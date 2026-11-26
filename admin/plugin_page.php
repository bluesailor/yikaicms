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

// G1（商城立项报告 §六）：插件可在 plugin.json 声明 admin_permission（如 "shop_manage"）
// 或 admin_permission_any（多键任一即可，如商城订单页让 shop_orders 也能进）。
// 声明的键必须已在权限目录（allPermissionKeys，含插件声明）里登记，否则退回
// '*'——未知键只收紧、不放宽。
$pluginSlug = trim($_GET['plugin'] ?? '');
// 已并入主程序的插件：旧书签、旧菜单地址转到新页面（新页面自己查权限）
$retiredTarget = retiredPluginRedirect($pluginSlug);
if ($retiredTarget !== null) {
    $keep = array_intersect_key($_GET, ['tab' => true]);
    // 写根相对地址：子目录前缀由 BasePath::prefixLocationHeader() 在发头前统一补上。
    // 这里再套 BasePath::url() 会补两次（/sub/sub/admin/…，2.3.0 子目录站的旧书签打不开）。
    header('Location: ' . $retiredTarget . ($keep !== [] ? '?' . http_build_query($keep) : ''), true, 302);
    exit;
}
$pluginMetaEarly = getPluginMeta($pluginSlug);
$pluginAdminPermissions = [];
if (is_array($pluginMetaEarly)) {
    $any = $pluginMetaEarly['admin_permission_any'] ?? null;
    if (is_array($any)) {
        foreach ($any as $key) {
            if (is_string($key) && in_array($key, allPermissionKeys(), true)) {
                $pluginAdminPermissions[] = $key;
            }
        }
    }
    if ($pluginAdminPermissions === []
        && is_string($pluginMetaEarly['admin_permission'] ?? null)
        && in_array($pluginMetaEarly['admin_permission'], allPermissionKeys(), true)) {
        $pluginAdminPermissions[] = $pluginMetaEarly['admin_permission'];
    }
}
if ($pluginAdminPermissions === []) {
    requirePermission('*');
} else {
    // 任一命中即放行（hasPermission 对超管恒真，语义不变）
    $allowed = false;
    foreach ($pluginAdminPermissions as $key) {
        if (hasPermission($key)) {
            $allowed = true;
            break;
        }
    }
    if (!$allowed) {
        permissionDenied();
    }
}

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
