<?php
/**
 * 产品导入插件 - 注册后台菜单
 */

if (!defined('ROOT_PATH')) exit;

add_action('admin_init', function (): void {
    if (!function_exists('register_admin_menu')) return;

    register_admin_menu('product', [
        'key'      => 'product_import',
        'label'    => '导入产品',
        'url'      => '/admin/plugin_page.php?plugin=product-import',
        'perm'     => '*',
        'priority' => 95,
        'icon'     => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>',
    ]);
});
