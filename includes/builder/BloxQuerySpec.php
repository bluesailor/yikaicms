<?php
/**
 * 查询循环规格（2.0.3，对标 Bricks Query Loop）：`_query` 查询体的唯一归一入口。
 *
 * 容器 Loop 的内联查询、全局查询保存端都走这里；值决定 SQL 形态与分页参数，
 * 非法值一律丢弃（宁缺毋滥），默认值不落盘（文档保持干净、旧文档归一结果不变）。
 *
 * 来源：
 * - type:<slug>      内容类型（article/case/自定义模型 → contents）、product → products、
 *                    download → downloads、job → jobs；
 * - channel:<id>     指定栏目（按栏目 type 定型，下载/招聘栏目走各自的表）；
 * - current          当前列表页（栏目/搜索词/主分页参数）；
 * - terms:<taxonomy> 分类循环：content（栏目）/ product（产品分类）/ download（下载分类）。
 *
 * 2.0.3 新增键：cats/cats_exclude/children、ids/exclude_ids/exclude_current、scope
 * （related 相关内容 / parent 嵌套循环读外层行）、date_within/date_from/date_to、
 * filter_relation、price_min/price_max、new、order 扩展、terms 专用 parent_term/hide_empty、
 * pagination 扩展（ajax/load_more/infinite）与 load_more_text。
 */

declare(strict_types=1);

final class BloxQuerySpec
{
    // 2.0.4 高级字段：field:键（当前条目的重复器行）、option:键（全站选项的重复器行）、rel:键（关联字段指向的条目）
    public const SOURCE_PATTERN = '/^(?:type:[a-z][a-z0-9_-]{0,31}|channel:[1-9][0-9]{0,9}|current|terms:(?:content|product|download)|(?:field|option|rel):[a-z][a-z0-9_]{0,63})$/D';
    public const MAX_LIMIT = 50;
    public const MAX_OFFSET = 5000;
    public const MAX_TERMS = 20;
    public const MAX_IDS = 50;
    public const PAGINATION_MODES = ['none', 'numbers', 'ajax', 'load_more', 'infinite'];
    /** 需要前台脚本局部刷新的分页模式（输出 data-yk-query 标记与片段包裹）。 */
    public const LIVE_PAGINATION = ['ajax', 'load_more', 'infinite'];
    /** 条目来源的排序白名单（各表不支持的字段由执行端回退到默认顺序）。 */
    public const ITEM_ORDERS = ['default', 'recommend_first', 'newest', 'oldest', 'updated', 'title', 'title_desc',
        'views', 'random', 'manual', 'sort', 'price_asc', 'price_desc'];
    public const TERM_ORDERS = ['default', 'name', 'name_desc', 'newest', 'count'];
    private const CAT_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/iD';
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/D';

