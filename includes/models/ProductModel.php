<?php
declare(strict_types=1);

class ProductModel extends Model
{
    protected string $table = 'products';
    protected string $defaultOrder = 'is_top DESC, sort_order ASC, id DESC';
    protected bool $softDelete = true;

    /**
     * 获取产品列表
     */
    public function getList(int $categoryId = 0, int $limit = 10, int $offset = 0, array $filters = []): array
    {
        $where = ['p.status = 1'];
        $params = [];

        if (isMultiLangEnabled('products') && empty($filters['_skip_lang'])) {
            $where[] = 'p.lang = ?';
            $params[] = $filters['lang'] ?? siteLang();
        }

        if ($categoryId > 0) {
            $includeChildren = $filters['include_children'] ?? true;
            $catIds = $includeChildren ? $this->categoryTreeIds($categoryId) : [$categoryId];
            $placeholders = implode(',', array_fill(0, count($catIds), '?'));
            $where[] = "p.category_id IN ({$placeholders})";
            $params = array_merge($params, $catIds);
        }

        if (!empty($filters['keyword'])) {
            $where[] = '(p.title LIKE ? OR p.summary LIKE ? OR p.model LIKE ?)';
            $kw = '%' . $filters['keyword'] . '%';
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
        }
        if (!empty($filters['is_recommend'])) {
            $where[] = 'p.is_recommend = 1';
        }
        if (!empty($filters['is_hot'])) {
            $where[] = 'p.is_hot = 1';
        }
        if (!empty($filters['is_new'])) {
            $where[] = 'p.is_new = 1';
        }
        if (!empty($filters['is_top'])) {
            $where[] = 'p.is_top = 1';
        }

        $this->applyFacetFilters($filters, 'p.', $where, $params);

        $orderBy = $this->resolveSortOrder($filters['sort'] ?? '');
        $whereSQL = implode(' AND ', $where) . $this->softDeleteGuard('p.');
        $sql = "SELECT p.*, pc.name as category_name, pc.slug as category_slug
             FROM {$this->tableName()} p
             LEFT JOIN " . DB_PREFIX . "product_categories pc ON p.category_id = pc.id
             WHERE {$whereSQL}
             ORDER BY {$orderBy}";
        if ($limit > 0) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
        }
        return db()->fetchAll($sql, $params);
    }

    /**
     * 获取产品数量
     */
    public function getCount(int $categoryId = 0, array $filters = []): int
    {
        $where = ['status = 1'];
        $params = [];

        if (isMultiLangEnabled('products') && empty($filters['_skip_lang'])) {
            $where[] = 'lang = ?';
            $params[] = $filters['lang'] ?? siteLang();
        }

        if ($categoryId > 0) {
            $includeChildren = $filters['include_children'] ?? true;
            $catIds = $includeChildren ? $this->categoryTreeIds($categoryId) : [$categoryId];
            $placeholders = implode(',', array_fill(0, count($catIds), '?'));
            $where[] = "category_id IN ({$placeholders})";
            $params = array_merge($params, $catIds);
        }

        if (!empty($filters['keyword'])) {
            $where[] = '(title LIKE ? OR summary LIKE ? OR model LIKE ?)';
            $kw = '%' . $filters['keyword'] . '%';
            $params[] = $kw;
            $params[] = $kw;
            $params[] = $kw;
        }
        if (!empty($filters['is_recommend'])) {
            $where[] = 'is_recommend = 1';
        }
        if (!empty($filters['is_hot'])) {
            $where[] = 'is_hot = 1';
        }
        if (!empty($filters['is_new'])) {
            $where[] = 'is_new = 1';
        }
        if (!empty($filters['is_top'])) {
            $where[] = 'is_top = 1';
        }

        $this->applyFacetFilters($filters, '', $where, $params);

        $whereSQL = implode(' AND ', $where) . $this->softDeleteGuard();
        return (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM {$this->tableName()} WHERE {$whereSQL}",
            $params
        );
    }

    /**
     * 多条件筛选：品牌（可多选 OR）/ 价格区间 / 标签组（组间 AND、组内 OR）。
     * 追加进 $where/$params。$alias 为列前缀：getList 用 'p.'，getCount 无别名用 ''。
     * 对标 PbootCMS「多条件筛选」——但只走已索引的真实列 / product_tag_map，不碰 EAV。
     *
     * @param array<string,mixed> $filters
     * @param list<string>        $where
     * @param list<mixed>         $params
     */
    private function applyFacetFilters(array $filters, string $alias, array &$where, array &$params): void
    {
        // 品牌：可多选，OR
        if (!empty($filters['brand_ids'])) {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) $filters['brand_ids']))));
            if ($ids) {
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $where[] = "{$alias}brand_id IN ({$ph})";
                array_push($params, ...$ids);
            }
        }

        // 价格区间
        if (isset($filters['price_min']) && $filters['price_min'] !== '' && is_numeric($filters['price_min'])) {
            $where[] = "{$alias}price >= ?";
            $params[] = (float) $filters['price_min'];
        }
        if (isset($filters['price_max']) && $filters['price_max'] !== '' && is_numeric($filters['price_max'])) {
            $where[] = "{$alias}price <= ?";
            $params[] = (float) $filters['price_max'];
        }

        // 标签组：每组一个 EXISTS 子查询 → 组间 AND；组内 tag_id IN(...) → OR
        if (!empty($filters['tag_groups']) && is_array($filters['tag_groups'])) {
            $map = DB_PREFIX . 'product_tag_map';
            foreach ($filters['tag_groups'] as $tagIds) {
                $ids = array_values(array_unique(array_filter(array_map('intval', (array) $tagIds))));
                if (!$ids) {
                    continue;
                }
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $where[] = "EXISTS (SELECT 1 FROM {$map} m WHERE m.product_id = {$alias}id AND m.tag_id IN ({$ph}))";
                array_push($params, ...$ids);
            }
        }
    }

    /**
     * 筛选面板数据：当前分类下「有在售产品」的品牌 + 各自命中数。
     * @return list<array{id:int,name:string,cnt:int}>
     */
    public function facetBrands(int $categoryId = 0): array
    {
        [$catSql, $params] = $this->facetCategoryClause($categoryId);
        if ($this->facetLangEnabled()) {
            $catSql .= ' AND p.lang = ?';
            $params[] = siteLang();
        }
        return db()->fetchAll(
            "SELECT b.id, b.name, COUNT(p.id) AS cnt
               FROM " . DB_PREFIX . "brands b
               JOIN {$this->tableName()} p ON p.brand_id = b.id
              WHERE b.status = 1 AND p.status = 1 AND p.deleted_at IS NULL{$catSql}
              GROUP BY b.id, b.name
              ORDER BY b.sort_order ASC, b.id ASC",
            $params
        );
    }

    /**
     * 筛选面板数据：当前分类下产品用到的标签，按 group_name 分组。
     * @return array<string, list<array{id:int,name:string,cnt:int}>>
     */
    public function facetTagGroups(int $categoryId = 0): array
    {
        [$catSql, $params] = $this->facetCategoryClause($categoryId);
        if ($this->facetLangEnabled()) {
            $catSql .= ' AND p.lang = ?';
            $params[] = siteLang();
        }
        $rows = db()->fetchAll(
            "SELECT t.id, t.name, t.group_name, COUNT(DISTINCT p.id) AS cnt
               FROM " . DB_PREFIX . "product_tags t
               JOIN " . DB_PREFIX . "product_tag_map m ON m.tag_id = t.id
               JOIN {$this->tableName()} p ON p.id = m.product_id
              WHERE t.status = 1 AND t.group_name <> '' AND p.status = 1 AND p.deleted_at IS NULL{$catSql}
              GROUP BY t.id, t.name, t.group_name
              ORDER BY t.group_name ASC, t.sort_order ASC, t.id ASC",
            $params
        );
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['group_name']][] = ['id' => (int) $r['id'], 'name' => $r['name'], 'cnt' => (int) $r['cnt']];
        }
        return $groups;
    }

    /** 当前分类下在售产品的价格区间 [min,max]（无价格时返回 [0,0]）。 */
    public function facetPriceRange(int $categoryId = 0): array
    {
        [$catSql, $params] = $this->facetCategoryClause($categoryId);
        $row = db()->fetchOne(
            "SELECT MIN(p.price) AS lo, MAX(p.price) AS hi
               FROM {$this->tableName()} p
              WHERE p.status = 1 AND p.deleted_at IS NULL AND p.price > 0{$catSql}",
            $params
        );
        return ['min' => (float) ($row['lo'] ?? 0), 'max' => (float) ($row['hi'] ?? 0)];
    }

    /**
     * 分类树展开（含自身）。常规站走 product_categories 树；当该 id 在
     * product_categories 中不存在、却是 product 型栏目时，按栏目树展开——
     * 兼容「子栏目即分类」拓扑（如 ShopEx 迁移站，products.category_id 存栏目 id）。
     */
    private function categoryTreeIds(int $categoryId): array
    {
        $ids = productCategoryModel()->getChildIds($categoryId);
        if (count($ids) > 1) {
            return $ids;
        }
        try {
            if (!productCategoryModel()->find($categoryId) && function_exists('channelModel')) {
                $ch = channelModel()->find($categoryId);
                if ($ch && ($ch['type'] ?? '') === 'product') {
                    return channelModel()->getChildIds($categoryId);
                }
            }
        } catch (\Throwable $e) {
            // 容错：任何异常回落常规结果
        }
        return $ids;
    }

    /** 拼「限定当前分类（含子类）」的 SQL 片段 + 参数。 */
    private function facetCategoryClause(int $categoryId): array
    {
        if ($categoryId <= 0) {
            return ['', []];
        }
        $catIds = $this->categoryTreeIds($categoryId);
        if (!$catIds) {
            return ['', []];
        }
        $ph = implode(',', array_fill(0, count($catIds), '?'));
        return [" AND p.category_id IN ({$ph})", $catIds];
    }

    private function facetLangEnabled(): bool
    {
        return function_exists('isMultiLangEnabled') && isMultiLangEnabled('products');
    }

    /**
     * 后台产品列表（支持按分类/状态/关键字筛选，JOIN 分类名）
     */
    public function getAdminList(array $filters, int $limit, int $offset): array
    {
        // 回收站的行不出现在后台产品列表
        $where = ['p.deleted_at IS NULL'];
        $params = [];

        // admin 上下文：filters['lang'] 显式传入时无条件按 lang 过滤
        //（不依赖 isMultiLangEnabled / show_lang_switcher 这种前台开关）
        if (!empty($filters['lang'])) {
            $where[] = 'p.lang = ?';
            $params[] = $filters['lang'];
        }

        if (!empty($filters['category_id'])) {
            $where[] = 'p.category_id = ?';
            $params[] = $filters['category_id'];
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $where[] = 'p.status = ?';
            $params[] = (int) $filters['status'];
        }
        if (!empty($filters['keyword'])) {
            $where[] = '(p.title LIKE ? OR p.model LIKE ?)';
            $params[] = '%' . $filters['keyword'] . '%';
            $params[] = '%' . $filters['keyword'] . '%';
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM {$this->tableName()} p {$whereSQL}",
            $params
        );

        $items = db()->fetchAll(
            "SELECT p.*, c.name as category_name
             FROM {$this->tableName()} p
             LEFT JOIN " . DB_PREFIX . "product_categories c ON p.category_id = c.id
             {$whereSQL} ORDER BY p.is_top DESC, p.id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * 获取详情（JOIN 分类）
     */
    public function getDetail(int $id): ?array
    {
        return db()->fetchOne(
            "SELECT p.*, pc.name as category_name, pc.slug as category_slug
             FROM {$this->tableName()} p
             LEFT JOIN " . DB_PREFIX . "product_categories pc ON p.category_id = pc.id
             WHERE p.id = ?",
            [$id]
        );
    }

    /**
     * 获取已发布的产品（前台用，JOIN 分类信息）
     */
    public function getPublished(int $id): ?array
    {
        return db()->fetchOne(
            "SELECT p.*, pc.name as category_name, pc.slug as category_slug
             FROM {$this->tableName()} p
             LEFT JOIN " . DB_PREFIX . "product_categories pc ON p.category_id = pc.id
             WHERE p.id = ? AND p.status = 1 AND p.deleted_at IS NULL",
            [$id]
        );
    }

    /**
     * 按 slug 查找（JOIN 分类）
     */
    public function findBySlug(string $slug): ?array
    {
        return db()->fetchOne(
            "SELECT p.*, pc.name as category_name, pc.slug as category_slug
             FROM {$this->tableName()} p
             LEFT JOIN " . DB_PREFIX . "product_categories pc ON p.category_id = pc.id
             WHERE p.slug = ? AND p.status = 1 AND p.deleted_at IS NULL",
            [$slug]
        );
    }

    /**
     * 按 slug 查找 + 多语言感知
     * URL 上是源 slug；当 siteLang != default 时按 translation_group_id 跳到翻译行
     */
    public function findBySlugLang(string $slug): ?array
    {
        $lang = function_exists('siteLang') ? siteLang() : (string) config('site_lang', 'zh-CN');

        // 优先：slug + 当前语言（slug 本身可能就带 -en/-ja 后缀）
        $direct = db()->fetchOne(
            "SELECT p.*, pc.name as category_name, pc.slug as category_slug
             FROM {$this->tableName()} p
             LEFT JOIN " . DB_PREFIX . "product_categories pc ON p.category_id = pc.id
             WHERE p.slug = ? AND p.lang = ? AND p.status = 1 AND p.deleted_at IS NULL
             LIMIT 1",
            [$slug, $lang]
        );
        if ($direct) return $direct;

        $src = db()->fetchOne(
            "SELECT * FROM {$this->tableName()} WHERE slug = ? AND status = 1 AND deleted_at IS NULL",
            [$slug]
        );
        if (!$src) return null;
        $groupId = (int) ($src['translation_group_id'] ?: $src['id']);

        $translated = db()->fetchOne(
            "SELECT p.*, pc.name as category_name, pc.slug as category_slug
             FROM {$this->tableName()} p
             LEFT JOIN " . DB_PREFIX . "product_categories pc ON p.category_id = pc.id
             WHERE p.translation_group_id = ? AND p.lang = ? AND p.status = 1 AND p.deleted_at IS NULL
             LIMIT 1",
            [$groupId, $lang]
        );
        return $translated ?: $this->findBySlug($slug);
    }

    /**
     * 上一个产品
     */
    public function getPrev(int $categoryId, int $currentId): ?array
    {
        return db()->fetchOne(
            "SELECT p.id, p.title, p.slug, p.cover, pc.slug as category_slug
             FROM {$this->tableName()} p
             LEFT JOIN " . DB_PREFIX . "product_categories pc ON p.category_id = pc.id
             WHERE p.category_id = ? AND p.status = 1 AND p.deleted_at IS NULL AND p.id < ? ORDER BY p.id DESC LIMIT 1",
            [$categoryId, $currentId]
        );
    }

    /**
     * 下一个产品
     */
    public function getNext(int $categoryId, int $currentId): ?array
    {
        return db()->fetchOne(
            "SELECT p.id, p.title, p.slug, p.cover, pc.slug as category_slug
             FROM {$this->tableName()} p
             LEFT JOIN " . DB_PREFIX . "product_categories pc ON p.category_id = pc.id
             WHERE p.category_id = ? AND p.status = 1 AND p.deleted_at IS NULL AND p.id > ? ORDER BY p.id ASC LIMIT 1",
            [$categoryId, $currentId]
        );
    }

    /**
     * 相关产品
     */
    public function getRelated(int $categoryId, int $excludeId, int $limit = 4): array
    {
        // 按当前 siteLang 过滤，避免详情页底部"相关产品"串语言
        $lang = function_exists('siteLang') ? siteLang() : (string) config('site_lang', 'zh-CN');
        return db()->fetchAll(
            "SELECT p.*, pc.slug as category_slug
             FROM {$this->tableName()} p
             LEFT JOIN " . DB_PREFIX . "product_categories pc ON p.category_id = pc.id
             WHERE p.category_id = ? AND p.status = 1 AND p.deleted_at IS NULL AND p.id != ? AND p.lang = ?
             ORDER BY p.is_recommend DESC, p.sort_order ASC, p.id DESC LIMIT ?",
            [$categoryId, $excludeId, $lang, $limit]
        );
    }

    /**
     * 增加浏览量
     */
    public function incrementViews(int $id): int
    {
        return $this->increment($id, 'views');
    }

    /**
     * 获取所有已用标签及使用次数
     * 返回 [['tag' => '标签名', 'count' => 5, 'latest' => 1739000000], ...]
     */
    public function getAllTags(): array
    {
        $rows = db()->fetchAll(
            "SELECT tags, MAX(updated_at) as latest FROM {$this->tableName()} WHERE tags != '' AND tags IS NOT NULL GROUP BY tags ORDER BY latest DESC"
        );

        $tagMap = [];
        foreach ($rows as $row) {
            $parts = array_map('trim', explode(',', $row['tags']));
            foreach ($parts as $tag) {
                if ($tag === '') continue;
                if (!isset($tagMap[$tag])) {
                    $tagMap[$tag] = ['tag' => $tag, 'count' => 0, 'latest' => 0];
                }
                $tagMap[$tag]['count']++;
                $tagMap[$tag]['latest'] = max($tagMap[$tag]['latest'], (int)$row['latest']);
            }
        }

        return array_values($tagMap);
    }

    public const SORT_MAP = [
        'default'    => 'p.is_top DESC, p.sort_order ASC, p.id DESC',
        'newest'     => 'p.created_at DESC, p.id DESC',
        'updated'    => 'p.updated_at DESC, p.id DESC',
        'views'      => 'p.views DESC, p.id DESC',
        'price_asc'  => 'p.price ASC, p.id DESC',
        'price_desc' => 'p.price DESC, p.id DESC',
    ];

    public const SORT_LABELS = [
        'default'    => 'sort_default',
        'newest'     => 'sort_newest',
        'updated'    => 'sort_updated',
        'views'      => 'sort_views',
        'price_asc'  => 'sort_price_asc',
        'price_desc' => 'sort_price_desc',
    ];

    public function resolveSortOrder(string $sort): string
    {
        return self::SORT_MAP[$sort] ?? $this->defaultOrder;
    }
}
