<?php
/**
 * 轻量商城 - 早期注册（菜单等）。
 *
 * 注意调用时机：本文件由 loadActivePlugins() 在「前台 init.php」与「后台 auth.php」
 * 两条链上都会加载。新版核心会先加载 sidebar_menu_api；旧版核心顺序相反，
 * 因此后台请求要按需补载 API，不能因 function_exists 守卫静默丢菜单。
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

// Blox 在插件之后启动：通过注册钩子接入，停用时由 plugin.json 保留缺失节点元数据。
add_action('builder_register_element', static function (): void {
    require_once __DIR__ . '/ShopPurchaseElement.php';
    BloxPluginRegistry::registerElement('shop', new ShopPurchaseElement());
});

// 后台菜单：挂「产品」分组（与产品导入同一位置），perm 用插件声明的权限键。
// 兼容尚未合入「菜单 API 先于插件加载」修复的 CMS：只在真实后台入口补载，
// 前台与 CLI 不引入后台文件。
if (!function_exists('register_admin_menu')) {
    $shopScriptFile = realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $shopAdminRoot = realpath(ROOT_PATH . '/admin');
    $shopIsAdminRequest = $shopScriptFile !== false
        && $shopAdminRoot !== false
        && str_starts_with($shopScriptFile, $shopAdminRoot . DIRECTORY_SEPARATOR);
    $shopMenuApi = ROOT_PATH . '/admin/includes/sidebar_menu_api.php';
    if ($shopIsAdminRequest && is_file($shopMenuApi)) {
        require_once $shopMenuApi;
    }
}

if (function_exists('register_admin_menu')) {
    register_admin_menu('product', [
        'key'      => 'shop_sales',
        'label'    => __('shop_menu_sales'),
        'url'      => '/admin/plugin_page.php?plugin=shop',
        'perm'     => 'shop_manage',
        'priority' => 90,
        'icon'     => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>',
    ]);
    register_admin_menu('product', [
        'key'      => 'shop_orders',
        'label'    => __('shop_nav_orders'),
        'url'      => '/admin/plugin_page.php?plugin=shop&view=orders',
        'perm'     => 'shop_orders',
        'priority' => 91,
        'icon'     => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/>',
    ]);
}

// 站点模板只接入商城自己的公开合同；核心保持对插件表和字段零认知。
add_filter('site_template_plugin_export', static function (mixed $entry, string $slug): mixed {
    if ($slug !== 'shop') {
        return $entry;
    }
    require_once __DIR__ . '/lib/site-template.php';
    return shopSiteTemplateExport();
});

add_action('site_template_plugin_import', static function (array $payload, string $slug): void {
    if ($slug !== 'shop') {
        return;
    }
    require_once __DIR__ . '/lib/site-template.php';
    shopSiteTemplateImport($payload);
});

// 前台路由（Dispatcher 约定：自定义规则必须并到核心表**前面**，正则锚定写窄）
add_filter('dispatch_routes', static function (array $routes): array {
    return array_merge([
        ['#^shop/cart$#', 'plugins/shop/front/cart.php', [], []],
        ['#^shop/api$#', 'plugins/shop/front/api.php', [], []],
        ['#^shop/checkout$#', 'plugins/shop/front/checkout.php', [], []],
        ['#^shop/order$#', 'plugins/shop/front/order.php', [], []],
        ['#^shop/pay$#', 'plugins/shop/front/pay.php', [], []],
        ['#^shop/payment-notify/([a-z][a-z0-9_-]{0,19})$#', 'plugins/shop/front/payment-notify.php', ['gateway'], []],
        ['#^member/shop-orders$#', 'plugins/shop/front/member-orders.php', [], []],
    ], $routes);
});
