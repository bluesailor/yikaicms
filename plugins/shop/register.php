<?php
/**
 * 轻量商城 - 早期注册（菜单等）。
 *
 * 注意调用时机：本文件由 loadActivePlugins() 在「前台 init.php」与「后台 auth.php」
 * 两条链上都会加载（后台加载是 G1 配套修复），register_admin_menu 在后台可用、
 * 前台不可用（sidebar_menu_api 仅后台加载），因此必须 function_exists 守卫。
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

// 后台菜单：挂「产品」分组（与产品导入同一位置），perm 用插件声明的权限键
if (function_exists('register_admin_menu')) {
    register_admin_menu('product', [
        'key'      => 'shop_sales',
        'label'    => __('shop_menu_sales'),
        'url'      => '/admin/plugin_page.php?plugin=shop',
        'perm'     => 'shop_manage',
        'priority' => 90,
        'icon'     => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>',
    ]);
}

// 前台路由（Dispatcher 约定：自定义规则必须并到核心表**前面**，正则锚定写窄）
add_filter('dispatch_routes', static function (array $routes): array {
    return array_merge([
        ['#^shop/cart$#', 'plugins/shop/front/cart.php', [], []],
        ['#^shop/api$#', 'plugins/shop/front/api.php', [], []],
        ['#^shop/checkout$#', 'plugins/shop/front/checkout.php', [], []],
        ['#^shop/order$#', 'plugins/shop/front/order.php', [], []],
    ], $routes);
});
