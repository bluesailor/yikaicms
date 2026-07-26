<?php
/**
 * Yikai CMS — central URL router.
 *
 * Today the live URL form is `.html` pseudo-static (see .htaccess /
 * deploy/nginx-server.conf), and URL generation has been spread across loose helpers
 * in includes/functions.php. This class consolidates the contract so:
 *
 *   1. Plugins can register their own named routes via Router::register().
 *   2. Templates can use a single Router::url(...) call instead of
 *      hand-coding paths, decoupling them from the URL format.
 *   3. We can unit-test URL generation without booting the CMS.
 *
 * Default routes mirror what the existing helpers and .htaccess produce
 * — switching the URL format later (e.g. dropping ".html") only requires
 * changing the patterns here, not every template.
 *
 * Usage:
 *
 *   Router::url('article', ['id' => 42])
 *     → /news/article/42.html
 *
 *   Router::url('channel', ['slug' => 'news'])
 *     → /news.html
 *
 *   Router::url('channel', ['id' => 5, 'page' => 3])
 *     → /list/5/page/3.html
 *
 *   Router::register('my_plugin.report', '/report/{year}.html',
 *                    fn($p) => '/report/' . (int)$p['year'] . '.html');
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

final class Router
{
    /** @var array<string, array{pattern:string, builder:callable}> */
    private static array $routes = [];

    /** Lazily-loaded default routes (initialized on first use). */
    private static bool $defaultsLoaded = false;

    /**
     * Register a named route. Use a route name like `vendor.entity` to
     * avoid colliding with built-ins.
     *
     * @param string                              $name     Unique identifier
     * @param string                              $pattern  Documentation only — the actual URL is what builder() returns. Kept so list-routes() output is self-documenting.
     * @param callable(array<string,mixed>):string $builder Receives the params array, returns a URL string starting with /
     */
    public static function register(string $name, string $pattern, callable $builder): void
    {
        // Load defaults first so user registrations win on collision
        // (otherwise loadDefaults() called from url() would overwrite
        // a custom 'home' the caller just registered).
        self::loadDefaults();
        self::$routes[$name] = ['pattern' => $pattern, 'builder' => $builder];
    }

    /**
     * Generate a URL for the named route.
     *
     * @param string                $name
     * @param array<string,mixed>   $params
     */
    public static function url(string $name, array $params = []): string
    {
        self::loadDefaults();

        if (!isset(self::$routes[$name])) {
            throw new \InvalidArgumentException("Unknown route: {$name}");
        }
        return (string) (self::$routes[$name]['builder'])($params);
    }

    /**
     * Whether a route name has been registered.
     */
    public static function has(string $name): bool
    {
        self::loadDefaults();
        return isset(self::$routes[$name]);
    }

    /**
     * Returns [name => pattern, ...] for debugging / docs.
     *
     * @return array<string,string>
     */
    public static function listRoutes(): array
    {
        self::loadDefaults();
        return array_map(fn($r) => $r['pattern'], self::$routes);
    }

    /**
     * Wipe registered routes — for tests only.
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$routes = [];
        self::$defaultsLoaded = false;
    }

    // ───────────────────────────────────────────────────────────────────
    // Default routes — mirror the existing .htaccess / deploy/nginx-server.conf rules.
    // ───────────────────────────────────────────────────────────────────

    private static function loadDefaults(): void
    {
        if (self::$defaultsLoaded) return;
        self::$defaultsLoaded = true;

        // Home
        self::register('home', '/', fn($p) => '/');

        // Channel — list page. Accepts either ['slug'=>...] or ['id'=>...] plus optional 'page' and 'keyword'.
        self::register('channel', '/{slug or list/id}.html', function (array $p): string {
            $slug = (string) ($p['slug'] ?? '');
            $id   = (int)    ($p['id']   ?? 0);
            $page = (int)    ($p['page'] ?? 1);

            if ($slug !== '') {
                $base = $page > 1 ? "/{$slug}/page/{$page}.html" : "/{$slug}.html";
            } elseif ($id > 0) {
                $base = $page > 1 ? "/list/{$id}/page/{$page}.html" : "/list/{$id}.html";
            } else {
                throw new \InvalidArgumentException('channel route needs slug or id');
            }
            return self::appendQuery($base, ['keyword' => $p['keyword'] ?? null]);
        });

        // Generic content detail — mapped to /detail/{id}.html
        self::register('detail', '/detail/{id}.html', function (array $p): string {
            $id = (int) ($p['id'] ?? 0);
            if ($id <= 0) throw new \InvalidArgumentException('detail route needs id');
            return "/detail/{$id}.html";
        });

        // Article detail — under /news/article/...
        self::register('article', '/news/article/{id|slug}.html', function (array $p): string {
            if (!empty($p['slug'])) {
                return '/news/article/' . rawurlencode((string) $p['slug']) . '.html';
            }
            $id = (int) ($p['id'] ?? 0);
            if ($id <= 0) throw new \InvalidArgumentException('article route needs id or slug');
            return "/news/article/{$id}.html";
        });

        // News listing
        self::register('news', '/news.html', function (array $p): string {
            $page = (int) ($p['page'] ?? 1);
            $cat  = (string) ($p['cat'] ?? '');
            if ($cat !== '' && $page > 1) return "/news/{$cat}/page/{$page}.html";
            if ($cat !== '')              return "/news/{$cat}.html";
            if ($page > 1)                return "/news/page/{$page}.html";
            return '/news.html';
        });

        // Product detail
        self::register('product', '/product/detail/{id|slug}.html', function (array $p): string {
            if (!empty($p['slug'])) {
                return '/product/detail/' . rawurlencode((string) $p['slug']) . '.html';
            }
            $id = (int) ($p['id'] ?? 0);
            if ($id <= 0) throw new \InvalidArgumentException('product route needs id or slug');
            return "/product/detail/{$id}.html";
        });

        // Product category list (also reachable as /list/<channel-slug>?cat=...)
        self::register('product.category', '/product/{slug}.html', function (array $p): string {
            $slug = (string) ($p['slug'] ?? '');
            if ($slug === '') throw new \InvalidArgumentException('product.category needs slug');
            $page = (int) ($p['page'] ?? 1);
            return $page > 1 ? "/product/{$slug}/page/{$page}.html" : "/product/{$slug}.html";
        });

        // Job detail
        self::register('job', '/job/{id}.html', function (array $p): string {
            $id = (int) ($p['id'] ?? 0);
            if ($id <= 0) throw new \InvalidArgumentException('job route needs id');
            return "/job/{$id}.html";
        });

        // Static helpers
        self::register('search',  '/search.html',  fn($p) => self::appendQuery('/search.html',  ['keyword' => $p['keyword'] ?? null]));
        self::register('sitemap', '/sitemap.xml',  fn($p) => '/sitemap.xml');
        self::register('contact', '/contact.html', fn($p) => '/contact.html');
    }

    /**
     * Append non-empty params as a query string. Skips null/'' values.
     *
     * @param array<string,mixed> $params
     */
    private static function appendQuery(string $url, array $params): string
    {
        $kept = array_filter($params, fn($v) => $v !== null && $v !== '' && $v !== false);
        if (!$kept) return $url;
        return $url . '?' . http_build_query($kept);
    }
}

/**
 * Shorthand global helper, à la WordPress's home_url() / route() in
 * Laravel. Templates can simply do `<?= route('article', ['id' => 42]) ?>`.
 *
 * @param array<string,mixed> $params
 */
function route(string $name, array $params = []): string
{
    return Router::url($name, $params);
}
