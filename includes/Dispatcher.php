<?php
/**
 * Yikai CMS - 入站分发器（WordPress 式单入口路由）
 *
 * 把 .htaccess 的伪静态映射镜像为 PHP 路由表，使站点在「仅有 WP 同款两行
 * catch-all 规则」的主机上也能完整运行——主机面板选『WordPress 伪静态』预设即可：
 *
 *   RewriteCond %{REQUEST_FILENAME} !-f
 *   RewriteCond %{REQUEST_FILENAME} !-d
 *   RewriteRule . /index.php [L]
 *
 * 由 index.php 在非首页路径时调用 run()：命中 → 注入 $_GET 并 require 目标入口后 exit；
 * 未命中 → 主题化 404（顺带修掉了旧行为里「任意乱路径都渲染首页」的软 404）。
 *
 * ⚠ 路由表必须与 .htaccess 伪静态段保持同序同义——完整规则的主机走服务器层映射
 *   （性能最优，本类不参与）；受限主机走本类。两边行为需一致。
 *
 * match() 为纯函数，供单元测试；类加载不依赖 CMS 运行时。
 */

declare(strict_types=1);

final class Dispatcher
{
    /**
     * 路由表：[正则, 目标文件, 参数名映射（按捕获组顺序）, 固定参数]
     * 顺序即优先级，镜像 .htaccess（具体规则在前、通用规则在后）。
     * @var array<int, array{0:string, 1:string, 2:array<int,string>, 3:array<string,string>}>
     */
    private const ROUTES = [
        ['#^sitemap\.xml$#',                                'sitemap.php',      [],                 []],
        ['#^search\.html$#',                                'search.php',       [],                 []],
        // 新闻系统
        ['#^news\.html$#',                                  'news.php',         [],                 []],
        ['#^news/page/(\d+)\.html$#',                       'news.php',         ['page'],           []],
        ['#^news/article/(\d+)\.html$#',                    'article.php',      ['id'],             []],
        ['#^news/article/([a-z0-9_-]+)\.html$#',            'article.php',      ['slug'],           []],
        ['#^news/([a-z0-9_-]+)/page/(\d+)\.html$#',         'news.php',         ['cat', 'page'],    []],
        ['#^news/([a-z0-9_-]+)\.html$#',                    'news.php',         ['cat'],            []],
        // 栏目列表
        ['#^list/(\d+)\.html$#',                            'list.php',         ['id'],             []],
        ['#^list/(\d+)/page/(\d+)\.html$#',                 'list.php',         ['id', 'page'],     []],
        ['#^([a-z0-9_-]+)/page/(\d+)\.html$#',              'list.php',         ['slug', 'page'],   []],
        // 案例 / 招聘 / 下载 / 详情
        ['#^case/(\d+)\.html$#',                            'detail.php',       ['id'],             []],
        ['#^case/([a-z0-9_-]+)\.html$#',                    'detail.php',       ['slug'],           []],
        ['#^job/(\d+)\.html$#',                             'job_detail.php',   ['id'],             []],
        ['#^download/detail/(\d+)\.html$#',                 'detail.php',       ['id'],             []],
        ['#^download/([a-z0-9_-]+)/page/(\d+)\.html$#',     'list.php',         ['cat', 'page'],    ['slug' => 'download']],
        ['#^download/([a-z0-9_-]+)\.html$#',                'list.php',         ['cat'],            ['slug' => 'download']],
        ['#^detail/(\d+)\.html$#',                          'detail.php',       ['id'],             []],
        // 产品（数字 ID → 双段 slug → 分类分页 → 分类）
        ['#^product/(\d+)\.html$#',                         'product.php',      ['id'],             []],
        ['#^product/([a-z0-9_-]+)/([a-z0-9_-]+)\.html$#',   'product.php',      ['_catslug', 'slug'], []],
        ['#^product/([a-z0-9_-]+)/page/(\d+)\.html$#',      'list.php',         ['cat', 'page'],    ['slug' => 'product']],
        ['#^product/([a-z0-9_-]+)\.html$#',                 'list.php',         ['cat'],            ['slug' => 'product']],
        // 特殊单页
        ['#^about/history\.html$#',                         'history.php',      [],                 []],
        ['#^contact\.html$#',                               'contact.php',      [],                 []],
        // 通用单页（多级 slug）
        ['#^page/(\d+)\.html$#',                            'page.php',         ['id'],             []],
        ['#^([a-z0-9_-]+)/([a-z0-9_-]+)\.html$#',           'page.php',         ['parent', 'slug'], []],
        ['#^([a-z0-9_-]+)\.html$#',                         'page.php',         ['slug'],           []],
        // 开放接口
        ['#^api/v1/([a-z_]+)/?$#',                          'api/v1/index.php', ['resource'],       []],
    ];