    /** 内联查询体归一：非法返回 null。 */
    public static function normalize(array $raw): ?array
    {
        $source = $raw['source'] ?? null;
        if (!is_string($source) || !preg_match(self::SOURCE_PATTERN, $source)) {
            return null;
        }
        $isTerms = str_starts_with($source, 'terms:');
        $query = ['source' => $source];

        // 分类：旧单值 cat 原样保留（旧文档归一结果不变），新 UI 写 cats 多选
        $cat = trim((string) ($raw['cat'] ?? ''));
        if ($cat !== '' && preg_match(self::CAT_PATTERN, $cat)) {
            $query['cat'] = $cat;
        }
        foreach (['cats', 'cats_exclude'] as $key) {
            $list = self::catList($raw[$key] ?? null);
            if ($list !== []) {
                $query[$key] = $list;
            }
        }
        if (array_key_exists('children', $raw) && is_bool($raw['children'])) {
            $query['children'] = $raw['children'];
        }

        $query['limit'] = max(1, min(self::MAX_LIMIT, (int) ($raw['limit'] ?? 6)));
        $offset = max(0, min(self::MAX_OFFSET, (int) ($raw['offset'] ?? 0)));
        if ($offset > 0) {
            $query['offset'] = $offset;
        }

        if ($isTerms) {
            $parent = $raw['parent_term'] ?? '';
            if ($parent !== '' && $parent !== null && is_numeric($parent) && (int) $parent >= 0) {
                $query['parent_term'] = (int) $parent;
            }
            if (!empty($raw['hide_empty'])) {
                $query['hide_empty'] = true;
            }
        } else {
            $keyword = trim((string) ($raw['keyword'] ?? ''));
            if ($keyword !== '') {
                $query['keyword'] = mb_substr($keyword, 0, 200);
            }
            foreach (['recommend', 'hot', 'top', 'new', 'exclude_current'] as $flag) {
                if (!empty($raw[$flag])) {
                    $query[$flag] = true;
                }
            }
            foreach (['ids', 'exclude_ids'] as $key) {
                $ids = self::idList($raw[$key] ?? null);
                if ($ids !== []) {
                    $query[$key] = $ids;
                }
            }
            $within = (int) ($raw['date_within'] ?? 0);
            if ($within > 0) {
                $query['date_within'] = min(3650, $within);
            }
            foreach (['date_from', 'date_to'] as $key) {
                $date = trim((string) ($raw[$key] ?? ''));
                if ($date !== '' && preg_match(self::DATE_PATTERN, $date) && strtotime($date) !== false) {
                    $query[$key] = $date;
                }
            }
            foreach (['price_min', 'price_max'] as $key) {
                $price = $raw[$key] ?? '';
                if ($price !== '' && $price !== null && is_numeric($price) && (float) $price >= 0) {
                    $query[$key] = (float) $price == (int) $price ? (int) $price : round((float) $price, 2);
                }
            }
        }

        $scope = (string) ($raw['scope'] ?? '');
        if ($scope === 'parent' || ($scope === 'related' && !$isTerms)) {
            $query['scope'] = $scope;
        }

        $order = (string) ($raw['order'] ?? 'default');
        $orders = $isTerms ? self::TERM_ORDERS : self::ITEM_ORDERS;
        if ($order !== 'default' && in_array($order, $orders, true)
            && ($order !== 'manual' || isset($query['ids']))) {
            $query['order'] = $order;
        }

        $pagination = (string) ($raw['pagination'] ?? 'none');
        // 随机顺序每次请求都不同，翻页必然重复/遗漏；嵌套循环没有稳定的独立分页上下文
        $pageable = ($query['order'] ?? '') !== 'random' && ($query['scope'] ?? '') !== 'parent';
        if ($pagination !== 'none' && in_array($pagination, self::PAGINATION_MODES, true) && $pageable) {
            $query['pagination'] = $pagination;
        }
        $moreText = trim((string) ($raw['load_more_text'] ?? ''));
        if ($moreText !== '' && ($query['pagination'] ?? '') === 'load_more') {
            $query['load_more_text'] = mb_substr($moreText, 0, 40);
        }

        if (($raw['empty_mode'] ?? 'message') === 'hidden') {
            $query['empty_mode'] = 'hidden';
        }
        $empty = trim((string) ($raw['empty'] ?? ''));
        if ($empty !== '') {
            $query['empty'] = mb_substr($empty, 0, 200);
        }

        if (!$isTerms) {
            $filters = self::normalizeFilters($raw['filters'] ?? null);
            if ($filters !== []) {
                $query['filters'] = $filters;
                if (($raw['filter_relation'] ?? 'and') === 'or' && count($filters) > 1) {
                    $query['filter_relation'] = 'or';
                }
            }
        }
        return $query;
    }

    /** 分类 id/slug 列表（去重、≤20）。 @return list<string> */
    private static function catList(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = explode(',', $raw);
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            $value = trim((string) (is_scalar($item) ? $item : ''));
            if ($value !== '' && preg_match(self::CAT_PATTERN, $value) && !in_array($value, $out, true)) {
                $out[] = $value;
                if (count($out) >= self::MAX_TERMS) {
                    break;
                }
            }
        }
        return $out;
    }

    /** 正整数 id 列表（保序去重、≤50）。 @return list<int> */
    public static function idList(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[\s,，]+/u', $raw) ?: [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_scalar($item) || !ctype_digit(trim((string) $item))) {
                continue;
            }
            $id = (int) trim((string) $item);
            if ($id > 0 && !in_array($id, $out, true)) {
                $out[] = $id;
                if (count($out) >= self::MAX_IDS) {
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * 自定义字段过滤归一（v1.25 §7.2.1）：≤5 条 {field,op,value}，算子白名单与 SQL
     * 编译端同源（MetaModel::FILTER_OPS）；数值算子强校验、between 要求「a,b」双数值；
     * 不合法条目静默丢弃。条件间关系由 filter_relation（and/or）决定。
     *
     * @return list<array{field:string,op:string,value:string}>
     */
    public static function normalizeFilters(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $field = strtolower(trim((string) ($item['field'] ?? '')));
            $op = strtolower(trim((string) ($item['op'] ?? '=')));
            $value = mb_substr(trim((string) ($item['value'] ?? '')), 0, 200);
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $field) !== 1
                || !in_array($op, MetaModel::FILTER_OPS, true)) {
                continue;
            }
            if ($op === 'empty') {
                $value = '';
            } elseif ($op === 'between') {
                $parts = array_map('trim', explode(',', $value));
                if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
                    continue;
                }
                $value = $parts[0] . ',' . $parts[1];
            } elseif (in_array($op, ['>', '>=', '<', '<='], true)) {
                if (!is_numeric($value)) {
                    continue;
                }
            } elseif ($value === '') {
                continue;
            }
            $out[] = ['field' => $field, 'op' => $op, 'value' => $value];
            if (count($out) >= MetaModel::FILTER_MAX_ITEMS) {
                break;
            }
        }
        return $out;
    }

    /** 是否需要前台脚本局部刷新（片段包裹 + data-yk-query 标记）。 */
    public static function isLive(array $query): bool
    {
        return in_array((string) ($query['pagination'] ?? 'none'), self::LIVE_PAGINATION, true);
    }

    /** 是否分页（任何模式）。 */
    public static function isPaged(array $query): bool
    {
        return ($query['pagination'] ?? 'none') !== 'none';
    }
}
