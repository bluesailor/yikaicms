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
requirePermission('*');

$pluginSlug = trim($_GET['plugin'] ?? '');

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
$pageTitle = ($pluginMeta['name'] ?? $pluginSlug);

// 加载插件管理页面（插件自行包含 header/footer）
require_once $adminPage;
