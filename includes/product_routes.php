<?php
declare(strict_types=1);

require_once __DIR__ . '/Redirects.php';

/**
 * 登记网址（product_routes）的请求路径换算：语言域名上不带语言前缀，登记的是带前缀的写法
 * （en.example.com/foo/ ↔ 登记的 /en/foo/）。主域名或未启用语言域名时原样返回。
 */
function customRouteLookupPath(string $requestPath): string
{
    $lang = class_exists('LanguageDomains') ? LanguageDomains::currentLanguage() : null;
    if ($lang === null) return $requestPath;
    $default = (string) config('site_lang', 'zh-CN');
    return $lang === $default ? $requestPath : '/' . $lang . '/' . ltrim($requestPath, '/');
}

/** 登记网址在当前主机上的写法（customRouteLookupPath 的逆运算）。 */
function customRoutePublicPath(string $registered): string
{
    $lang = class_exists('LanguageDomains') ? LanguageDomains::currentLanguage() : null;
    if ($lang === null) return $registered;
    $prefix = '/' . $lang . '/';
    return str_starts_with($registered, $prefix) ? '/' . substr($registered, strlen($prefix)) : $registered;
}

/** 登记网址允许带着走的查询参数（列表筛选与预览），其余一律丢弃。 */
function customRouteQuery(): array
{
    $params = [];
    parse_str((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY), $params);
    return array_filter(array_intersect_key($params, array_flip(['keyword', 'sort', 'brand', 'tag', 'pmin', 'pmax', 'page', 'preview'])), 'is_scalar');
}

/**
 * Runs after init even when Apache/Nginx dispatched a generic .html path to page.php.
 * 每种登记类型交给自己的入口，只注入 id（列表类另注入页码）。
 */
function dispatchCustomProductRoute(?array $hit): void
{
    if ($hit === null || defined('YK_CUSTOM_PRODUCT_ROUTE')) return;
    define('YK_CUSTOM_PRODUCT_ROUTE', true);
    if (!$hit['active']) render404();
    // 跳转直接发往目标，不先补结尾斜杠（免得多跳一次）
    if ($hit['kind'] === 'redirect' && is_array($hit['entity'])) Redirects::send($hit['entity']);
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        http_response_code(405);
        exit;
    }
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $params = customRouteQuery();
    if (ProductRouteModel::normalize(customRouteLookupPath($path)) !== $hit['canonical']) {
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        header('Location: ' . customRoutePublicPath($hit['canonical']) . ($query !== '' ? '?' . $query : ''), true, 301);
        exit;
    }
    // Discard route identity injected by a rewrite or a crafted query; preserve only listing filters.
    foreach (['id', 'slug', 'parent', 'cat', 'cat_id', '_catslug', 'page', 'yk_route'] as $key) unset($_GET[$key], $_REQUEST[$key]);
    foreach ($params as $key => $value) { $_GET[$key] = $value; $_REQUEST[$key] = $value; }
    $_SERVER['YK_CANONICAL_PATH'] = customRoutePublicPath($hit['canonical']);
    $GLOBALS['yk_route_entity'] = ['kind' => (string) $hit['kind'], 'id' => (int) $hit['id'], 'page' => (int) $hit['page']];   // renderHreflangs 按条目找各语言网址
    $set = static function (string $key, string $value): void { $_GET[$key] = $value; $_REQUEST[$key] = $value; };
    if ($hit['page'] > 1) $set('page', (string) $hit['page']);
    $entity = (array) $hit['entity'];
    switch ($hit['kind']) {
        case 'product':
            $set('id', (string) $hit['id']);
            require ROOT_PATH . '/product.php';
            break;
        case 'category':
            $set('slug', 'product');
            $set('cat', (string) $entity['slug']);
            $GLOBALS['yk_custom_product_category_id'] = $hit['id'];
            require ROOT_PATH . '/list.php';
            break;
        case 'product_tag':
            $set('slug', 'product');
            $set('tag', (string) $hit['id']);
            require ROOT_PATH . '/list.php';
            break;
        case 'content':
            $set('id', (string) $hit['id']);
            require ROOT_PATH . (($entity['type'] ?? '') === 'article' ? '/article.php' : '/detail.php');
            break;
        case 'channel':
            $set('id', (string) $hit['id']);
            require ROOT_PATH . (in_array($entity['type'] ?? '', ['page', 'album'], true) ? '/page.php' : '/list.php');
            break;
        case 'album':
            $set('id', (string) $hit['id']);
            require ROOT_PATH . '/album.php';
            break;
        case 'content_tag':
            $set('id', (string) $hit['id']);
            require ROOT_PATH . '/tag.php';
            break;
        default:
            render404();
    }
    exit;
}

