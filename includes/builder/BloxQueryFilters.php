<?php
/**
 * 前台查询筛选（2.0.3，对标 Bricks Query Filters）：筛选元素把访客选择写进 URL 参数，
 * 目标循环渲染时读取这些参数叠加到执行计划上。
 *
 * 参数按目标循环命名空间隔离：`yf<6 位哈希>_<键>`（哈希取自循环宿主节点 id），
 * 同页多个循环各自筛选互不串扰；链接可分享、可收藏，禁用脚本时表单 GET 提交同样生效。
 *
 * 叠加规则（只能收窄，不能越出作者设定的查询范围）：
 * - s        关键词（与查询自带关键词同时满足）；
 * - cat      分类 id 列表（含子类口径随查询；与查询自带分类取交集）；
 * - min/max  价格区间（仅产品）；
 * - days     最近 N 天；
 * - sort     排序（白名单，手动顺序除外）；
 * - m_<字段> 自定义字段取值（逗号分隔，同字段内 OR，字段间 AND）。
 */

declare(strict_types=1);

final class BloxQueryFilters
{
    public const SORTS = ['default', 'newest', 'oldest', 'updated', 'title', 'title_desc', 'views', 'price_asc', 'price_desc', 'recommend_first', 'name', 'name_desc', 'count'];
    private const MAX_META_FIELDS = 5;
    private const MAX_META_VALUES = 10;

    /** 目标循环的参数前缀。 */
    public static function prefix(string $hostId): string
    {
        return 'yf' . substr(hash('sha256', 'filter:' . $hostId), 0, 6);
    }

    /**
     * 读取当前请求里针对该循环的筛选参数（已白名单化）。
     *
     * @return array<string,mixed>
     */
    public static function requestOverrides(string $hostId, ?array $get = null): array
    {
        $get ??= $_GET;
        $prefix = self::prefix($hostId) . '_';
        $out = [];
        $search = $get[$prefix . 's'] ?? null;
        if (is_string($search) && trim($search) !== '') {
            $out['search'] = mb_substr(trim($search), 0, 100);
        }
        $cats = $get[$prefix . 'cat'] ?? null;
        if (is_array($cats)) {
            $cats = implode(',', array_filter($cats, 'is_scalar'));
        }
        if (is_string($cats) && $cats !== '') {
            $ids = BloxQuerySpec::idList($cats);
            if ($ids !== []) {
                $out['cats'] = array_slice($ids, 0, BloxQuerySpec::MAX_TERMS);
            }
        }
        foreach (['min' => 'price_min', 'max' => 'price_max'] as $key => $target) {
            $value = $get[$prefix . $key] ?? null;
            if (is_string($value) && $value !== '' && is_numeric($value) && (float) $value >= 0) {
                $out[$target] = (float) $value;
            }
        }
        $days = $get[$prefix . 'days'] ?? null;
        if (is_string($days) && ctype_digit($days) && (int) $days > 0) {
            $out['days'] = min(3650, (int) $days);
        }
        $sort = $get[$prefix . 'sort'] ?? null;
        if (is_string($sort) && in_array($sort, self::SORTS, true)) {
            $out['sort'] = $sort;
        }
        $meta = [];
        foreach ($get as $key => $value) {
            if (count($meta) >= self::MAX_META_FIELDS) {
                break;
            }
            if (!is_string($key) || !str_starts_with($key, $prefix . 'm_')) {
                continue;
            }
            $field = substr($key, strlen($prefix) + 2);
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $field) !== 1) {
                continue;
            }
            if (is_array($value)) {
                $value = implode(',', array_filter($value, 'is_scalar'));
            }
            if (!is_string($value)) {
                continue;
            }
            $values = array_slice(array_values(array_unique(array_filter(
                array_map(static fn (string $v): string => mb_substr(trim($v), 0, 100), explode(',', $value)),
                static fn (string $v): bool => $v !== ''
            ))), 0, self::MAX_META_VALUES);
            if ($values !== []) {
                $meta[$field] = $values;
            }
        }
        if ($meta !== []) {
            $out['meta'] = $meta;
        }
        return $out;
    }

    /**
     * 把筛选叠加到执行计划上（只收窄）。
     *
     * @param array<string,mixed> $plan @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    public static function applyOverrides(array $plan, array $overrides): array
    {
        if ($overrides === []) {
            return $plan;
        }
        $kind = (string) ($plan['kind'] ?? '');
        if (isset($overrides['search']) && $kind !== 'term') {
            $plan['search'] = (string) $overrides['search'];
        }
        if (isset($overrides['cats']) && is_array($overrides['cats'])) {
            $selected = array_map('intval', $overrides['cats']);
            if ($kind !== 'term') {
                $selected = self::expandChildren($kind, $selected);
            }
            $plan['cat_ids'] = is_array($plan['cat_ids'] ?? null)
                ? array_values(array_intersect($plan['cat_ids'], $selected))
                : $selected;
        }
        if ($kind === 'product') {
            if (isset($overrides['price_min'])) {
                $plan['price_min'] = max((float) ($plan['price_min'] ?? 0), (float) $overrides['price_min']);
            }
            if (isset($overrides['price_max'])) {
                $plan['price_max'] = isset($plan['price_max'])
                    ? min((float) $plan['price_max'], (float) $overrides['price_max'])
                    : (float) $overrides['price_max'];
            }
        }
        if (isset($overrides['days']) && $kind !== 'term') {
            $from = strtotime('today') - ((int) $overrides['days'] - 1) * 86400;
            $plan['date_from'] = max((int) ($plan['date_from'] ?? 0), $from);
        }
        if (isset($overrides['sort'])) {
            $sort = (string) $overrides['sort'];
            $allowed = $kind === 'term' ? BloxQuerySpec::TERM_ORDERS : BloxQuerySpec::ITEM_ORDERS;
            if (in_array($sort, $allowed, true) && $sort !== 'manual' && $sort !== 'random') {
                $plan['order'] = $sort;
            }
        }
        if (isset($overrides['meta']) && is_array($overrides['meta']) && in_array($kind, ['content', 'product'], true)) {
            $items = [];
            foreach ($overrides['meta'] as $field => $values) {
                $items[] = ['field' => (string) $field, 'op' => 'in', 'value' => implode(',', (array) $values)];
            }
            $plan['meta_filter'] = [
                'owner' => $kind === 'product' ? 'product'
                    : (function_exists('resolveExtFieldOwner') ? resolveExtFieldOwner((string) ($plan['content_type'] ?? 'article')) : 'content'),
                'items' => $items,
            ];
        }
        return $plan;
    }

    /** @param list<int> $ids @return list<int> */
    private static function expandChildren(string $kind, array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            if ($kind === 'content' && function_exists('channelModel')) {
                $out = array_merge($out, array_map('intval', channelModel()->getChildIds($id)));
            } elseif ($kind === 'product' && function_exists('getChildProductCategoryIds')) {
                $out = array_merge($out, array_map('intval', getChildProductCategoryIds($id)));
            } else {
                $out[] = $id;
            }
        }
        return array_values(array_unique($out));
    }
}
