<?php
/**
 * 查询循环执行器（2.0.3）：把 BloxLoopQuery::plan() 解析好的执行计划编译成参数化 SQL。
 *
 * 为什么不再走 TagEngine {yk:list}：多分类包含/排除、ID 包含/排除、日期范围、
 * OR 条件、排序方向、下载/招聘/分类来源都超出 getContents/getProducts 的契约，
 * 硬塞进全站共用的模型方法会牵动所有列表页。本类只服务容器 Loop，{yk:list} 模板
 * 标签契约保持不变；行形状与模型取数一致（c.* + 栏目名/别名/类型、p.* + 分类名/别名），
 * 既有 {{loop.*}}、contentUrl/productUrl 无需改动。
 *
 * 安全：列名、表名、排序片段全部来自本类常量；所有值走参数位；
 * 计划本身已由 BloxQuerySpec 白名单归一。
 */

declare(strict_types=1);

final class BloxQueryRunner
{
    /** 各条目来源的表、别名、选列、关联与列能力。 */
    private const ITEM_SOURCES = [
        'content' => [
            'table' => 'contents', 'alias' => 'c',
            'select' => 'c.*, ch.name AS channel_name, ch.slug AS channel_slug, ch.type AS channel_type',
            'join' => 'LEFT JOIN {p}channels ch ON c.channel_id = ch.id',
            'cat' => 'channel_id', 'date' => 'publish_time', 'keyword' => ['title', 'summary'],
            'flags' => ['recommend' => 'is_recommend', 'hot' => 'is_hot', 'top' => 'is_top'],
            'soft_delete' => true, 'price' => false, 'views' => 'views',
        ],
        'product' => [
            'table' => 'products', 'alias' => 'p',
            'select' => 'p.*, pc.name AS category_name, pc.slug AS category_slug',
            'join' => 'LEFT JOIN {p}product_categories pc ON p.category_id = pc.id',
            'cat' => 'category_id', 'date' => 'created_at', 'keyword' => ['title', 'summary', 'model'],
            'flags' => ['recommend' => 'is_recommend', 'hot' => 'is_hot', 'top' => 'is_top', 'new' => 'is_new'],
            'soft_delete' => true, 'price' => true, 'views' => 'views',
        ],
        'download' => [
            'table' => 'downloads', 'alias' => 'd',
            'select' => 'd.*, dc.name AS category_name, dc.slug AS category_slug',
            'join' => 'LEFT JOIN {p}download_categories dc ON d.category_id = dc.id',
            'cat' => 'category_id', 'date' => 'created_at', 'keyword' => ['title', 'description'],
            'flags' => [],
            'soft_delete' => true, 'price' => false, 'views' => 'download_count',
        ],
        'job' => [
            'table' => 'jobs', 'alias' => 'j',
            'select' => 'j.*',
            'join' => '',
            'cat' => '', 'date' => 'publish_time', 'keyword' => ['title', 'summary', 'location'],
            'flags' => ['top' => 'is_top'],
            'soft_delete' => true, 'price' => false, 'views' => 'views',
        ],
    ];

    /** 分类来源：表 + 条目计数子查询的条目表/外键。 */
    private const TERM_SOURCES = [
        'content' => ['table' => 'channels', 'items' => 'contents', 'fk' => 'channel_id', 'hierarchy' => true, 'lang' => true, 'default' => 't.sort_order ASC, t.id ASC'],
        'product' => ['table' => 'product_categories', 'items' => 'products', 'fk' => 'category_id', 'hierarchy' => true, 'lang' => true, 'default' => 't.sort_order ASC, t.id ASC'],
        'download' => ['table' => 'download_categories', 'items' => 'downloads', 'fk' => 'category_id', 'hierarchy' => false, 'lang' => false, 'default' => 't.sort_order DESC, t.id ASC'],
    ];

