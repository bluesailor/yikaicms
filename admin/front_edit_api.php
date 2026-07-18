<?php
/**
 * 前台就地编辑 - 保存接口
 *
 * 管理员在前台直接改动（如 Logo）时，AJAX 提交到这里保存对应设置。
 * 仅允许白名单内的键，避免被用作任意设置写入。checkLogin() 已含 CSRF 校验。
 *
 * POST: key=site_logo & value=<url> & _token=<csrf>
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

header('Content-Type: application/json; charset=utf-8');

// 可就地编辑的设置白名单：key => group
$allow = [
    'site_logo' => 'basic',
];

$key   = (string) post('key');
$value = (string) ($_POST['value'] ?? '');

if (!isset($allow[$key])) {
    error('不允许编辑该项');
}

settingModel()->set($key, $value, $allow[$key]);
adminLog('setting', 'front_edit', "前台就地编辑 {$key}");

// 清前台 HTML 缓存，改动立即对访客生效
if (class_exists('HtmlCache')) {
    HtmlCache::invalidate();
}

success(['key' => $key, 'value' => $value], '已保存');