    /** 支持的多语言 URL 前缀（与 .htaccess 同步） */
    private const LANG_PREFIXES = ['ja', 'en', 'zh-CN', 'zh-TW'];

    /**
     * 从原始请求路径识别语言前缀，供 index.php 在加载 init.php 前初始化语言。
     */
    public static function languagePrefixFromPath(string $path): ?string
    {
        $path = ltrim((string) parse_url($path, PHP_URL_PATH), '/');
        foreach (self::LANG_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return $prefix;
            }
        }
        return null;
    }

    /**
     * 纯匹配：路径（不带开头 / 与查询串）→ ['file'=>目标, 'params'=>[...], 'lang'=>?string]。
     * 返回 null = 未命中；'file' 为 '' 且 lang 非空 = 语言前缀首页（如 /ja/）。
     *
     * $routes 为 null 时用内置表。run() 会传入经 dispatch_routes 过滤后的表；
     * 保留参数化是为了让本函数维持纯函数（不碰 CMS 运行时），单元测试可直接调。
     *
     * @param array<int, array{0:string, 1:string, 2:array<int,string>, 3:array<string,string>}>|null $routes
     */
    public static function match(string $path, ?array $routes = null): ?array
    {
        $path = ltrim($path, '/');
        // 语言前缀剥离：/ja/xxx → lang=ja + xxx
        $lang = self::languagePrefixFromPath('/' . $path);
        if ($lang !== null) {
            $path = $path === $lang ? '' : substr($path, strlen($lang) + 1);
        }
        if ($path === '') {
            return $lang !== null ? ['file' => '', 'params' => [], 'lang' => $lang] : null;
        }

        foreach (($routes ?? self::ROUTES) as [$re, $file, $names, $fixed]) {
            if (preg_match($re, $path, $m)) {
                $params = $fixed;
                foreach ($names as $i => $name) {
                    $params[$name] = $m[$i + 1] ?? '';
                }
                unset($params['_catslug']);   // 产品双段 slug 的分类段仅用于匹配
                return ['file' => $file, 'params' => $params, 'lang' => $lang];
            }
        }
        return null;
    }

    /**
     * 解析未配置 rewrite 时的 index.php 查询入口。
     *
     * 这不是第二套路由系统：返回值与 match() 保持同一形状，最终仍由现有页面入口渲染。
     * 只接受文档化的 yk_route 白名单，避免把任意 GET 参数变成文件或 SQL 入口。
     *
     * @param array<string,mixed> $query
     * @return array{file:string, params:array<string,string>, lang:?string, canonical:string}|null
     */
    public static function dynamicQuery(array $query): ?array
    {
        $route = self::queryScalar($query, 'yk_route');
        if ($route === null || !in_array($route, [
            'home', 'search', 'news', 'article', 'list', 'page', 'product',
            'product_list', 'case', 'download', 'download_list', 'job',
            'contact', 'history',
        ], true)) {
            return null;
        }

        $lang = self::queryLanguage($query);
        if (array_key_exists('lang', $query)
            && (self::queryScalar($query, 'lang') === null || $lang === null)) {
            return null;
        }

        $prefix = $lang === null ? '' : '/' . $lang;
        $page = self::positiveQuery($query, 'page');
        if (array_key_exists('page', $query) && $page === null) {
            return null;
        }

        if ($route === 'home') {
            return ['file' => '', 'params' => [], 'lang' => $lang, 'canonical' => $prefix . '/'];
        }
        if ($route === 'contact') {
            return ['file' => 'contact.php', 'params' => [], 'lang' => $lang, 'canonical' => $prefix . '/contact.html'];
        }
        if ($route === 'history') {
            return ['file' => 'history.php', 'params' => [], 'lang' => $lang, 'canonical' => $prefix . '/about/history.html'];
        }
        if ($route === 'search') {
            $keyword = self::queryScalar($query, 'keyword') ?? '';
            $type = self::queryScalar($query, 'type') ?? 'all';
            if ((array_key_exists('keyword', $query) && self::queryScalar($query, 'keyword') === null)
                || (array_key_exists('type', $query) && self::queryScalar($query, 'type') === null)) {
                return null;
            }
            if (!in_array($type, ['all', 'article', 'product', 'case', 'download'], true)) {
                return null;
            }
            $params = ['keyword' => $keyword, 'type' => $type];
            if ($page !== null) $params['page'] = $page;
            return ['file' => 'search.php', 'params' => $params, 'lang' => $lang, 'canonical' => $prefix . '/search.html'];
        }

        if ($route === 'news') {
            $cat = self::slugQuery($query, 'cat');
            if (array_key_exists('cat', $query) && $cat === null) return null;
            $params = [];
            $canonical = $prefix . '/news.html';
            if ($cat !== null) {
                $params['cat'] = $cat;
                $canonical = $prefix . '/news/' . $cat . '.html';
            }
            if ($page !== null) {
                $params['page'] = $page;
                $canonical = $prefix . ($cat !== null ? '/news/' . $cat : '/news') . '/page/' . $page . '.html';
            }
            return ['file' => 'news.php', 'params' => $params, 'lang' => $lang, 'canonical' => $canonical];
        }

        if ($route === 'article') {
            $identity = self::identityQuery($query);
            if ($identity === null) return null;
            return [
                'file' => 'article.php',
                'params' => $identity['params'],
                'lang' => $lang,
                'canonical' => $prefix . '/news/article/' . $identity['value'] . '.html',
            ];
        }

        if ($route === 'list') {
            $identity = self::identityQuery($query);
            if ($identity === null) return null;
            $params = $identity['params'];
            $canonical = $identity['kind'] === 'id'
                ? $prefix . '/list/' . $identity['value'] . '.html'
                : $prefix . '/' . $identity['value'] . '.html';
            if ($page !== null) {
                $params['page'] = $page;
                $canonical = $identity['kind'] === 'id'
                    ? $prefix . '/list/' . $identity['value'] . '/page/' . $page . '.html'
                    : $prefix . '/' . $identity['value'] . '/page/' . $page . '.html';
            }
            return ['file' => 'list.php', 'params' => $params, 'lang' => $lang, 'canonical' => $canonical];
        }

        if ($route === 'page') {
            $id = self::positiveQuery($query, 'id');
            $slug = self::slugQuery($query, 'slug');
            $parent = self::slugQuery($query, 'parent');
            if (array_key_exists('id', $query) && $id === null) return null;
            if (array_key_exists('slug', $query) && $slug === null) return null;
            if ($id === null && $slug === null) return null;
            if ($id !== null && $slug !== null) return null;
            if ($parent !== null && $id !== null) return null;
            if (array_key_exists('parent', $query) && $parent === null) return null;
            $params = $id !== null ? ['id' => $id] : ['slug' => $slug];
            $canonical = $id !== null ? $prefix . '/page/' . $id . '.html' : $prefix . '/';
            if ($id === null && $parent !== null) {
                $params['parent'] = $parent;
                $canonical = $prefix . '/' . $parent . '/' . $slug . '.html';
            } elseif ($id === null) {
                $canonical = $prefix . '/' . $slug . '.html';
            }
            return ['file' => 'page.php', 'params' => $params, 'lang' => $lang, 'canonical' => $canonical];
        }

        if ($route === 'product_list' || $route === 'download_list') {
            $cat = self::slugQuery($query, 'cat');
            if ($cat === null && array_key_exists('cat', $query)) return null;
            $fixedSlug = $route === 'product_list' ? 'product' : 'download';
            $base = $prefix . '/' . $fixedSlug;
            $params = ['slug' => $fixedSlug];
            if ($cat !== null) {
                $params['cat'] = $cat;
                $base .= '/' . $cat;
            }
            if ($page !== null) {
                $params['page'] = $page;
                $base .= '/page/' . $page;
            }
            return ['file' => 'list.php', 'params' => $params, 'lang' => $lang, 'canonical' => $base . '.html'];
        }

        if ($route === 'product') {
            $identity = self::identityQuery($query);
            if ($identity === null) return null;
            return ['file' => 'product.php', 'params' => $identity['params'], 'lang' => $lang,
                'canonical' => $prefix . '/product/' . $identity['value'] . '.html'];
        }

        if ($route === 'case' || $route === 'download') {
            $identity = self::identityQuery($query);
            if ($identity === null) return null;
            $base = $route === 'case' ? '/case/' : '/download/detail/';
            return ['file' => 'detail.php', 'params' => $identity['params'], 'lang' => $lang,
                'canonical' => $prefix . $base . $identity['value'] . '.html'];
        }

        if ($route === 'job') {
            $id = self::positiveQuery($query, 'id');
            if ($id === null) return null;
            return ['file' => 'job_detail.php', 'params' => ['id' => $id], 'lang' => $lang,
                'canonical' => $prefix . '/job/' . $id . '.html'];
        }

        return null;
    }

    /** @param array<string,mixed> $query */
    public static function dynamicLanguage(array $query): ?string
    {
        if (!array_key_exists('yk_route', $query)) return null;
        return self::queryLanguage($query);
    }

    /** @param array{file:string, params:array<string,string>, lang:?string, canonical:string} $hit */
    public static function runDynamic(array $hit): void
    {
        if ($hit['lang'] !== null) {
            $_GET['_lang'] = $hit['lang'];
            $_REQUEST['_lang'] = $hit['lang'];
        }
        $_SERVER['YK_CANONICAL_PATH'] = $hit['canonical'];
        foreach ($hit['params'] as $key => $value) {
            $_GET[$key] = $value;
            $_REQUEST[$key] = $value;
        }
        if ($hit['file'] === '') return;
        require ROOT_PATH . '/' . $hit['file'];
        exit;
    }

    /** @param array<string,mixed> $query */
    private static function queryScalar(array $query, string $key): ?string
    {
        if (!array_key_exists($key, $query)) return null;
        return is_string($query[$key]) ? trim($query[$key]) : null;
    }

    /** @param array<string,mixed> $query */
    private static function queryLanguage(array $query): ?string
    {
        $lang = self::queryScalar($query, 'lang');
        return $lang !== null && in_array($lang, self::LANG_PREFIXES, true) ? $lang : null;
    }

    /** @param array<string,mixed> $query */
    private static function positiveQuery(array $query, string $key): ?string
    {
        $value = self::queryScalar($query, $key);
        return $value !== null && preg_match('/^[1-9]\d*$/', $value) === 1 ? $value : null;
    }

    /** @param array<string,mixed> $query */
    private static function slugQuery(array $query, string $key): ?string
    {
        $value = self::queryScalar($query, $key);
        return $value !== null && preg_match('/^[a-z0-9_-]+$/', $value) === 1 ? $value : null;
    }

    /** @param array<string,mixed> $query */
    private static function identityQuery(array $query): ?array
    {
        $id = self::positiveQuery($query, 'id');
        $slug = self::slugQuery($query, 'slug');
        if (($id !== null) === ($slug !== null)) return null;
        if (array_key_exists('id', $query) && $id === null) return null;
        if (array_key_exists('slug', $query) && $slug === null) return null;
        return $id !== null
            ? ['kind' => 'id', 'value' => $id, 'params' => ['id' => $id]]
            : ['kind' => 'slug', 'value' => $slug, 'params' => ['slug' => $slug]];
    }

    /**
     * 分发当前请求。命中 → require 目标入口并 exit；语言前缀首页 → 设 lang 后返回（继续走首页）；
     * 未命中 → 主题化 404 并 exit。仅应在「路径非 / 且非 /index.php」时调用。
     */
    public static function run(): void
    {
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        // 静态 HTML 直出兜底（完整规则主机由服务器层直出，不经 PHP；此处服务受限主机）。
        // 已登录管理员跳过：静态文件是匿名抓取生成的，不含前台管理条与就地编辑，
        // 内容改完也不会立刻变。这里能直接读会话，比服务器层的 cookie 判断更准。
        $__isAdmin = !empty($_SESSION['admin_id']);
        if (!$__isAdmin
            && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
            && (string) ($_SERVER['QUERY_STRING'] ?? '') === '') {
            $static = ROOT_PATH . '/html' . $path;
            if (is_file($static) && str_starts_with((string) realpath($static), (string) realpath(ROOT_PATH . '/html'))) {
                header('Content-Type: text/html; charset=utf-8');
                readfile($static);
                exit;
            }
        }

        // 站点/插件可在此增删路由——旧站迁移过来的历史 URL（如 ShopEx 的
        // /cat-73.html、/brand-5.html）在只配了 catch-all 伪静态的主机上没有
        // 服务器层 rewrite 可用，只能由这里兜住。写法同内置表：
        // [正则, 目标入口文件, 捕获组参数名, 固定参数]。
        //
        // ⚠ 自定义规则必须**放在返回数组的前面**：内置表末尾是
        //   `([a-z0-9_-]+)\.html → page.php` 这类通配规则，会吃掉排在它后面的一切，
        //   追加到末尾的规则永远匹配不到。即
        //       add_filter('dispatch_routes', fn($r) => array_merge($mine, $r));
        //   代价是自定义规则优先级最高，正则务必写窄（锚定 ^…$、限定前缀），
        //   否则会误伤核心路由。
        //
        // 挂在 overrides/bootstrap.php 里即可，升级不冲突。见 overrides/README.md。
        /** @var array<int, array{0:string, 1:string, 2:array<int,string>, 3:array<string,string>}> $routes */
        $routes = apply_filters('dispatch_routes', self::ROUTES);

        $hit = self::match($path, $routes);
        if ($hit === null) {
            render404();
        }
        if ($hit['lang'] !== null) {
            $_GET['_lang'] = $hit['lang'];
            $_REQUEST['_lang'] = $hit['lang'];
        }
        if ($hit['file'] === '') {
            return;   // 语言前缀首页：交还 index.php 继续渲染
        }
        foreach ($hit['params'] as $k => $v) {
            $_GET[$k] = $v;
            $_REQUEST[$k] = $v;
        }
        require ROOT_PATH . '/' . $hit['file'];
        exit;
    }
}
