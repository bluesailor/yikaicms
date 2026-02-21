<?php
declare(strict_types=1);

class ProductModel extends Model
{
    protected string $table = 'products';
    protected string $defaultOrder = 'is_top DESC, sort_order ASC, id DESC';

    /**
     * 获取产品列表
     */
    public function getList(int $categoryId = 0, int $limit = 10, int $offset = 0, array $filters = []): array
    {
        $where = ['p.status = 1'];
        $params = [];

        if ($categoryId > 0) {
            $includeChildren = $filters['include_children'] ?? true;
            $catIds = $includeChildren ? productCategoryModel()->getChildIds($categoryId) : [$categoryId];
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

        $whereSQL = implode(' AND ', $where);
        $sql = "SELECT p.*, pc.name as category_name, pc.slug as category_slug
             FROM {$this->tableName()} p
             LEFT JOIN " . DB_PREFIX . "product_categories pc ON p.category_id = pc.id
             WHERE {$whereSQL}
             ORDER BY {$this->defaultOrder}";
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

        if ($categoryId > 0) {
            $includeChildren = $filters['include_children'] ?? true;
            $catIds = $includeChildren ? productCategoryModel()->getChildIds($categoryId) : [$categoryId];
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

        $whereSQL = implode(' AND ', $where);
        return (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM {$this->tableName()} WHERE {$whereSQL}",
            $params
        );
    }

    /**
     * 后台产品列表（支持按分类/状态/关键字筛选，JOIN 分类名）
     */
    public function getAdminList(array $filters, int $limit, int $offset): array
    {
        $where = [];
        $params = [];

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
             WHERE p.id = ? AND p.status = 1",
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
             WHERE p.slug = ? AND p.status = 1",
            [$slug]
        );
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
             WHERE p.category_id = ? AND p.status = 1 AND p.id < ? ORDER BY p.id DESC LIMIT 1",
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
             WHERE p.category_id = ? AND p.status = 1 AND p.id > ? ORDER BY p.id ASC LIMIT 1",
            [$categoryId, $currentId]
        );
    }

    /**
     * 相关产品
     */
    public function getRelated(int $categoryId, int $excludeId, int $limit = 4): array
    {
        return db()->fetchAll(
            "SELECT p.*, pc.slug as category_slug
             FROM {$this->tableName()} p
             LEFT JOIN " . DB_PREFIX . "product_categories pc ON p.category_id = pc.id
             WHERE p.category_id = ? AND p.status = 1 AND p.id != ?
             ORDER BY p.is_recommend DESC, p.sort_order ASC, p.id DESC LIMIT ?",
            [$categoryId, $excludeId, $limit]
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
}
