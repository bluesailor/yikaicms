<?php
/**
 * 容器 Loop 查询（v1.25 Phase 2）：container/div 挂 `_query` 即成为循环宿主，
 * 子树按行重复渲染，文案绑定走 {{loop.*}} 动态标签。
 *
 * 查询语义**完全复用** TagEngine 的 {yk:list} 契约（listItems/tagListPagination），
 * 一份查询实现、零漂移；本类只负责 `_query` 的归一化、source 解析（type:/channel:，
 * 与 list-dynamic 同一语法）、同请求结果复用与分页参数派生。
 *
 * 授权语义：`_query` 的新增/修改属 query_loop 作者端能力（BloxQueryLoopPolicy 拦保存），
 * 已发布内容渲染永远免费；能力失效时 BloxProtectedFields 冻结既有 `_query` 与子树结构。
 */

declare(strict_types=1);

final class BloxLoopQuery
{
    public const SOURCE_PATTERN = '/^(?:type:[a-z][a-z0-9_-]{0,31}|channel:[1-9][0-9]{0,9})$/D';
    private const ORDERS = ['default', 'recommend_first', 'newest', 'updated', 'views', 'price_asc', 'price_desc'];
    private const HOST_TYPES = ['container', 'div'];

    /** @var array<string,array{rows:array<int,array<string,mixed>>,pagination:string}> 同请求查询复用（杜绝同页同查询 N+1） */
    private static array $memo = [];

    /** @psalm-suppress PossiblyUnusedMethod 测试专用（单测进程共享请求级缓存时复位） */
    public static function resetForTests(): void
    {
        self::$memo = [];
    }

    /** 归一元素 data 上的 `_query`：非法整体删除（安全边界——值决定查询与分页参数）。 */
    public static function normalizeElementData(array $data): array
    {
        if (!array_key_exists('_query', $data)) {
            return $data;
        }
        $raw = $data['_query'];
        if (!is_array($raw) || !is_string($raw['source'] ?? null) || !preg_match(self::SOURCE_PATTERN, $raw['source'])) {
            unset($data['_query']);
            return $data;
        }
        $query = ['source' => $raw['source']];
        $cat = trim((string) ($raw['cat'] ?? ''));
        if ($cat !== '' && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/iD', $cat)) {
            $query['cat'] = $cat;
        }
        $query['limit'] = max(1, min(50, (int) ($raw['limit'] ?? 6)));
        $offset = max(0, min(5000, (int) ($raw['offset'] ?? 0)));
        if ($offset > 0) {
            $query['offset'] = $offset;
        }
        $keyword = trim((string) ($raw['keyword'] ?? ''));
        if ($keyword !== '') {
            $query['keyword'] = mb_substr($keyword, 0, 200);
        }
        foreach (['recommend', 'hot', 'top'] as $flag) {
            if (!empty($raw[$flag])) {
                $query[$flag] = true;
            }
        }
        $order = (string) ($raw['order'] ?? 'default');
        if ($order !== 'default' && in_array($order, self::ORDERS, true)) {
            $query['order'] = $order;
        }
        if (($raw['pagination'] ?? 'none') === 'numbers') {
            $query['pagination'] = 'numbers';
        }
        if (($raw['empty_mode'] ?? 'message') === 'hidden') {
            $query['empty_mode'] = 'hidden';
        }
        $empty = trim((string) ($raw['empty'] ?? ''));
        if ($empty !== '') {
            $query['empty'] = mb_substr($empty, 0, 200);
        }
        $data['_query'] = $query;
        return $data;
    }

    /** 该元素是否是循环宿主（类型 + 合法 `_query`）。 */
    public static function queryFrom(string $type, array $data): ?array
    {
        if (!in_array($type, self::HOST_TYPES, true)) {
            return null;
        }
        $query = $data['_query'] ?? null;
        return is_array($query) && is_string($query['source'] ?? null)
            && preg_match(self::SOURCE_PATTERN, $query['source']) ? $query : null;
    }

    /**
     * 执行查询：返回行集与分页 HTML（同请求同查询只执行一次）。
     *
     * @return array{rows:array<int,array<string,mixed>>,pagination:string,is_product:bool}
     */
    public static function run(array $query, string $paginationParam): array
    {
        $attrs = self::attrsFor($query, $paginationParam);
        if ($attrs === null) {
            return ['rows' => [], 'pagination' => '', 'is_product' => false];
        }
        $isProduct = ($attrs['type'] ?? '') === 'product';
        $memoKey = json_encode($attrs, JSON_UNESCAPED_UNICODE) ?: serialize($attrs);
        if (!isset(self::$memo[$memoKey])) {
            self::$memo[$memoKey] = [
                'rows' => TagEngine::listItems($attrs),
                'pagination' => ($query['pagination'] ?? 'none') === 'numbers'
                    ? TagEngine::tagListPagination($attrs, null)
                    : '',
            ];
        }
        $cached = self::$memo[$memoKey];
        return ['rows' => $cached['rows'], 'pagination' => $cached['pagination'], 'is_product' => $isProduct];
    }

    /**
     * `_query` → {yk:list} 属性数组。channel:N 与 list-dynamic 同语义：
     * 取该栏目的 type 作为内容类型、栏目 id 作为 cat（覆盖用户填的 cat）；
     * 栏目不存在或停用返回 null（渲染为空，不退化为全量查询）。
     *
     * @return array<string,mixed>|null
     */
    private static function attrsFor(array $query, string $paginationParam): ?array
    {
        $source = (string) ($query['source'] ?? '');
        $attrs = [
            'limit' => max(1, min(50, (int) ($query['limit'] ?? 6))),
            'offset' => max(0, min(5000, (int) ($query['offset'] ?? 0))),
        ];
        if (str_starts_with($source, 'channel:')) {
            $channelId = (int) substr($source, 8);
            $channel = function_exists('channelModel') ? channelModel()->find($channelId) : null;
            if (!is_array($channel) || empty($channel['status'])) {
                return null;
            }
            $type = strtolower(trim((string) ($channel['type'] ?? '')));
            $attrs['type'] = preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $type) ? $type : 'article';
            $attrs['cat'] = (string) $channelId;
        } elseif (str_starts_with($source, 'type:')) {
            $type = substr($source, 5);
            $attrs['type'] = preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $type) ? $type : 'article';
            if (($query['cat'] ?? '') !== '') {
                $attrs['cat'] = (string) $query['cat'];
            }
        } else {
            return null;
        }
        if (($query['keyword'] ?? '') !== '') {
            $attrs['keyword'] = (string) $query['keyword'];
        }
        foreach (['recommend', 'hot', 'top'] as $flag) {
            if (!empty($query[$flag])) {
                $attrs[$flag] = 1;
            }
        }
        if (($query['order'] ?? '') !== '' && ($query['order'] ?? 'default') !== 'default') {
            $attrs['order'] = (string) $query['order'];
        }
        if (($query['pagination'] ?? 'none') === 'numbers' && $paginationParam !== '') {
            $attrs['page_param'] = $paginationParam;
        }
        return $attrs;
    }

    /** 每个循环容器独立、稳定的分页 GET 参数（与 list-dynamic 同派生规则，避免同页串页）。 */
    public static function paginationParam(array $query, string $nodeId): string
    {
        $identity = trim($nodeId);
        if ($identity === '') {
            $identity = json_encode([
                $query['source'] ?? '',
                $query['cat'] ?? '',
                $query['limit'] ?? 6,
                $query['offset'] ?? 0,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'query';
        }
        return 'ykq_' . substr(hash('sha256', $identity), 0, 10);
    }
}