    /**
     * 执行计划 → 当前页行集与总数。
     *
     * @param array<string,mixed> $plan
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function fetch(array $plan, int $page): array
    {
        if (($plan['cat_ids'] ?? null) === [] || ($plan['ids'] ?? null) === []) {
            return ['rows' => [], 'total' => 0]; // 指定了分类/ID 但一个都解析不到：空，不退化为全量
        }
        $kind = (string) ($plan['kind'] ?? '');
        if ($kind === 'term') {
            return self::fetchTerms($plan, $page);
        }
        if (!isset(self::ITEM_SOURCES[$kind])) {
            return ['rows' => [], 'total' => 0];
        }
        $source = self::ITEM_SOURCES[$kind];
        $a = $source['alias'];
        [$where, $params] = self::itemWhere($plan, $source);
        $from = DB_PREFIX . $source['table'] . ' ' . $a
            . ($source['join'] !== '' ? ' ' . str_replace('{p}', DB_PREFIX, $source['join']) : '');
        $whereSql = implode(' AND ', $where);
        $limit = max(1, (int) ($plan['limit'] ?? 6));
        $baseOffset = max(0, (int) ($plan['offset'] ?? 0));

        if (($plan['order'] ?? '') === 'manual' && is_array($plan['ids'] ?? null)) {
            // 手动顺序：按给定 ID 顺序排（≤50 条，PHP 侧排序，方言无关）
            $all = db()->fetchAll("SELECT {$source['select']} FROM {$from} WHERE {$whereSql}", $params);
            $position = array_flip(array_map('intval', $plan['ids']));
            usort($all, static fn (array $x, array $y): int
                => ($position[(int) ($x['id'] ?? 0)] ?? PHP_INT_MAX) <=> ($position[(int) ($y['id'] ?? 0)] ?? PHP_INT_MAX));
            $all = array_slice($all, $baseOffset);
            return ['rows' => array_slice($all, ($page - 1) * $limit, $limit), 'total' => count($all)];
        }

        $total = max(0, (int) db()->fetchColumn("SELECT COUNT(*) FROM {$from} WHERE {$whereSql}", $params) - $baseOffset);
        $order = self::itemOrder($kind, (string) ($plan['order'] ?? 'default'));
        $rows = db()->fetchAll(
            "SELECT {$source['select']} FROM {$from} WHERE {$whereSql} ORDER BY {$order} LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $baseOffset + ($page - 1) * $limit])
        );
        return ['rows' => $rows, 'total' => $total];
    }

    /** 分页总页数。 */
    public static function pages(int $total, int $limit): int
    {
        return max(1, (int) ceil($total / max(1, $limit)));
    }