/**
 * 设了登记网址的条目从旧地址（/news/article/x.html、/about.html …）进来时 301 到登记网址。
 * 只管美化网址模式下的 GET/HEAD；从登记网址分发进来的请求、预览和兼容模式（?id=）不跳。
 */
function redirectToRegisteredUrl(string $kind, int $id): void
{
    if ($id <= 0 || defined('YK_CUSTOM_PRODUCT_ROUTE') || PHP_SAPI === 'cli' || headers_sent()) return;
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) || isDynamicUrlMode()) return;
    $params = customRouteQuery();
    if (isset($params['preview'])) return;
    $registered = productRouteModel()->pathFor($kind, $id);
    if ($registered === '') return;
    $page = (int) ($params['page'] ?? 1);
    unset($params['page']);
    $target = customRoutePublicPath($registered);
    if ($page > 1 && in_array($kind, ProductRouteModel::LISTING_KINDS, true)) $target = rtrim($target, '/') . '/page/' . $page . '/';
    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    header('Location: ' . $target . ($query !== '' ? '?' . $query : ''), true, 301);
    exit;
}

/**
 * 登记网址条目的 hreflang：只列出有登记网址的语言版本（没有的不给，免得指向 404）。
 * @param array<string,string> $paths 语言 → 登记网址
 * @param array<array-key,mixed> $enabled
 */
function customRouteHreflangs(array $paths, int $page, array $enabled, string $defaultLang): string
{
    $domains = LanguageDomains::active();
    $hrefs = [];
    foreach ($enabled as $code) {
        if (!is_string($code) || !isset($paths[$code]) || !LanguageRegistry::has($code)) continue;
        $path = pagedUrl($paths[$code], $page);
        if ($domains) {
            $prefix = '/' . $code . '/';
            $rest = str_starts_with($path, $prefix) ? substr($path, strlen($prefix) - 1) : $path;
            $hrefs[$code] = LanguageDomains::originFor($code) . LanguageDomains::pathFor($code, $rest);
        } else {
            $hrefs[$code] = siteBaseUrl() . $path;
        }
    }
    if (count($hrefs) < 2) return '';
    $out = '';
    foreach ($hrefs as $code => $href) {
        $out .= '<link rel="alternate" hreflang="' . htmlspecialchars(LanguageRegistry::hreflang($code), ENT_QUOTES) . '" href="' . htmlspecialchars($href, ENT_QUOTES) . '">' . "\n";
    }
    if (isset($hrefs[$defaultLang])) {
        $out .= '<link rel="alternate" hreflang="x-default" href="' . htmlspecialchars($hrefs[$defaultLang], ENT_QUOTES) . '">' . "\n";
    }
    return $out;
}

/** 列表第 N 页：结尾斜杠的网址用 /page/N/（WordPress 写法），.html 网址用 /page/N.html；保留查询串。 */
function pagedUrl(string $base, int $page): string
{
    if ($page <= 1) return $base;
    [$path, $query] = array_pad(explode('?', $base, 2), 2, null);
    if (str_ends_with($path, '/')) $path .= 'page/' . $page . '/';
    elseif (str_ends_with($path, '.html')) $path = substr($path, 0, -5) . '/page/' . $page . '.html';
    else $path .= '/page/' . $page . '/';
    return $path . ($query !== null && $query !== '' ? '?' . $query : '');
}

function customProductCategoryPageUrl(array $category, int $page = 1, array $params = []): string
{
    $base = productCategoryUrl($category);
    if ($page > 1) {
        if (isDynamicUrlMode()) $params['page'] = $page;
        elseif (productRouteModel()->pathFor('category', (int) ($category['id'] ?? 0)) !== '') $base = rtrim($base, '/') . '/page/' . $page . '/';
        else $base = langPrefix() . '/product/' . (string) ($category['slug'] ?? '') . '/page/' . $page . '.html';
    }
    $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    return $base . ($query !== '' ? (str_contains($base, '?') ? '&' : '?') . $query : '');
}
