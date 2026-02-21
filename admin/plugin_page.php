<?php
/**
 * Yikai CMS - 插件管理页面路由
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
    die('<div style="padding:50px;text-align:center"><h2>无效的插件</h2><a href="/admin/plugin.php">返回插件管理</a></div>');
}

// 检查插件是否已启用
$active = pluginModel()->findWhere(['slug' => $pluginSlug, 'status' => 1]);
if (!$active) {
    die('<div style="padding:50px;text-align:center"><h2>插件未启用</h2><a href="/admin/plugin.php">返回插件管理</a></div>');
}

// 检查管理页面文件
$adminPage = ROOT_PATH . '/plugins/' . $pluginSlug . '/admin.php';
if (!file_exists($adminPage)) {
    die('<div style="padding:50px;text-align:center"><h2>插件没有管理页面</h2><a href="/admin/plugin.php">返回插件管理</a></div>');
}

// 获取插件元数据
$pluginMeta = getPluginMeta($pluginSlug);

$currentMenu = 'plugin';

// 加载插件管理页面
require_once $adminPage;