    /**
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $source
     * @return array{0:list<string>,1:list<mixed>}
     */
    private static function itemWhere(array $plan, array $source): array
    {
        $a = $source['alias'] . '.';
        $where = [$a . 'status = 1'];
        $params = [];
        $table = (string) $source['table'];
        if (function_exists('isMultiLangEnabled') && isMultiLangEnabled($table)) {
            $where[] = $a . 'lang = ?';
            $params[] = function_exists('siteLang') ? siteLang() : 'zh-CN';
        }
        if (($plan['kind'] ?? '') === 'content' && ($plan['content_type'] ?? '') !== '') {
            $where[] = $a . 'type = ?';
            $params[] = (string) $plan['content_type'];
        }
        if ($source['cat'] !== '') {
            self::inClause($where, $params, $a . $source['cat'], $plan['cat_ids'] ?? null, false);
            self::inClause($where, $params, $a . $source['cat'], $plan['cat_exclude_ids'] ?? null, true);
        }
        self::inClause($where, $params, $a . 'id', $plan['ids'] ?? null, false);
        self::inClause($where, $params, $a . 'id', $plan['exclude_ids'] ?? null, true);

        // 查询自带关键词与前台搜索（筛选叠加）各成一组，同时满足
        foreach ([(string) ($plan['keyword'] ?? ''), (string) ($plan['search'] ?? '')] as $keyword) {
            if ($keyword === '') {
                continue;
            }
            $likes = [];
            foreach ($source['keyword'] as $column) {
                $likes[] = $a . $column . " LIKE ? ESCAPE '!'";
                $params[] = '%' . strtr($keyword, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            }
            $where[] = '(' . implode(' OR ', $likes) . ')';
        }
        foreach ((array) ($plan['flags'] ?? []) as $flag) {
            if (isset($source['flags'][$flag])) {
                $where[] = $a . $source['flags'][$flag] . ' = 1';
            }
        }
        if (isset($plan['date_from']) && is_int($plan['date_from'])) {
            $where[] = $a . $source['date'] . ' >= ?';
            $params[] = $plan['date_from'];
        }
        if (isset($plan['date_to']) && is_int($plan['date_to'])) {
            $where[] = $a . $source['date'] . ' <= ?';
            $params[] = $plan['date_to'];
        }
        if ($source['price']) {
            foreach (['price_min' => '>=', 'price_max' => '<='] as $key => $op) {
                if (isset($plan[$key]) && is_numeric($plan[$key])) {
                    $where[] = $a . 'price ' . $op . ' ?';
                    $params[] = (float) $plan[$key];
                }
            }
        }
        // 自定义字段：查询自带条件（AND/OR）与前台筛选（字段间 AND）各成一组
        foreach (['meta', 'meta_filter'] as $metaKey) {
            $meta = $plan[$metaKey] ?? null;
            if (!is_array($meta) || !class_exists('MetaModel')) {
                continue;
            }
            $metaWhere = [];
            $metaParams = [];
            MetaModel::applyMetaFilters($meta, $a, $metaWhere, $metaParams);
            if ($metaWhere !== []) {
                $where[] = '(' . implode(($meta['relation'] ?? 'and') === 'or' ? ' OR ' : ' AND ', $metaWhere) . ')';
                $params = array_merge($params, $metaParams);
            }
        }
        if ($source['soft_delete']) {
            $where[] = $a . 'deleted_at IS NULL';
        }
        return [$where, $params];
    }

    /**
     * @param list<string> $where @param list<mixed> $params
     */
    private static function inClause(array &$where, array &$params, string $column, mixed $ids, bool $negate): void
    {
        if (!is_array($ids) || $ids === []) {
            return;
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $where[] = $column . ($negate ? ' NOT IN (' : ' IN (') . implode(',', array_fill(0, count($ids), '?')) . ')';
        array_push($params, ...$ids);
    }

    private static function randomOrder(): string
    {
        return db()->isSqlite() ? 'RANDOM()' : 'RAND()';
    }

    /** 条目排序片段（只来自本方法的字面量）。 */
    private static function itemOrder(string $kind, string $order): string
    {
        if ($order === 'random') {
            return self::randomOrder();
        }
        $source = self::ITEM_SOURCES[$kind];
        $a = $source['alias'] . '.';
        $common = [
            'newest' => "{$a}{$source['date']} DESC, {$a}id DESC",
            'oldest' => "{$a}{$source['date']} ASC, {$a}id ASC",
            'updated' => "{$a}updated_at DESC, {$a}id DESC",
            'title' => "{$a}title ASC, {$a}id ASC",
            'title_desc' => "{$a}title DESC, {$a}id DESC",
            'views' => "{$a}{$source['views']} DESC, {$a}id DESC",
        ];
        switch ($kind) {
            case 'product':
                $map = ProductModel::SORT_MAP + $common + ['sort' => 'p.sort_order ASC, p.id DESC'];
                return $map[$order] ?? ProductModel::SORT_MAP['default'];
            case 'content':
                // 与 ContentModel::getEffectiveOrder 同口径：有 sort_order 列时权重优先
                $default = 'c.is_top DESC, c.publish_time DESC, c.id DESC';
                if (function_exists('contentModel') && str_starts_with(contentModel()->getEffectiveOrder(), 'sort_order')) {
                    $default = 'c.sort_order DESC, ' . $default;
                }
                $map = $common + [
                    'recommend_first' => 'c.is_recommend DESC, ' . $default,
                    'sort' => 'c.sort_order DESC, c.id DESC',
                    'price_asc' => 'c.price ASC, c.id DESC',
                    'price_desc' => 'c.price DESC, c.id DESC',
                ];
                return $map[$order] ?? $default;
            case 'download':
                return ($common + ['sort' => 'd.sort_order DESC, d.id DESC'])[$order] ?? 'd.sort_order DESC, d.id DESC';
            default: // job
                return ($common + ['sort' => 'j.sort_order DESC, j.id DESC'])[$order] ?? 'j.is_top DESC, j.sort_order DESC, j.id DESC';
        }
    }

    /**
     * 分类循环：栏目 / 产品分类 / 下载分类。每行附带 item_count（已发布条目数）。
     *
     * @param array<string,mixed> $plan
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    private static function fetchTerms(array $plan, int $page): array
    {
        $taxonomy = (string) ($plan['taxonomy'] ?? '');
        if (!isset(self::TERM_SOURCES[$taxonomy])) {
            return ['rows' => [], 'total' => 0];
        }
        $source = self::TERM_SOURCES[$taxonomy];
        $itemTable = DB_PREFIX . $source['items'];
        $countSql = "(SELECT COUNT(*) FROM {$itemTable} x WHERE x.{$source['fk']} = t.id AND x.status = 1 AND x.deleted_at IS NULL)";
        $where = ['t.status = 1'];
        $params = [];
        if ($source['lang'] && function_exists('isMultiLangEnabled') && isMultiLangEnabled($source['table'])) {
            $where[] = 't.lang = ?';
            $params[] = function_exists('siteLang') ? siteLang() : 'zh-CN';
        }
        if ($source['hierarchy'] && isset($plan['parent_term']) && is_int($plan['parent_term'])) {
            $where[] = 't.parent_id = ?';
            $params[] = $plan['parent_term'];
        }
        self::inClause($where, $params, 't.id', $plan['cat_ids'] ?? null, false);
        self::inClause($where, $params, 't.id', $plan['cat_exclude_ids'] ?? null, true);
        if (!empty($plan['hide_empty'])) {
            $where[] = $countSql . ' > 0';
        }
        $whereSql = implode(' AND ', $where);
        $table = DB_PREFIX . $source['table'] . ' t';
        $limit = max(1, (int) ($plan['limit'] ?? 6));
        $baseOffset = max(0, (int) ($plan['offset'] ?? 0));
        $total = max(0, (int) db()->fetchColumn("SELECT COUNT(*) FROM {$table} WHERE {$whereSql}", $params) - $baseOffset);
        $order = [
            'name' => 't.name ASC, t.id ASC',
            'name_desc' => 't.name DESC, t.id DESC',
            'newest' => 't.created_at DESC, t.id DESC',
            'count' => 'item_count DESC, ' . $source['default'],
        ][(string) ($plan['order'] ?? '')] ?? $source['default'];
        $rows = db()->fetchAll(
            "SELECT t.*, {$countSql} AS item_count FROM {$table} WHERE {$whereSql} ORDER BY {$order} LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $baseOffset + ($page - 1) * $limit])
        );
        return ['rows' => $rows, 'total' => $total];
    }
}
