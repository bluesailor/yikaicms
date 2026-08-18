<?php
/**
 * YikaiCMS — 模板标签引擎 {yk:...}
 *
 * 让不会 PHP 的建站者在内容/单页/页面构建器里直接套动态数据（对标织梦/帝国的标签体系）。
 *
 * 语法：
 *   块标签（带内层模板，逐条循环渲染）：
 *     {yk:list type=article cat=news limit=6}
 *       <li><a href="{yk:field name=url /}">{yk:field name=title /}</a></li>
 *     {/yk:list}
 *   自闭合标签：
 *     {yk:field name=title /}
 *     {yk:channel slug=about field=url /}
 *     {yk:banner group=home /}
 *     {yk:config name=site_name /}
 *
 * 规则：
 *   - 属性值支持 双引号 / 单引号 / 裸值（裸值不能含空格）
 *   - 未注册的标签原样保留（不吞不报错）
 *   - 同名块标签不支持嵌套；不同名可以（如 list 内套 field/channel）
 *   - {yk:field} 默认 HTML 转义输出，esc=0 关闭（仅用于 content 这类本身已 sanitize 的字段）
 *   - 插件可在 `tag_engine_register` action 里调用 TagEngine::register() 扩展标签
 *
 * 完整标签参考见 docs/template-tags.md。
 */

declare(strict_types=1);

final class TagEngine
{
    /** @var array<string, array{handler: callable, block: bool}> */
    private static array $tags = [];

    /** @var array<int, array<string, mixed>> {yk:list} 循环时的当前条目上下文栈 */
    private static array $contextStack = [];

    /** @var array<string,mixed>|null 当前页面主条目（详情/单页），contextStack 为空时的兜底 */
    private static ?array $item = null;

    private static bool $booted = false;
    private static int $depth = 0;

    private const MAX_DEPTH = 8;
    private const MAX_LIMIT = 50;

    /**
     * 注册标签。$block=true 表示块标签（{yk:x}...{/yk:x}，handler 收到 inner 模板）。
     * handler 签名：fn(array $attrs, ?string $inner): string
     */
    public static function register(string $name, callable $handler, bool $block = false): void
    {
        self::$tags[strtolower($name)] = ['handler' => $handler, 'block' => $block];
    }

    /**
     * 渲染模板中的全部 {yk:...} 标签。无标签时零开销直接返回。
     */
    public static function render(?string $tpl): string
    {
        $tpl = (string) $tpl;
        if ($tpl === '' || strpos($tpl, '{yk:') === false) {
            return $tpl;
        }
        self::boot();

        if (self::$depth >= self::MAX_DEPTH) {
            return $tpl;
        }
        self::$depth++;
        try {
            // 1. 块标签：{yk:name attrs}inner{/yk:name}（同名不嵌套，inner 非贪婪）
            $tpl = (string) preg_replace_callback(
                '~\{yk:([a-z][\w-]*)((?:\s[^{}]*?)?)\}(.*?)\{/yk:\1\}~is',
                function (array $m): string {
                    $name = strtolower($m[1]);
                    $tag = self::$tags[$name] ?? null;
                    if ($tag === null || !$tag['block']) {
                        return $m[0]; // 未注册 / 非块标签 → 原样保留
                    }
                    return (string) ($tag['handler'])(self::parseAttrs($m[2]), $m[3]);
                },
                $tpl
            );

            // 2. 自闭合标签：{yk:name attrs /} 或 {yk:name attrs}
            $tpl = (string) preg_replace_callback(
                '~\{yk:([a-z][\w-]*)((?:\s[^{}]*?)?)/?\}~i',
                function (array $m): string {
                    $name = strtolower($m[1]);
                    $tag = self::$tags[$name] ?? null;
                    if ($tag === null || $tag['block']) {
                        return $m[0]; // 未注册 / 落单的块标签开标记 → 原样保留
                    }
                    return (string) ($tag['handler'])(self::parseAttrs($m[2]), null);
                },
                $tpl
            );
        } finally {
            self::$depth--;
        }

        return $tpl;
    }

    /** 解析属性串：key="v" / key='v' / key=v */
    public static function parseAttrs(string $raw): array
    {
        $attrs = [];
        if (trim($raw) === '') {
            return $attrs;
        }
        if (preg_match_all('~([\w-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s/}]+))~', $raw, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $attrs[strtolower($m[1])] = $m[2] !== '' ? $m[2] : ($m[3] !== '' ? $m[3] : ($m[4] ?? ''));
            }
        }
        return $attrs;
    }

