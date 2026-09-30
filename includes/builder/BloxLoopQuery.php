<?php
/**
 * 容器 Loop 查询（v1.25 Phase 2，2.0.3 对标 Bricks 扩展）：container/div 挂 `_query`
 * 即成为循环宿主，子树按行重复渲染，文案绑定走 {{loop.*}} 动态标签。
 *
 * 分工：
 * - BloxQuerySpec   查询体归一（唯一白名单入口，全局查询保存端同用）；
 * - 本类            `_query` 形态（内联/全局引用）、来源与上下文解析成执行计划
 *                   （分类 slug→id、含子类、相关内容、嵌套循环读外层行、当前列表页）、
 *                   同请求结果复用、分页参数与分页标记、循环行栈；
 * - BloxQueryRunner 执行计划 → 参数化 SQL。
 *
 * 2.0.3 起取数不再经 TagEngine {yk:list}（多分类/排除/日期/OR/新来源超出其契约），
 * {yk:list} 模板标签契约保持不变；默认排序、分类含子类的默认口径与旧实现逐项一致。
 *
 * 授权语义：`_query` 的新增/修改属 query_loop 作者端能力（BloxQueryLoopPolicy 拦保存），
 * 已发布内容渲染永远免费；能力失效时 BloxProtectedFields 冻结既有 `_query` 与子树结构。
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/i18n/LanguageRegistry.php';

final class BloxLoopQuery
{
    public const SOURCE_PATTERN = BloxQuerySpec::SOURCE_PATTERN;
    private const HOST_TYPES = ['container', 'div'];

    /** @var array<string,array{rows:list<array<string,mixed>>,total:int,page:int}> 同请求查询复用（杜绝同页同查询 N+1） */
    private static array $memo = [];

    /** @var int 本请求已写的失败日志条数（限流：循环页不刷爆错误日志） */
    private static int $loggedFailures = 0;

    /** @var list<array<string,mixed>> 正在渲染的循环行（外层在前）：嵌套循环读外层、{{parent.*}} 取值 */
    private static array $rowStack = [];

    /** @var array<string,int> 本请求已渲染循环的结果总数（结果摘要元素在循环之后渲染时直接取用） */
    private static array $hostTotals = [];

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
        self::$rowStack = [];
        self::$hostTotals = [];
    }

    public static function rememberTotal(string $hostId, int $total): void
    {
        self::$hostTotals[$hostId] = $total;
    }

    public static function totalFor(string $hostId): ?int
    {
        return self::$hostTotals[$hostId] ?? null;
    }

    /**
     * 查询异常的限流日志（外审 P2-5）：前台保持 fail-open 空态，但数据库回归
     * 不能伪装成普通空列表——排查要有踪迹。每请求最多 3 条，避免循环页刷爆日志。
     */
    private static function logFailure(Throwable $e, array $query): void
    {
        if (self::$loggedFailures >= 3) {
            return;
        }
        self::$loggedFailures++;
        error_log('[BloxLoopQuery] query failed, rendering empty state (source='
            . (string) ($query['source'] ?? '?') . '): ' . $e->getMessage());
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
        return BloxQuerySpec::normalize($raw);
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
     * 执行查询：返回行集、分页标记与分页状态（同请求同计划只执行一次）。
     * $paginationParam 为空表示不分页（或 current 源锁定主列表参数）。
     *
     * @param array<string,mixed> $overrides 前台筛选（BloxQueryFilters）叠加的条件
     * @return array{rows:list<array<string,mixed>>,pagination:string,is_product:bool,kind:string,total:int,page:int,pages:int,param:string}
     */
    public static function run(array $query, string $paginationParam, array $overrides = []): array
    {
        $empty = ['rows' => [], 'pagination' => '', 'is_product' => false, 'kind' => '', 'total' => 0, 'page' => 1, 'pages' => 1, 'param' => ''];
        $plan = self::plan($query, $overrides);
        if ($plan === null) {
            return $empty;
        }
        $param = self::effectivePageParam($query, $paginationParam);
        $page = 1;
        if ($param !== '') {
            $raw = $_GET[$param] ?? 1;
            $page = max(1, is_scalar($raw) ? (int) $raw : 1);
        }
        $memoKey = (json_encode([$plan, $page], JSON_UNESCAPED_UNICODE) ?: serialize([$plan, $page]));
        if (!isset(self::$memo[$memoKey])) {
            // DB 异常（表未建/升级中途）按空结果处理：循环容器不得把整页渲染打死
            try {
                $result = BloxQueryRunner::fetch($plan, $page);
                // 越界页（手改参数/数据变少）钳回末页，与 {yk:list} 同口径
                $pages = BloxQueryRunner::pages($result['total'], (int) $plan['limit']);
                if ($param !== '' && $page > $pages && $result['total'] > 0) {
                    $page = $pages;
                    $result = BloxQueryRunner::fetch($plan, $page);
                }
                self::$memo[$memoKey] = ['rows' => $result['rows'], 'total' => $result['total'], 'page' => $page];
            } catch (Throwable $e) {
                self::logFailure($e, $query);
                self::$memo[$memoKey] = ['rows' => [], 'total' => 0, 'page' => 1];
            }
        }
        $cached = self::$memo[$memoKey];
        $pages = BloxQueryRunner::pages((int) $cached['total'], (int) $plan['limit']);
        $page = (int) $cached['page'];
        $kind = (string) $plan['kind'];
        $rows = [];
        $count = count($cached['rows']);
        foreach (array_values($cached['rows']) as $i => $row) {
            $rows[] = self::decorateRow($row, $plan, $i, $count, (int) $cached['total'], $page, $pages);
        }
        $pagination = '';
        if ($param !== '' && $pages > 1) {
            $pagination = self::paginationHtml((string) ($query['pagination'] ?? 'numbers'), $param, $page, $pages, $query);
        }
        return [
            'rows' => $rows,
            'pagination' => $pagination,
            'is_product' => $kind === 'product',
            'kind' => $kind,
            'total' => (int) $cached['total'],
            'page' => $page,
            'pages' => $pages,
            'param' => $param,
        ];
    }

    /** 循环行附加虚拟键：类型、序号、首末奇偶、总数与分页（{{loop.*}} 与显示条件共用）。 */
    private static function decorateRow(array $row, array $plan, int $i, int $count, int $total, int $page, int $pages): array
    {
        $kind = (string) $plan['kind'];
        $row['_type'] = $kind;
        if ($kind === 'term') {
            $row['_taxonomy'] = (string) $plan['taxonomy'];
            // 分类行与条目卡片共用同一套绑定：title/summary/cover 别名
            if (($plan['taxonomy'] ?? '') === 'download') {
                $row['name'] = self::downloadCategoryName($row);
            }
            $row['title'] = (string) ($row['name'] ?? '');
            $row['summary'] = (string) ($row['description'] ?? '');
            $row['cover'] = (string) ($row['image'] ?? '');
        } elseif ($kind === 'download') {
            $row['summary'] = (string) ($row['description'] ?? '');
        }
        $index = $i + 1;
        $row['_index'] = $index;
        // 跨页的全局序号（加载更多/分页后继续编号）
        $row['_position'] = ($page - 1) * (int) $plan['limit'] + $index;
        // 显示条件的 field 规则只认字母开头的键：用 loop_* 暴露序号类虚拟字段
        $row['loop_index'] = (string) $index;
        $row['loop_first'] = $index === 1 ? '1' : '0';
        $row['loop_last'] = $index === $count ? '1' : '0';
        $row['loop_odd'] = $index % 2 === 1 ? '1' : '0';
        $row['loop_even'] = $index % 2 === 0 ? '1' : '0';
        $row['_total'] = $total;
        $row['_count'] = $count;
        $row['_page'] = $page;
        $row['_pages'] = $pages;
        return $row;
    }

    private static function downloadCategoryName(array $row): string
    {
        $lang = function_exists('siteLang') ? siteLang() : 'zh-CN';
        $localized = LanguageRegistry::localizedField($row, 'name', $lang, (function_exists('config') ? (string) config('site_lang', 'zh-CN') : 'zh-CN'));
        return $localized !== '' ? $localized : (string) ($row['name'] ?? '');
    }

    // ── 循环行栈（BlockRenderer 渲染每行时压入/弹出） ──────────────────

    public static function pushRow(array $row): void
    {
        self::$rowStack[] = $row;
        TagEngine::pushContext($row);
    }

    public static function popRow(): void
    {
        array_pop(self::$rowStack);
        TagEngine::popContext();
    }

    /** 外层循环行（{{parent.*}}）：当前行的上一层；不在嵌套循环里为 null。 */
    public static function parentRow(): ?array
    {
        $count = count(self::$rowStack);
        return $count >= 2 ? self::$rowStack[$count - 2] : null;
    }

    /** 正在渲染的最内层循环行（嵌套查询执行时即外层行）。 */
    private static function enclosingRow(): ?array
    {
        return self::$rowStack === [] ? null : self::$rowStack[count(self::$rowStack) - 1];
    }

    // ── 计划解析 ───────────────────────────────────────────────────

    /**
     * `_query`（+ 前台筛选叠加）→ 执行计划；来源不可解析（栏目停用、current 无上下文、
     * 相关内容不在详情页、嵌套循环无外层行）返回 null（空态，绝不退化为全量）。
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>|null
     */
    public static function plan(array $query, array $overrides = []): ?array
    {
        $source = (string) ($query['source'] ?? '');
        $plan = [
            'kind' => '',
            'limit' => max(1, min(BloxQuerySpec::MAX_LIMIT, (int) ($query['limit'] ?? 6))),
            'offset' => max(0, min(BloxQuerySpec::MAX_OFFSET, (int) ($query['offset'] ?? 0))),
            'order' => (string) ($query['order'] ?? 'default'),
        ];
        $children = null; // null = 各来源旧默认（内容不含子栏目、产品含子分类）
        if (is_bool($query['children'] ?? null)) {
            $children = $query['children'];
        }
        $cats = [];
        if (($query['cat'] ?? '') !== '') {
            $cats[] = (string) $query['cat'];
        }
        foreach ((array) ($query['cats'] ?? []) as $cat) {
            $cats[] = (string) $cat;
        }

        if (str_starts_with($source, 'terms:')) {
            $plan['kind'] = 'term';
            $plan['taxonomy'] = substr($source, 6);
            if (isset($query['parent_term'])) {
                $plan['parent_term'] = (int) $query['parent_term'];
            }
            if (!empty($query['hide_empty'])) {
                $plan['hide_empty'] = true;
            }
            if ($cats !== []) {
                $plan['cat_ids'] = self::resolveCats($plan['taxonomy'], $cats, false);
            }
            if (!empty($query['cats_exclude'])) {
                $plan['cat_exclude_ids'] = self::resolveCats($plan['taxonomy'], (array) $query['cats_exclude'], false);
            }
            if (($query['scope'] ?? '') === 'parent') {
                // 嵌套：外层是同类分类行 → 列其子分类；否则没有可继承的父级
                $outer = self::enclosingRow();
                if ($outer === null || ($outer['_type'] ?? '') !== 'term' || ($outer['_taxonomy'] ?? '') !== $plan['taxonomy']) {
                    return null;
                }
                $plan['parent_term'] = (int) ($outer['id'] ?? 0);
            }
            return $plan;
        }

        // 条目来源
        if (str_starts_with($source, 'channel:')) {
            $channelId = (int) substr($source, 8);
            $channel = function_exists('channelModel') ? channelModel()->find($channelId) : null;
            if (!is_array($channel) || empty($channel['status'])) {
                return null;
            }
            $channelType = (string) ($channel['type'] ?? '');
            if (in_array($channelType, ['download', 'job', 'product'], true)) {
                // 下载/招聘/产品栏目：各自的表，栏目本身不是它们的分类
                $plan['kind'] = $channelType;
                $cats = [];
            } else {
                $plan['kind'] = 'content';
                $plan['content_type'] = self::safeType(self::channelContentType($channelType));
                $cats = [(string) $channelId];
            }
        } elseif (str_starts_with($source, 'type:')) {
            $type = self::safeType(substr($source, 5));
            if (in_array($type, ['product', 'download', 'job'], true)) {
                $plan['kind'] = $type;
            } else {
                $plan['kind'] = 'content';
                $plan['content_type'] = $type;
            }
        } elseif ($source === 'current') {
            // 继承当前列表页的查询上下文（栏目/搜索词/主分页参数）
            $context = self::$currentContext;
            $channel = is_array($context['channel'] ?? null) ? $context['channel'] : null;
            if ($context === null || $channel === null || empty($channel['status'])) {
                return null;
            }
            $cats = [];
            if ((string) ($channel['type'] ?? '') === 'product') {
                $plan['kind'] = 'product';
                $categoryId = (int) ($context['category_id'] ?? 0);
                if ($categoryId > 0) {
                    $cats = [(string) $categoryId];
                }
            } else {
                $plan['kind'] = 'content';
                $plan['content_type'] = self::safeType(self::channelContentType((string) ($channel['type'] ?? '')));
                $cats = [(string) (int) ($channel['id'] ?? 0)];
            }
            $contextKeyword = trim((string) ($context['keyword'] ?? ''));
            if ($contextKeyword !== '') {
                $plan['keyword'] = mb_substr($contextKeyword, 0, 200);
            }
        } else {
            return null;
        }
        $kind = (string) $plan['kind'];
        $taxonomyOf = $kind === 'content' ? 'content' : $kind;

        // 相关内容 / 嵌套外层：分类来自当前详情条目或外层循环行，并排除该条目本身
        $scope = (string) ($query['scope'] ?? '');
        $excludeIds = array_map('intval', (array) ($query['exclude_ids'] ?? []));
        if ($scope === 'related') {
            $item = self::pageItem($kind);
            if ($item === null) {
                return null;
            }
            $cats = [(string) (int) ($item[$kind === 'content' ? 'channel_id' : 'category_id'] ?? 0)];
            $excludeIds[] = (int) ($item['id'] ?? 0);
        } elseif ($scope === 'parent') {
            $outer = self::enclosingRow();
            if ($outer === null) {
                return null;
            }
            if (($outer['_type'] ?? '') === 'term') {
                if (($outer['_taxonomy'] ?? '') !== $taxonomyOf) {
                    return null;
                }
                $cats = [(string) (int) ($outer['id'] ?? 0)];
            } elseif (($outer['_type'] ?? '') === $kind && $kind !== 'job') {
                $cats = [(string) (int) ($outer[$kind === 'content' ? 'channel_id' : 'category_id'] ?? 0)];
                $excludeIds[] = (int) ($outer['id'] ?? 0);
            } else {
                return null;
            }
        }
        if (!empty($query['exclude_current'])) {
            $item = self::pageItem($kind);
            if ($item !== null) {
                $excludeIds[] = (int) ($item['id'] ?? 0);
            }
        }

        if ($kind !== 'job') {
            if ($cats !== []) {
                $withChildren = $children ?? ($kind === 'product');
                $plan['cat_ids'] = self::resolveCats($taxonomyOf, $cats, $withChildren);
            }
            if (!empty($query['cats_exclude'])) {
                $plan['cat_exclude_ids'] = self::resolveCats($taxonomyOf, (array) $query['cats_exclude'], $children ?? ($kind === 'product'));
                if ($plan['cat_exclude_ids'] === []) {
                    unset($plan['cat_exclude_ids']); // 排除项解析不到：等于不排除
                }
            }
        }
        if (!empty($query['ids'])) {
            $plan['ids'] = array_map('intval', (array) $query['ids']);
        }
        $excludeIds = array_values(array_unique(array_filter($excludeIds, static fn (int $id): bool => $id > 0)));
        if ($excludeIds !== []) {
            $plan['exclude_ids'] = $excludeIds;
        }
        if (($query['keyword'] ?? '') !== '') {
            $plan['keyword'] = (string) $query['keyword'];
        }
        $flags = [];
        foreach (['recommend', 'hot', 'top', 'new'] as $flag) {
            if (!empty($query[$flag])) {
                $flags[] = $flag;
            }
        }
        if ($flags !== []) {
            $plan['flags'] = $flags;
        }
        $within = (int) ($query['date_within'] ?? 0);
        if ($within > 0) {
            // 按天取整：同一天内的请求计划一致（结果复用、页面缓存友好）
            $plan['date_from'] = strtotime('today') - ($within - 1) * 86400;
        }
        if (is_string($query['date_from'] ?? null) && ($from = strtotime($query['date_from'] . ' 00:00:00')) !== false) {
            $plan['date_from'] = max((int) ($plan['date_from'] ?? 0), $from);
        }
        if (is_string($query['date_to'] ?? null) && ($to = strtotime($query['date_to'] . ' 23:59:59')) !== false) {
            $plan['date_to'] = $to;
        }
        foreach (['price_min', 'price_max'] as $key) {
            if (isset($query[$key]) && is_numeric($query[$key])) {
                $plan[$key] = (float) $query[$key];
            }
        }
        if (!empty($query['filters']) && is_array($query['filters']) && in_array($kind, ['content', 'product'], true)) {
            $plan['meta'] = [
                'owner' => $kind === 'product' ? 'product'
                    : (function_exists('resolveExtFieldOwner') ? resolveExtFieldOwner((string) $plan['content_type']) : 'content'),
                'items' => array_values($query['filters']),
                'relation' => ($query['filter_relation'] ?? 'and') === 'or' ? 'or' : 'and',
            ];
        }
        return BloxQueryFilters::applyOverrides($plan, $overrides);
    }

    /** 当前页面主条目（相关内容 / 排除当前）：类型必须与查询来源一致。 */
    private static function pageItem(string $kind): ?array
    {
        $candidates = [];
        if ($kind === 'product') {
            $candidates[] = class_exists('ProductTemplateDocument') ? ProductTemplateDocument::currentProduct() : null;
        } elseif ($kind === 'content') {
            $candidates[] = class_exists('ArticleTemplateDocument') ? ArticleTemplateDocument::currentContent() : null;
        }
        $item = TagEngine::pageItem();
        if (is_array($item)) {
            $itemType = (string) ($item['_type'] ?? 'content');
            $isJob = array_key_exists('salary', $item) && !array_key_exists('channel_id', $item);
            if (($kind === 'product' && $itemType === 'product')
                || ($kind === 'content' && $itemType === 'content' && !$isJob)
                || ($kind === 'job' && $isJob)) {
                $candidates[] = $item;
            }
        }
        foreach ($candidates as $candidate) {
            if (is_array($candidate) && (int) ($candidate['id'] ?? 0) > 0) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * 分类 id/slug → id 列表（可选含子类）。解析不到的项丢弃；全部解析不到返回 []
     * （执行端据此返回空结果）。
     *
     * @param array<int,mixed> $cats
     * @return list<int>
     */
    private static function resolveCats(string $taxonomy, array $cats, bool $withChildren): array
    {
        $ids = [];
        foreach ($cats as $cat) {
            $cat = trim((string) $cat);
            if ($cat === '') {
                continue;
            }
            if (ctype_digit($cat)) {
                $id = (int) $cat;
            } elseif ($taxonomy === 'product') {
                $row = function_exists('getProductCategoryBySlug') ? getProductCategoryBySlug($cat) : null;
                $id = is_array($row) ? (int) $row['id'] : 0;
            } elseif ($taxonomy === 'download') {
                $row = db()->fetchOne('SELECT id FROM ' . DB_PREFIX . 'download_categories WHERE slug = ?', [$cat]);
                $id = is_array($row) ? (int) $row['id'] : 0;
            } else {
                $row = function_exists('getChannelBySlug') ? getChannelBySlug($cat) : null;
                $id = is_array($row) ? (int) $row['id'] : 0;
            }
            if ($id <= 0) {
                continue;
            }
            if ($withChildren && $taxonomy === 'content' && function_exists('channelModel')) {
                $ids = array_merge($ids, array_map('intval', channelModel()->getChildIds($id)));
            } elseif ($withChildren && $taxonomy === 'product' && function_exists('getChildProductCategoryIds')) {
                $ids = array_merge($ids, array_map('intval', getChildProductCategoryIds($id)));
            } else {
                $ids[] = $id;
            }
        }
        return array_values(array_unique($ids));
    }

    private static function safeType(string $type): string
    {
        $type = strtolower(trim($type));
        return preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $type) ? $type : 'article';
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

    // ── 分页 ───────────────────────────────────────────────────────

    /** current 源锁定主列表分页参数；其余用节点派生参数（空 = 不分页）。 */
    private static function effectivePageParam(array $query, string $paginationParam): string
    {
        if (!BloxQuerySpec::isPaged($query)) {
            return '';
        }
        if (($query['source'] ?? '') === 'current') {
            $param = (string) (self::$currentContext['page_param'] ?? 'page');
            $param = preg_replace('/[^a-zA-Z0-9_]/', '', $param) ?? '';
            return $param !== '' && strlen($param) <= 40 ? $param : 'page';
        }
        return preg_match('/^[a-zA-Z0-9_]{1,40}$/D', $paginationParam) ? $paginationParam : '';
    }

    /**
     * 分页标记：numbers/ajax 为数字导航（ajax 由前台脚本接管点击）；load_more 为按钮 +
     * 无脚本回退的下一页链接；infinite 为滚动哨兵 + 同款回退链接。
     */
    private static function paginationHtml(string $mode, string $param, int $page, int $pages, array $query): string
    {
        if ($mode === 'numbers' || $mode === 'ajax') {
            return TagEngine::paginationNav($param, $page, $pages, $mode === 'ajax' ? ' data-yk-query-nav' : '');
        }
        if ($page >= $pages) {
            return '';
        }
        $next = e(TagEngine::pageUrl($param, $page + 1));
        if ($mode === 'infinite') {
            return '<div class="yk-query-infinite mt-6 flex justify-center" data-yk-query-infinite>'
                . '<a class="text-sm text-gray-500 no-underline hover:text-primary" href="' . $next . '" rel="next" data-yk-query-next>'
                . e(__('blox_query_load_more_default')) . '</a></div>';
        }
        $text = trim((string) ($query['load_more_text'] ?? ''));
        return '<div class="yk-query-more mt-8 flex justify-center">'
            . '<a class="inline-flex items-center gap-2 rounded-lg border border-primary px-6 py-2.5 text-sm font-medium text-primary no-underline transition hover:bg-primary hover:text-white" href="'
            . $next . '" rel="next" data-yk-query-more data-yk-query-next>'
            . e($text !== '' ? $text : __('blox_query_load_more_default')) . '</a></div>';
    }

    // ── 编辑器数据（循环面板 / 筛选元素的候选项） ─────────────────────

    /**
     * 来源分组：内容类型、其他条目表、指定栏目、分类循环。
     *
     * @return list<array{label:string,options:array<string,string>}>
     */
    public static function editorSources(): array
    {
        $types = [
            'type:article' => __('blox_dynamic_source_article'),
            'type:case' => __('blox_dynamic_source_case'),
        ];
        try {
            if (function_exists('contentModelModel')) {
                foreach (contentModelModel()->allActive() as $model) {
                    $key = self::safeType((string) ($model['model_key'] ?? ''));
                    if ($key !== 'article' && !in_array($key, ContentModelModel::RESERVED_KEYS, true)) {
                        $types['type:' . $key] = (string) ($model['name'] ?? $key);
                    }
                }
            }
        } catch (Throwable) {
            // 安装早期/无数据库：只列内置类型
        }
        $channels = [];
        foreach (ListDynamicElement::sourceOptions() as $value => $label) {
            if (str_starts_with((string) $value, 'channel:')) {
                $channels[(string) $value] = (string) $label;
            }
        }
        $groups = [
            ['label' => __('blox_query_group_content'), 'options' => $types],
            ['label' => __('blox_query_group_other'), 'options' => [
                'type:product' => __('blox_dynamic_source_product'),
                'type:download' => __('blox_query_source_download'),
                'type:job' => __('blox_query_source_job'),
                'current' => __('blox_loop_source_current'),
            ]],
            ['label' => __('blox_query_group_terms'), 'options' => [
                'terms:content' => __('blox_query_terms_content'),
                'terms:product' => __('blox_query_terms_product'),
                'terms:download' => __('blox_query_terms_download'),
            ]],
        ];
        if ($channels !== []) {
            $groups[] = ['label' => __('blox_query_group_channels'), 'options' => $channels];
        }
        return $groups;
    }

    /**
     * 分类候选（树形缩进）：content=栏目、product=产品分类、download=下载分类。
     *
     * @return array<string,list<array{value:int,label:string}>>
     */
    public static function editorTerms(): array
    {
        $out = ['content' => [], 'product' => [], 'download' => []];
        try {
            foreach (channelModel()->getFlatList(0, 0, function_exists('siteLang') ? siteLang() : null) as $channel) {
                $id = (int) ($channel['id'] ?? 0);
                if ($id < 1 || empty($channel['status']) || in_array((string) ($channel['type'] ?? ''), ['link', 'form'], true)) {
                    continue;
                }
                $out['content'][] = ['value' => $id, 'label' => str_repeat('— ', min(4, (int) ($channel['_level'] ?? 0))) . (string) ($channel['name'] ?? '#' . $id)];
            }
            $walk = static function (array $nodes, int $level) use (&$walk, &$out): void {
                foreach ($nodes as $node) {
                    $out['product'][] = ['value' => (int) $node['id'], 'label' => str_repeat('— ', min(4, $level)) . (string) ($node['name'] ?? '')];
                    $walk((array) ($node['children'] ?? []), $level + 1);
                }
            };
            if (function_exists('productCategoryModel')) {
                $walk(productCategoryModel()->getTree(0), 0);
            }
            foreach (db()->fetchAll('SELECT * FROM ' . DB_PREFIX . 'download_categories WHERE status = 1 ORDER BY sort_order DESC, id ASC') as $row) {
                $out['download'][] = ['value' => (int) $row['id'], 'label' => self::downloadCategoryName($row)];
            }
        } catch (Throwable) {
            // 缺表（未安装下载/产品模块的老站）：对应候选为空
        }
        return $out;
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
