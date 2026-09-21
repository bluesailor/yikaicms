<?php
/**
 * YikaiCMS - 插件管理页面路由
 *
 * 加载插件的后台管理页面: /admin/plugin_page.php?plugin=slug
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();

// G1（商城立项报告 §六）：插件可在 plugin.json 声明 admin_permission（如 "shop_manage"），
// 把后台页从超管专属放宽到对应角色。声明的键必须已在权限目录（allPermissionKeys，
// 含插件声明）里登记，否则退回 '*'——未知键只收紧、不放宽。
$pluginSlug = trim($_GET['plugin'] ?? '');
$pluginAdminPermission = '*';
$pluginMetaEarly = getPluginMeta($pluginSlug);
if (is_array($pluginMetaEarly)
    && is_string($pluginMetaEarly['admin_permission'] ?? null)
    && in_array($pluginMetaEarly['admin_permission'], allPermissionKeys(), true)) {
    $pluginAdminPermission = $pluginMetaEarly['admin_permission'];
}
requirePermission($pluginAdminPermission);

// 验证 slug
if (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $pluginSlug)) {
    die('<div style="padding:50px;text-align:center"><h2>' . e(__('pl_invalid_plugin')) . '</h2><a href="/admin/plugin.php">' . e(__('pl_back_to_plugins')) . '</a></div>');
}

// 检查插件是否已启用
$active = pluginModel()->findWhere(['slug' => $pluginSlug, 'status' => 1]);
if (!$active) {
    die('<div style="padding:50px;text-align:center"><h2>' . e(__('pl_not_enabled')) . '</h2><a href="/admin/plugin.php">' . e(__('pl_back_to_list')) . '</a></div>');
}

// 检查管理页面文件
$adminPage = ROOT_PATH . '/plugins/' . $pluginSlug . '/admin.php';
if (!file_exists($adminPage)) {
    die('<div style="padding:50px;text-align:center"><h2>' . e(__('pl_no_admin_page')) . '</h2><a href="/admin/plugin.php">' . e(__('pl_back_to_list')) . '</a></div>');
}

// 获取插件元数据
$pluginMeta = getPluginMeta($pluginSlug);

$currentMenu = 'plugin';
$pageTitle = pluginMetaLabel($pluginMeta, 'name', $pluginSlug);

// 加载插件管理页面（插件自行包含 header/footer）
require_once $adminPage;