    // ---- {yk:list} 循环上下文（供 {yk:field} 取值） ----

    public static function pushContext(array $item): void
    {
        self::$contextStack[] = $item;
    }

    public static function popContext(): void
    {
        array_pop(self::$contextStack);
    }

    public static function currentContext(): ?array
    {
        if (self::$contextStack !== []) {
            return self::$contextStack[count(self::$contextStack) - 1];
        }
        return self::$item; // 兜底：详情/单页的当前条目，使 {yk:field}/{yk:if} 在正文里可用
    }

    /**
     * 设置当前页面主条目（详情/单页正文渲染前调用），使 {yk:field}/{yk:if}/{yk:list related}
     * 在没有 {yk:list} 循环时也能取到「本篇」的字段。$type 决定 url 生成器（content/product）。
     */
    public static function setItem(?array $item, string $type = 'content'): void
    {
        if ($item !== null && !isset($item['_type'])) {
            $item['_type'] = $type;
        }
        self::$item = $item;
    }

    /** 注册内置标签（幂等）；随后广播 tag_engine_register 让插件扩展。 */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        self::register('list', [self::class, 'tagList'], true);
        self::register('list-pagination', [self::class, 'tagListPagination']);
        self::register('nav', [self::class, 'tagNav'], true);
        self::register('subnav', [self::class, 'tagSubnav'], true);
        self::register('if', [self::class, 'tagIf'], true);
        self::register('field', [self::class, 'tagField']);
        self::register('channel', [self::class, 'tagChannel']);
        self::register('banner', [self::class, 'tagBanner']);
        self::register('config', [self::class, 'tagConfig']);

