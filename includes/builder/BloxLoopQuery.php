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
    public const SOURCE_PATTERN = '/^(?:type:[a-z][a-z0-9_-]{0,31}|channel:[1-9][0-9]{0,9}|current)$/D';
    private const ORDERS = ['default', 'recommend_first', 'newest', 'updated', 'views', 'price_asc', 'price_desc'];
    private const HOST_TYPES = ['container', 'div'];

    /** @var array<string,array{rows:array<int,array<string,mixed>>,pagination:string}> 同请求查询复用（杜绝同页同查询 N+1） */
    private static array $memo = [];

    /** @var int 本请求已写的失败日志条数（限流：循环页不刷爆错误日志） */
    private static int $loggedFailures = 0;

    /**
     * current 源（v1.25）的请求级上下文：列表页入口（list.php）在渲染栏目 Blox 文档
     * 前设置、渲染后清空。键：channel（当前请求的栏目行，可能是子栏目）、
     * keyword（当前搜索词）、category_id（产品分类页）、page_param（主列表分页参数，默认 page）。
     * 无上下文（首页/详情/预览端点/编辑器）时 current 源渲染为空态，绝不退化为全量。
     * @var array<string,mixed>|null
     */
    private static ?array $currentContext = null;

    public static function setCurrentContext(?array $context): void
    {
        self::$currentContext = $context;
    }

    /** @psalm-suppress PossiblyUnusedMethod 测试专用（单测进程共享请求级缓存时复位） */
    public static function resetForTests(): void
    {
        self::$memo = [];
        self::$currentContext = null;
        self::$loggedFailures = 0;
    }

    /**
     * 查询异常的限流日志（外审 P2-5）：前台保持 fail-open 空态，但数据库回归
     * 不能伪装成普通空列表——排查要有踪迹。每请求最多 3 条，避免循环页刷爆日志。
     */
    private static function logFailure(Throwable $e, array $attrs): void
    {
        if (self::$loggedFailures >= 3) {
            return;
        }
        self::$loggedFailures++;
        error_log('[BloxLoopQuery] query failed, rendering empty state (source='
            . (string) ($attrs['source'] ?? ($attrs['type'] ?? '?')) . '): ' . $e->getMessage());
    }

    /** 归一元素 data 上的 `_query`：非法整体删除（安全边界——值决定查询与分页参数）。 */
    public static function normalizeElementData(array $data): array
    {
        if (!array_key_exists('_query', $data)) {
            return $data;
        }
        $raw = $data['_query'];
        // v1.25 全局查询引用形态：{ref: gq_xxx}——只存引用，查询体在 blox_global_queries
        if (is_array($raw) && is_string($raw['ref'] ?? null)
            && preg_match(BloxGlobalQueries::ID_PATTERN, $raw['ref'])) {
            $data['_query'] = ['ref' => $raw['ref']];
            return $data;
        }
        $query = is_array($raw) ? self::normalizeQuery($raw) : null;
        if ($query === null) {
            unset($data['_query']);
        } else {
            $data['_query'] = $query;
        }
        return $data;
    }

    /** 内联查询体归一：非法返回 null。全局查询保存端复用同一份规则。 */
    public static function normalizeQuery(array $raw): ?array
    {
        if (!is_string($raw['source'] ?? null) || !preg_match(self::SOURCE_PATTERN, $raw['source'])) {
            return null;
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
        $filters = self::normalizeFilters($raw['filters'] ?? null);
        if ($filters !== []) {
            $query['filters'] = $filters;
        }
        return $query;
    }

    /**
     * 自定义字段过滤归一（v1.25 §7.2.1）：AND 平铺 ≤5 条 {field,op,value}，
     * 算子白名单与 SQL 编译端同源（MetaModel::FILTER_OPS）；数值算子强校验、
     * between 要求「a,b」双数值；不合法条目静默丢弃（与 _query 其余键同规——
     * 值决定 SQL 形态，宁缺毋滥）。
     *
     * @return list<array{field:string,op:string,value:string}>
     */
    private static function normalizeFilters(mixed $raw): array
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

    /**
     * 该元素是否是循环宿主。引用形态解析全局查询体；悬挂引用返回
     * `['__dangling' => true]`（渲染端按空结果提示处理，绝不退化为静态渲染）。
     */
    public static function queryFrom(string $type, array $data): ?array
    {
        if (!in_array($type, self::HOST_TYPES, true)) {
            return null;
        }
        $query = $data['_query'] ?? null;
        if (!is_array($query)) {
            return null;
        }
        if (is_string($query['ref'] ?? null)) {
            if (!preg_match(BloxGlobalQueries::ID_PATTERN, $query['ref'])) {
                return null;
            }
            $resolved = BloxGlobalQueries::queryBody($query['ref']);
            return $resolved ?? ['__dangling' => true];
        }
        return is_string($query['source'] ?? null)
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
            // DB 异常（表未建/升级中途）按空结果处理：循环容器不得把整页渲染打死，
            // 空态语义现成且安全（与 sourceOptions/BlocksLibrary 的容错口径一致）
            try {
                self::$memo[$memoKey] = [
                    'rows' => TagEngine::listItems($attrs),
                    'pagination' => ($query['pagination'] ?? 'none') === 'numbers'
                        ? TagEngine::tagListPagination($attrs, null)
                        : '',
                ];
            } catch (Throwable $e) {
                // 外审 P2-5：数据库回归不能伪装成普通空态——留限流日志再 fail-open
                self::logFailure($e, $attrs);
                self::$memo[$memoKey] = ['rows' => [], 'pagination' => ''];
            }
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
            $type = self::channelContentType((string) ($channel['type'] ?? ''));
            $attrs['type'] = preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $type) ? $type : 'article';
            $attrs['cat'] = (string) $channelId;
        } elseif (str_starts_with($source, 'type:')) {
            $type = substr($source, 5);
            $attrs['type'] = preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $type) ? $type : 'article';
            if (($query['cat'] ?? '') !== '') {
                $attrs['cat'] = (string) $query['cat'];
            }
        } elseif ($source === 'current') {
            // 继承当前列表页的查询上下文（栏目/搜索词/主分页参数）。上下文由
            // list.php 在渲染栏目 Blox 文档前设置；其余入口（首页/详情/预览/编辑器）
            // 无上下文 → 空态，与未知栏目同款守卫。
            $context = self::$currentContext;
            $channel = is_array($context['channel'] ?? null) ? $context['channel'] : null;
            if ($context === null || $channel === null || empty($channel['status'])) {
                return null;
            }
            if ((string) ($channel['type'] ?? '') === 'product') {
                $attrs['type'] = 'product';
                $categoryId = (int) ($context['category_id'] ?? 0);
                if ($categoryId > 0) {
                    $attrs['cat'] = (string) $categoryId;
                }
            } else {
                $type = self::channelContentType((string) ($channel['type'] ?? ''));
                $attrs['type'] = preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $type) ? $type : 'article';
                $attrs['cat'] = (string) (int) ($channel['id'] ?? 0);
            }
            $contextKeyword = trim((string) ($context['keyword'] ?? ''));
            if ($contextKeyword !== '') {
                $attrs['keyword'] = mb_substr($contextKeyword, 0, 200);
            }
            if (($query['pagination'] ?? 'none') === 'numbers') {
                // 与主列表共用同一分页参数：current 循环就是本页的列表本体
                $attrs['page_param'] = (string) ($context['page_param'] ?? 'page');
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
        // 自定义字段过滤：owner 归一在 TagEngine::listQueryContext（type 在那里定型），
        // 规格进 attrs 也让 memo 键自然覆盖过滤维度
        if (!empty($query['filters']) && is_array($query['filters'])) {
            $attrs['_meta_filters'] = array_values($query['filters']);
        }
        if (($query['pagination'] ?? 'none') === 'numbers' && $paginationParam !== ''
            && !isset($attrs['page_param'])) { // current 源已锁定主列表参数，不被节点派生参数覆盖
            $attrs['page_param'] = $paginationParam;
        }
        return $attrs;
    }

    /**
     * 栏目 type → contents.type 映射：'list' 栏目存放的内容行 type 是 'article'
     * （2026-09-20 开发库实测：所有 list 栏目下 60 行全部 type=article），直接拿栏目
     * type 过滤会恒空——这是 channel:/current 源共用的唯一映射点。其余栏目类型
     * （case/自定义模型）与内容 type 同名；product 不走 contents 表。
     */
    public static function channelContentType(string $channelType): string
    {
        $channelType = strtolower(trim($channelType));
        return $channelType === 'list' || $channelType === '' ? 'article' : $channelType;
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
