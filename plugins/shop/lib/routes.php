<?php
/** 商城入口按站点 URL 模式生成；子目录前缀由 BasePath 在输出时统一补齐。 */

declare(strict_types=1);

/** @return array<string,array{path:string,file:string}> */
function shopFrontRoutes(): array
{
    return [
        'cart' => ['path' => '/shop/cart', 'file' => 'cart.php'],
        'api' => ['path' => '/shop/api', 'file' => 'api.php'],
        'checkout' => ['path' => '/shop/checkout', 'file' => 'checkout.php'],
        'order' => ['path' => '/shop/order', 'file' => 'order.php'],
        'pay' => ['path' => '/shop/pay', 'file' => 'pay.php'],
        'member-orders' => ['path' => '/member/shop-orders', 'file' => 'member-orders.php'],
    ];
}

/** @param array<string,int|string> $params */
function shopFrontUrl(string $route, array $params = [], ?string $mode = null): string
{
    $routes = shopFrontRoutes();
    if (!isset($routes[$route])) {
        throw new InvalidArgumentException('shop: unsupported front route');
    }
    $mode ??= function_exists('urlMode') ? urlMode() : 'pretty';
    unset($params['shop_route']);
    if ($mode === 'query') {
        $path = '/plugins/shop/front/index.php';
        $params = ['shop_route' => $route] + $params;
        if (!isset($params['_lang']) && function_exists('siteLang')) {
            $params['_lang'] = siteLang();
        }
    } else {
        $path = $routes[$route]['path'];
    }
    return $path . ($params === [] ? '' : '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
}