        if (function_exists('do_action')) {
            do_action('tag_engine_register');
        }
    }

    // ================= 内置标签实现 =================

    /**
     * {yk:list type=article|case|product cat=<slug|id> limit=6 offset=0 keyword=
     *          recommend=1 hot=1 top=1 order=<product 排序> empty="暂无内容"}
     * article/case 走 contents 表（cat 为栏目），product 走 products 表（cat 为产品分类）。
     * @psalm-suppress PossiblyUnusedReturnValue （handler 经 callable 动态调用，Psalm 看不见调用点）
     */
    public static function tagList(array $attrs, ?string $inner): string
    {
        $inner = (string) $inner;
        $query = self::listQueryContext($attrs);
        $type = $query['type'];
        $limit = $query['limit'];
        $where = $query['filters'];
        $isProduct = $query['is_product'];
        $ctxType = $isProduct ? 'product' : 'content';

        $idsAttr = trim((string) ($attrs['id'] ?? ''));
        if ($idsAttr !== '') {
            // 指定 id 列表：{yk:list id=3,7,9}（按给定顺序逐条取已发布条目）
            $items = [];
            foreach (array_filter(array_map('intval', explode(',', $idsAttr))) as $id) {
                $it = $isProduct
                    ? (function_exists('getProduct') ? getProduct($id) : null)
                    : (function_exists('getContent') ? getContent($id) : null);
                if ($it) {
                    $items[] = $it;
                }
            }
        } elseif (!empty($attrs['related'])) {
            // 相关内容：{yk:list type=product related=1}（同分类/栏目、排除本篇，需当前条目上下文）
            $cur = self::currentContext();
            $items = [];
            if ($cur !== null) {
                $curId = (int) ($cur['id'] ?? 0);
                if ($isProduct) {
                    $rows = getProducts((int) ($cur['category_id'] ?? 0), $limit + 1, 0, $where);
                } else {
                    $where['type'] = $type;
                    $rows = getContents((int) ($cur['channel_id'] ?? 0), $limit + 1, 0, $where);
                }
                $items = array_slice(
                    array_values(array_filter($rows, fn($r) => (int) ($r['id'] ?? 0) !== $curId)),
                    0,
                    $limit
                );
            }
        } elseif ($isProduct) {
            [, , $page] = self::listPageState($query);
            $effectiveOffset = $query['offset'] + (($page - 1) * $limit);
            $items = $query['valid']
                ? getProducts($query['source_id'], $limit, $effectiveOffset, $where)
                : [];
        } else {
            // article / case / 自定义模型 等内容类型统一走 contents（按 type 聚合，如 {yk:list type=team}）
            [, , $page] = self::listPageState($query);
            $effectiveOffset = $query['offset'] + (($page - 1) * $limit);
            $items = $query['valid']
                ? getContents($query['source_id'], $limit, $effectiveOffset, $where)
                : [];
        }

        if ($items === []) {
            return isset($attrs['empty']) ? e((string) $attrs['empty']) : '';
        }

        $out = '';
        foreach (array_values($items) as $i => $item) {
            $item['_type'] = $ctxType;
            $item['_index'] = $i + 1;
            self::pushContext($item);
            try {
                $out .= self::render($inner);
            } finally {
                self::popContext();
            }
        }
        return $out;
    }

    /**
     * 与 {yk:list} 使用同一查询契约输出数字分页。
     * @psalm-suppress PossiblyUnusedReturnValue （handler 经 callable 动态调用，Psalm 看不见调用点）
     * @psalm-suppress UnusedParam （$inner 是标签 handler 统一契约的一部分）
     */
    public static function tagListPagination(array $attrs, ?string $inner): string
    {
        $query = self::listQueryContext($attrs);
        if ($query['page_param'] === '' || !$query['valid']) {
            return '';
        }
        [$total, $pages, $page] = self::listPageState($query);
        if ($total <= $query['limit'] || $pages <= 1) {
            return '';
        }

        $pageSet = [1, $pages];
        for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++) {
            $pageSet[] = $i;
        }
        $pageSet = array_values(array_unique($pageSet));
        sort($pageSet);

        $items = [];
        if ($page > 1) {
            $items[] = self::paginationLink($query['page_param'], $page - 1, __('pager_prev'), 'prev');
        }
        $previous = 0;
        foreach ($pageSet as $number) {
            if ($previous > 0 && $number > $previous + 1) {
                $items[] = '<span class="px-2 text-gray-400" aria-hidden="true">…</span>';
            }
            if ($number === $page) {
                $items[] = '<span class="inline-flex min-w-9 items-center justify-center rounded border border-primary bg-primary px-3 py-2 text-sm text-white" aria-current="page">'
                    . $number . '</span>';
            } else {
                $items[] = self::paginationLink($query['page_param'], $number, (string) $number);
            }
            $previous = $number;
        }
        if ($page < $pages) {
            $items[] = self::paginationLink($query['page_param'], $page + 1, __('pager_next'), 'next');
        }

        return '<nav class="yk-query-pagination mt-8 flex flex-wrap items-center justify-center gap-2" aria-label="'
            . e(__('blox_dynamic_pagination_label')) . '">' . implode('', $items) . '</nav>';
    }

    /**
     * @return array{type:string,is_product:bool,source_id:int,valid:bool,limit:int,offset:int,page_param:string,filters:array<string,mixed>}
     */
    private static function listQueryContext(array $attrs): array
    {
        $type = strtolower(trim((string) ($attrs['type'] ?? 'article')));
        if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $type) !== 1) {
            $type = 'article';
        }
        $limit = max(1, min(self::MAX_LIMIT, (int) ($attrs['limit'] ?? 6)));
        $offset = max(0, min(5000, (int) ($attrs['offset'] ?? 0)));
        $cat = trim((string) ($attrs['cat'] ?? ''));
        $isProduct = $type === 'product';
        $filters = [];
        if (!empty($attrs['keyword'])) {
            $filters['keyword'] = mb_substr((string) $attrs['keyword'], 0, 200);
        }
        foreach (['recommend' => 'is_recommend', 'hot' => 'is_hot', 'top' => 'is_top'] as $attr => $flag) {
            if (!empty($attrs[$attr])) {
                $filters[$flag] = 1;
            }
        }
        if ($isProduct && !empty($attrs['order']) && class_exists('ProductModel')
            && isset(ProductModel::SORT_MAP[(string) $attrs['order']])) {
            $filters['sort'] = (string) $attrs['order'];
        }
        if (!$isProduct) {
            $filters['type'] = $type;
        }

        $sourceId = 0;
        $valid = true;
        if ($cat !== '') {
            if (ctype_digit($cat)) {
                $sourceId = (int) $cat;
            } elseif ($isProduct) {
                $row = function_exists('getProductCategoryBySlug') ? getProductCategoryBySlug($cat) : null;
                $sourceId = $row ? (int) $row['id'] : -1;
                $valid = $sourceId >= 0;
            } else {
                $row = getChannelBySlug($cat);
                $sourceId = $row ? (int) $row['id'] : -1;
                $valid = $sourceId >= 0;
            }
        }

        $pageParam = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($attrs['page_param'] ?? '')) ?? '';
        if (strlen($pageParam) > 40) {
            $pageParam = '';
        }

        return [
            'type' => $type,
            'is_product' => $isProduct,
            'source_id' => max(0, $sourceId),
            'valid' => $valid,
            'limit' => $limit,
            'offset' => $offset,
            'page_param' => $pageParam,
            'filters' => $filters,
        ];
    }

    /** @param array{type:string,is_product:bool,source_id:int,valid:bool,limit:int,offset:int,page_param:string,filters:array<string,mixed>} $query @return array{int,int,int} */
    private static function listPageState(array $query): array
    {
        if ($query['page_param'] === '' || !$query['valid']) {
            return [0, 1, 1];
        }
        $count = $query['is_product']
            ? productModel()->getCount($query['source_id'], $query['filters'])
            : contentModel()->getCount($query['source_id'], $query['filters']);
        $total = max(0, $count - $query['offset']);
        $pages = max(1, (int) ceil($total / $query['limit']));
        $rawPage = $_GET[$query['page_param']] ?? 1;
        $requestedPage = is_scalar($rawPage) ? (int) $rawPage : 1;
        $page = max(1, min($pages, $requestedPage));
        return [$total, $pages, $page];
    }

    private static function paginationLink(string $param, int $page, string $label, string $rel = ''): string
    {
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($requestUri, PHP_URL_PATH) ?: '/');
        $query = [];
        parse_str((string) (parse_url($requestUri, PHP_URL_QUERY) ?? ''), $query);
        if ($page <= 1) {
            unset($query[$param]);
        } else {
            $query[$param] = $page;
        }
        $url = $path . ($query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $relAttr = $rel !== '' ? ' rel="' . $rel . '"' : '';
        return '<a class="inline-flex min-w-9 items-center justify-center rounded border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 no-underline hover:border-primary hover:text-primary" href="'
            . e($url) . '"' . $relAttr . '>' . e($label) . '</a>';
    }

    /**
     * {yk:nav parent=0 nav_only=1}
     *   <li><a href="{yk:field name=url /}">{yk:field name=name /}</a></li>
     * {/yk:nav}
     * 遍历栏目做导航菜单。parent 为父栏目 id 或 slug（0/空=顶级）；nav_only=0 显示全部栏目。
     * 条目字段：name / slug / id …，虚拟字段 url（栏目链接）、_index。
     * @psalm-suppress PossiblyUnusedReturnValue （handler 经 callable 动态调用，Psalm 看不见调用点）
     */
    public static function tagNav(array $attrs, ?string $inner): string
    {
        $inner = (string) $inner;
        $parentRaw = trim((string) ($attrs['parent'] ?? '0'));
        $navOnly = ($attrs['nav_only'] ?? '1') !== '0';
        $limit = max(0, min(self::MAX_LIMIT, (int) ($attrs['limit'] ?? 0)));

        $parentId = 0;
        if ($parentRaw !== '' && $parentRaw !== '0') {
            if (ctype_digit($parentRaw)) {
                $parentId = (int) $parentRaw;
            } else {
                $ch = getChannelBySlug($parentRaw);
                $parentId = $ch ? (int) $ch['id'] : -1; // slug 未命中 → 空结果
            }
        }
        if ($parentId < 0) {
            return isset($attrs['empty']) ? e((string) $attrs['empty']) : '';
        }

        $items = self::filterNavLang(getChannels($parentId, $navOnly));
        if ($limit > 0) {
            $items = array_slice($items, 0, $limit);
        }
        if ($items === []) {
            return isset($attrs['empty']) ? e((string) $attrs['empty']) : '';
        }

        // 多级下拉：内层模板用到 {yk:subnav} 或 has_children 字段时才预取子级
        // （普通单层导航零额外查询，输出不变）。预取结果塞 _children 供 {yk:subnav} 直接吃。
        $needChildren = strpos($inner, '{yk:subnav') !== false || strpos($inner, 'has_children') !== false;

        $out = '';
        foreach (array_values($items) as $i => $item) {
            $item['_type'] = 'channel';
            $item['_index'] = $i + 1;
            if ($needChildren) {
                $kids = self::filterNavLang(getChannels((int) ($item['id'] ?? 0), $navOnly));
                $item['has_children'] = $kids === [] ? 0 : 1;
                $item['_children'] = $kids;
            }
            self::pushContext($item);
            try {
                $out .= self::render($inner);
            } finally {
                self::popContext();
            }
        }
        return $out;
    }

    /**
     * {yk:subnav}...{/yk:subnav} — 当前循环栏目的子栏目循环（配合 {yk:nav} 做多级下拉菜单）。
     * 块标签同名不可自嵌套（渲染正则约束），故子级循环用独立标签而非 {yk:nav} 套 {yk:nav}。
     * 仅在栏目上下文（{yk:nav} 循环内）有效；attrs：
     *   nav_only（默认 1）、limit、empty、
     *   wrap=ul class="..."（非空时用该标签+class 包裹输出；无子级则连包裹一起省略，
     *   悬停下拉不会出现空面板）。
     * 搭配 {yk:if field=has_children op=eq value=1} 可条件渲染下拉箭头。
     * @psalm-suppress PossiblyUnusedReturnValue （handler 经 callable 动态调用）
     */
    public static function tagSubnav(array $attrs, ?string $inner): string
    {
        $inner = (string) $inner;
        $ctx = self::currentContext();
        $parentId = (int) ($ctx['id'] ?? 0);
        if ($ctx === null || $parentId <= 0 || ($ctx['_type'] ?? '') !== 'channel') {
            return isset($attrs['empty']) ? e((string) $attrs['empty']) : '';
        }
        $navOnly = ($attrs['nav_only'] ?? '1') !== '0';
        $limit = max(0, min(self::MAX_LIMIT, (int) ($attrs['limit'] ?? 0)));

        // {yk:nav} 预取过且未显式改 nav_only 时直接吃缓存（预取按 nav 的 nav_only 算），
        // 否则自己查（显式覆盖 nav_only 的场景）
        $items = (!isset($attrs['nav_only']) && is_array($ctx['_children'] ?? null))
            ? $ctx['_children']
            : self::filterNavLang(getChannels($parentId, $navOnly));
        if ($limit > 0) {
            $items = array_slice($items, 0, $limit);
        }
        if ($items === []) {
            return isset($attrs['empty']) ? e((string) $attrs['empty']) : '';
        }

        $out = '';
        foreach (array_values($items) as $i => $item) {
            $item['_type'] = 'channel';
            $item['_index'] = $i + 1;
            self::pushContext($item);
            try {
                $out .= self::render($inner);
            } finally {
                self::popContext();
            }
        }

        $wrap = strtolower(trim((string) ($attrs['wrap'] ?? '')));
        if ($wrap !== '' && preg_match('/^[a-z][a-z0-9]*$/', $wrap) === 1) {
            $cls = trim((string) ($attrs['class'] ?? ''));
            return '<' . $wrap . ($cls !== '' ? ' class="' . e($cls) . '"' : '') . '>' . $out . '</' . $wrap . '>';
        }
        return $out;
    }

    /** 多语言站点：只保留当前语言的栏目，避免菜单里混语言（{yk:nav}/{yk:subnav} 共用） */
    private static function filterNavLang(array $items): array
    {
        if (function_exists('isMultiLangEnabled') && isMultiLangEnabled('channels')) {
            $lang = function_exists('siteLang') ? siteLang() : '';
            $items = array_values(array_filter($items, fn($c) => ($c['lang'] ?? $lang) === $lang));
        }
        return $items;
    }

    /**
     * {yk:if field=is_hot op=eq value=1}...{yk:else/}...{/yk:if}
     * 条件渲染。左值：field=当前条目字段（含虚拟/扩展字段）或 config=设置项。
     * op：eq/ne/gt/gte/lt/lte/contains/in/empty/notempty；缺省 op = 给了 value 用 eq，否则 notempty。
     * {yk:else/} 可选，分隔真/假分支。同名不可自嵌套（与其它块标签一致）。
     * @psalm-suppress PossiblyUnusedReturnValue （handler 经 callable 动态调用）
     */
    public static function tagIf(array $attrs, ?string $inner): string
    {
        $inner = (string) $inner;
        $parts = preg_split('~\{yk:else\s*/?\}~i', $inner, 2) ?: [$inner];
        $branch = self::evalCondition($attrs) ? ($parts[0] ?? '') : ($parts[1] ?? '');
        return self::render($branch); // 递归渲染选中分支内的其它标签
    }

    /** 计算 {yk:if} 条件。 */
    private static function evalCondition(array $attrs): bool
    {
        if (isset($attrs['config'])) {
            $actual = function_exists('config') ? config((string) $attrs['config'], '') : '';
        } else {
            $name = strtolower(trim((string) ($attrs['field'] ?? '')));
            $ctx = self::currentContext();
            $actual = ($name !== '' && $ctx !== null)
                ? ($ctx[$name] ?? self::metaFallback($ctx, $name))
                : null;
        }
        $op = isset($attrs['op'])
            ? strtolower((string) $attrs['op'])
            : (array_key_exists('value', $attrs) ? 'eq' : 'notempty');
        $expected = (string) ($attrs['value'] ?? '');
        $a = is_scalar($actual) ? (string) $actual : '';
        $blank = ($actual === null || $a === '' || $a === '0');

        return match ($op) {
            'empty'    => $blank,
            'notempty' => !$blank,
            'eq'       => $a === $expected,
            'ne'       => $a !== $expected,
            'gt'       => is_numeric($a) && (float) $a >  (float) $expected,
            'gte'      => is_numeric($a) && (float) $a >= (float) $expected,
            'lt'       => is_numeric($a) && (float) $a <  (float) $expected,
            'lte'      => is_numeric($a) && (float) $a <= (float) $expected,
            'contains' => $expected !== '' && mb_strpos($a, $expected) !== false,
            'in'       => in_array($a, array_map('trim', explode(',', $expected)), true),
            default    => false,
        };
    }

    /**
     * {yk:field name=title default="" len=80 dateformat="Y-m-d" esc=1 /}
     * 从当前 {yk:list} 条目取字段。虚拟字段：url（详情页链接）、date（发布时间）。
     * @psalm-suppress UnusedParam （$inner 是标签 handler 统一契约的一部分）
     * @psalm-suppress PossiblyUnusedReturnValue （handler 经 callable 动态调用，Psalm 看不见调用点）
     */
    public static function tagField(array $attrs, ?string $inner): string
    {
        $ctx = self::currentContext();
        $name = strtolower(trim((string) ($attrs['name'] ?? '')));
        if ($ctx === null || $name === '') {
            return '';
        }
        $default = (string) ($attrs['default'] ?? '');
        if (isset($attrs['fallback'])) {
            $default = mb_substr(rawurldecode((string) $attrs['fallback']), 0, 200);
        }

        // 虚拟字段：url（按当前循环条目的来源类型选对应链接生成器）
        if ($name === 'url') {
            $type = $ctx['_type'] ?? '';
            $url = match ($type) {
                'product' => productUrl($ctx),
                'channel' => channelUrl($ctx),
                default   => contentUrl($ctx),
            };
            return e($url !== '' ? $url : $default);
        }

        // 虚拟字段：date（publish_time 优先，回退 created_at）
        if ($name === 'date') {
            $rawTime = $ctx['publish_time'] ?? $ctx['created_at'] ?? '';
            $date = self::formatDate($rawTime, (string) ($attrs['dateformat'] ?? 'Y-m-d'));
            return e($date !== '' ? $date : $default);
        }

        // 原生列优先；不是原生列时回退查扩展字段（metas），支持自定义模型字段
        $value = $ctx[$name] ?? null;
        if ($value === null) {
            $value = self::metaFallback($ctx, $name);
        }
        if ($value === null || (is_scalar($value) && trim((string) $value) === '')) {
            $value = $default;
        }
        if (!is_scalar($value)) {
            return '';
        }
        $value = (string) $value;

        // 时间戳类字段按 dateformat 输出
        if (isset($attrs['dateformat']) && in_array($name, ['publish_time', 'created_at', 'updated_at'], true)) {
            return e(self::formatDate($value, (string) $attrs['dateformat']));
        }

        // len 截断（先剥标签再按多字节截断）
        if (!empty($attrs['len'])) {
            $value = trim(strip_tags($value));
            $len = max(1, (int) $attrs['len']);
            if (mb_strlen($value) > $len) {
                $value = mb_substr($value, 0, $len) . '…';
            }
        }

        $esc = ($attrs['esc'] ?? '1') !== '0';
        return $esc ? e($value) : $value;
    }

    /**
     * {yk:channel id=8 field=name /} / {yk:channel slug=about field=url /}
     * @psalm-suppress UnusedParam （$inner 是标签 handler 统一契约的一部分）
     * @psalm-suppress PossiblyUnusedReturnValue （handler 经 callable 动态调用，Psalm 看不见调用点）
     */
    public static function tagChannel(array $attrs, ?string $inner): string
    {
        $channel = null;
        if (!empty($attrs['id'])) {
            $channel = getChannel((int) $attrs['id']);
        } elseif (!empty($attrs['slug'])) {
            $channel = getChannelBySlug((string) $attrs['slug']);
        }
        if (!$channel) {
            return '';
        }
        $field = strtolower((string) ($attrs['field'] ?? 'name'));
        if ($field === 'url') {
            return e(channelUrl($channel));
        }
        $value = $channel[$field] ?? '';
        return is_scalar($value) ? e((string) $value) : '';
    }

    /**
     * {yk:banner group=home /} — 复用轮播图短码渲染器，输出整段轮播 HTML
     * @psalm-suppress UnusedParam （$inner 是标签 handler 统一契约的一部分）
     * @psalm-suppress PossiblyUnusedReturnValue （handler 经 callable 动态调用，Psalm 看不见调用点）
     */
    public static function tagBanner(array $attrs, ?string $inner): string
    {
        $group = trim((string) ($attrs['group'] ?? $attrs['slug'] ?? ''));
        if ($group === '' || !function_exists('renderBannerShortcode')) {
            return '';
        }
        return renderBannerShortcode($group);
    }

    /**
     * {yk:config name=site_name /} — 仅放行展示类设置（白名单），防止把 SMTP 密码之类拼进页面。
     * @psalm-suppress UnusedParam （$inner 是标签 handler 统一契约的一部分）
     * @psalm-suppress PossiblyUnusedReturnValue （handler 经 callable 动态调用，Psalm 看不见调用点）
     */
    public static function tagConfig(array $attrs, ?string $inner): string
    {
        return e(self::configValue(
            (string) ($attrs['name'] ?? ''),
            (string) ($attrs['default'] ?? '')
        ));
    }

    /** 返回可公开展示的站点设置原值，供构建器的可视化字段绑定复用。 */
    public static function configValue(string $name, string $default = ''): string
    {
        static $allowed = [
            'site_name', 'site_url', 'site_logo', 'site_keywords', 'site_description',
            'contact_phone', 'contact_email', 'contact_address', 'contact_qq', 'contact_wechat',
            'icp_number', 'police_number', 'copyright',
        ];
        $name = strtolower(trim($name));
        if ($name === '' || !in_array($name, $allowed, true)) {
            return '';
        }
        $value = config($name, $default);
        return is_scalar($value) ? (string) $value : $default;
    }

    private static function formatDate(mixed $raw, string $format): string
    {
        if ($raw === '' || $raw === null) {
            return '';
        }
        $ts = is_numeric($raw) ? (int) $raw : strtotime((string) $raw);
        if ($ts === false || $ts <= 0) {
            return '';
        }
        return date($format, $ts);
    }

    /**
     * {yk:field} 取不到原生列时，回退查扩展字段值（metas）。
     * owner_type 与保存端一致（product / 自定义模型 key / content）；
     * getMeta / resolveExtFieldOwner 缺失（如纯单测环境）时安全跳过。
     */
    private static function metaFallback(array $ctx, string $name): mixed
    {
        $ctxType = (string) ($ctx['_type'] ?? '');
        if ($ctxType === 'channel' || empty($ctx['id']) || !function_exists('getMeta')) {
            return null;
        }
        if ($ctxType === 'product') {
            $owner = 'product';
        } elseif (function_exists('resolveExtFieldOwner')) {
            $owner = resolveExtFieldOwner((string) ($ctx['type'] ?? ''));
        } else {
            $owner = 'content';
        }
        return getMeta($owner, (int) $ctx['id'], $name, null);
    }
}
