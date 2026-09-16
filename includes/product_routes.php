<?php
declare(strict_types=1);

/** Runs after init even when Apache/Nginx dispatched a generic .html path to page.php. */
function dispatchCustomProductRoute(?array $hit): void
{
    if ($hit === null || defined('YK_CUSTOM_PRODUCT_ROUTE')) return;
    define('YK_CUSTOM_PRODUCT_ROUTE', true);
    if (!$hit['active']) render404();
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        http_response_code(405);
        exit;
    }
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $params = [];
    parse_str((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_QUERY), $params);
    $params = array_filter(array_intersect_key($params, array_flip(['keyword', 'sort', 'brand', 'tag', 'pmin', 'pmax', 'page', 'preview'])), 'is_scalar');
    if (ProductRouteModel::normalize($path) !== $hit['canonical']) {
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        header('Location: ' . $hit['canonical'] . ($query !== '' ? '?' . $query : ''), true, 301);
        exit;
    }
    // Discard route identity injected by a rewrite or a crafted query; preserve only listing filters.
    foreach (['id', 'slug', 'parent', 'cat', '_catslug', 'page', 'yk_route'] as $key) unset($_GET[$key], $_REQUEST[$key]);
    foreach ($params as $key => $value) { $_GET[$key] = $value; $_REQUEST[$key] = $value; }
    $_SERVER['YK_CANONICAL_PATH'] = $hit['canonical'];
    if ($hit['kind'] === 'product') {
        $_GET['id'] = (string) $hit['id'];
        $_REQUEST['id'] = (string) $hit['id'];
        require ROOT_PATH . '/product.php';
    } else {
        $_GET['slug'] = 'product';
        $_GET['cat'] = (string) $hit['entity']['slug'];
        $_REQUEST['slug'] = $_GET['slug'];
        $_REQUEST['cat'] = $_GET['cat'];
        if ($hit['page'] > 1) $_GET['page'] = $_REQUEST['page'] = (string) $hit['page'];
        $GLOBALS['yk_custom_product_category_id'] = $hit['id'];
        require ROOT_PATH . '/list.php';
    }
    exit;
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
