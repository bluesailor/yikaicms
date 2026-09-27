<?php
/** 免伪静态商城入口：固定路径白名单，继续经过原有插件路由过滤器。 */

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/init.php';
require_once __DIR__ . '/../lib/routes.php';
require_once ROOT_PATH . '/includes/Dispatcher.php';

header('Cache-Control: no-store');
$route = $_GET['shop_route'] ?? null;
$routes = shopFrontRoutes();
if (!isPluginAvailable('shop') || !is_string($route) || !isset($routes[$route])) {
    render404(__('error_page_not_found'));
    exit;
}

// 复用既有分发链，保证站点对支付入口等路由的限制仍然生效。
$_SERVER['REQUEST_URI'] = $routes[$route]['path'] . '?' . http_build_query($_GET, '', '&', PHP_QUERY_RFC3986);
Dispatcher::run();
