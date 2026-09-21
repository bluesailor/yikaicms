<?php
declare(strict_types=1);

require_once __DIR__ . '/Dispatcher.php';
require_once __DIR__ . '/HtmlTagRewriter.php';

/** 兼容模式的输出兜底，不修改存量内容；切回漂亮地址后自然恢复。 */
final class CompatibleLinks
{
    public static function url(string $url, string $origin, string $requestPath = '/', string $root = ''): string
    {
        if ($url === '' || preg_match('/[\x00-\x20\\\\]/', $url) || $url[0] === '#') return $url;
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) return $url;
        $site = parse_url($origin);
        if (!is_array($site)) return $url;
        if (isset($parts['host']) || isset($parts['scheme'])) {
            $scheme = strtolower($parts['scheme'] ?? $site['scheme'] ?? '');
            $siteScheme = strtolower($site['scheme'] ?? '');
            if (!in_array($scheme, ['http', 'https'], true) || $scheme !== $siteScheme
                || strcasecmp($parts['host'] ?? '', $site['host'] ?? '') !== 0
                || ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)) !== ($site['port'] ?? ($siteScheme === 'https' ? 443 : 80))) return $url;
        }
        $path = $parts['path'] ?? '';
        if ($path === '') return $url;
        if ($path[0] !== '/') $path = rtrim(str_replace('\\', '/', dirname($requestPath)), '/') . '/' . $path;
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') array_pop($segments);
            elseif ($segment !== '' && $segment !== '.') $segments[] = $segment;
        }
        $path = '/' . implode('/', $segments);
        if (str_ends_with($parts['path'] ?? '', '/')) $path = rtrim($path, '/') . '/';
        // 真实 HTML 文件、静态资源和后台/插件地址属于各自应用，不接管。
        if (preg_match('#^/(?:admin|api|install|plugins|assets|uploads|themes|html)(?:/|$)#', $path)
            || ($root !== '' && is_file($root . $path))) return $url;
        $hit = Dispatcher::match($path);
        if ($hit === null) return $url;
        // sitemap 没有 ?yk_route= 形式（不在 Dispatcher::dynamicQuery 白名单内），
        // 但它本身就是真实入口：兼容模式下指向 /sitemap.php，否则该链接 404。
        if (($hit['file'] ?? '') === 'sitemap.php') return '/sitemap.php';
        $route = [
            '' => 'home', 'search.php' => 'search', 'news.php' => 'news', 'article.php' => 'article',
            'list.php' => 'list', 'page.php' => 'page', 'product.php' => 'product',
            'detail.php' => 'detail', 'job_detail.php' => 'job', 'contact.php' => 'contact', 'history.php' => 'history',
        ][$hit['file']] ?? null;
        if ($route === null) return $url;
        $query = [];
        parse_str($parts['query'] ?? '', $query);
        // 身份、语言只取路径，不允许附加查询串把链接变成另一个页面。
        foreach (['yk_route', 'id', 'slug', 'parent', 'lang', '_lang'] as $key) unset($query[$key]);
        $query = array_merge($query, $hit['params']);
        $query['yk_route'] = $route;
        if ($hit['lang'] !== null) $query['lang'] = $hit['lang'];
        if (Dispatcher::dynamicQuery($query) === null) return $url;
        return '/index.php?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    public static function html(string $html, string $origin, string $path = '/', string $root = ''): string
    {
        // <base> 连根相对地址的域名也会改变；仅处理明确写出域名的链接。
        $hasBase = stripos($html, '<base') !== false;
        $tags = new HtmlTagRewriter($html);
        while ($tags->nextTag()) {
            $tag = $tags->getTag();
            if (!in_array($tag, ['A', 'AREA', 'LINK'], true)) continue;
            if ($tags->getAttribute('download') !== null) continue;
            if ($tag === 'LINK' && !in_array(strtolower((string) $tags->getAttribute('rel')), ['canonical', 'alternate'], true)) continue;
            $href = $tags->getAttribute('href');
            if (!is_string($href) || ($hasBase && !preg_match('#^(?://|https?://)#i', $href))) continue;
            $converted = self::url($href, $origin, $path, $root);
            if ($converted !== $href) $tags->setAttribute('href', $converted);
        }
        return $tags->getUpdatedHtml();
    }

    /** @psalm-suppress PossiblyUnusedMethod Invoked by the web-only output buffer in init.php. */
    public static function output(string $html): string
    {
        // 本方法是 ob_start 回调，对 query 模式下的**每个**请求生效（includes/init.php）。
        // 输出缓冲回调里抛出的异常无法被正常处理——ErrorHandler 试图在回调内产出输出时
        // PHP 会升级为 fatal，结果是全站白屏。因此整体 fail-open：出任何差错都原样返回。
        try {
            // API、文件下载、XML 等不属于页面链接转换范围。
            foreach (headers_list() as $header) {
                if (stripos($header, 'Content-Disposition:') === 0) return $html;
                if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false) return $html;
            }
            if (stripos($html, '<html') === false) return $html;
            return self::html($html, siteBaseUrl(), (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), ROOT_PATH);
        } catch (Throwable $e) {
            error_log('[CompatibleLinks] output filter skipped: ' . $e->getMessage());
            return $html;
        }
    }
}
