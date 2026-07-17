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
        return self::$contextStack !== [] ? self::$contextStack[count(self::$contextStack) - 1] : null;
    }

    /** 注册内置标签（幂等）；随后广播 tag_engine_register 让插件扩展。 */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;

        self::register('list', [self::class, 'tagList'], true);
        self::register('nav', [self::class, 'tagNav'], true);
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
        $type = strtolower($attrs['type'] ?? 'article');
        $limit = max(1, min(self::MAX_LIMIT, (int) ($attrs['limit'] ?? 6)));
        $offset = max(0, (int) ($attrs['offset'] ?? 0));
        $cat = trim((string) ($attrs['cat'] ?? ''));

        $where = [];
        if (!empty($attrs['keyword'])) {
            $where['keyword'] = (string) $attrs['keyword'];
        }
        foreach (['recommend' => 'is_recommend', 'hot' => 'is_hot', 'top' => 'is_top'] as $attr => $flag) {
            if (!empty($attrs[$attr])) {
                $where[$flag] = 1;
            }
        }

        if ($type === 'product') {
            $categoryId = 0;
            if ($cat !== '') {
                if (ctype_digit($cat)) {
                    $categoryId = (int) $cat;
                } else {
                    $pc = function_exists('getProductCategoryBySlug') ? getProductCategoryBySlug($cat) : null;
                    $categoryId = $pc ? (int) $pc['id'] : -1; // 分类不存在 → 空结果
                }
            }
            if (!empty($attrs['order']) && class_exists('ProductModel')
                && isset(ProductModel::SORT_MAP[(string) $attrs['order']])) {
                $where['sort'] = (string) $attrs['order'];
            }
            $items = $categoryId >= 0 ? getProducts($categoryId, $limit, $offset, $where) : [];
            $ctxType = 'product';
        } else {
            // article / case / news 等内容类型统一走 contents
            $channelId = 0;
            if ($cat !== '') {
                if (ctype_digit($cat)) {
                    $channelId = (int) $cat;
                } else {
                    $ch = getChannelBySlug($cat);
                    $channelId = $ch ? (int) $ch['id'] : -1;
                }
            }
            $items = $channelId >= 0 ? getContents($channelId, $limit, $offset, $where) : [];
            $ctxType = 'content';
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

        $items = getChannels($parentId, $navOnly);
        // 多语言站点：只保留当前语言的栏目，避免菜单里混语言
        if (function_exists('isMultiLangEnabled') && isMultiLangEnabled('channels')) {
            $lang = function_exists('siteLang') ? siteLang() : '';
            $items = array_values(array_filter($items, fn($c) => ($c['lang'] ?? $lang) === $lang));
        }
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
        return $out;
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

        // 虚拟字段：url（按当前循环条目的来源类型选对应链接生成器）
        if ($name === 'url') {
            $type = $ctx['_type'] ?? '';
            $url = match ($type) {
                'product' => productUrl($ctx),
                'channel' => channelUrl($ctx),
                default   => contentUrl($ctx),
            };
            return e($url);
        }

        // 虚拟字段：date（publish_time 优先，回退 created_at）
        if ($name === 'date') {
            $rawTime = $ctx['publish_time'] ?? $ctx['created_at'] ?? '';
            return e(self::formatDate($rawTime, (string) ($attrs['dateformat'] ?? 'Y-m-d')));
        }

        $value = $ctx[$name] ?? $default;
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
        static $allowed = [
            'site_name', 'site_url', 'site_logo', 'site_keywords', 'site_description',
            'contact_phone', 'contact_email', 'contact_address', 'contact_qq', 'contact_wechat',
            'icp_number', 'police_number', 'copyright',
        ];
        $name = strtolower(trim((string) ($attrs['name'] ?? '')));
        if ($name === '' || !in_array($name, $allowed, true)) {
            return '';
        }
        $value = config($name, (string) ($attrs['default'] ?? ''));
        return is_scalar($value) ? e((string) $value) : '';
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
}
