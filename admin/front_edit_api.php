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
    'site_logo_alt' => 'basic',
    'site_logo_max_height' => 'basic',
];

$key   = (string) post('key');
$value = (string) ($_POST['value'] ?? '');

if (!isset($allow[$key])) {
    error(__('fe_key_not_allowed'));
}

// 逐键收口：数值钳制 / 文本截断，防脏数据入库
if ($key === 'site_logo_max_height') {
    $value = (string) max(16, min(200, (int) $value ?: 40));
} elseif ($key === 'site_logo_alt') {
    $value = mb_substr(trim($value), 0, 150);
}

settingModel()->set($key, $value, $allow[$key]);
adminLog('setting', 'front_edit', "前台就地编辑 {$key}");

// 清前台 HTML 缓存，改动立即对访客生效
if (class_exists('HtmlCache')) {
    HtmlCache::invalidate();
}

success(['key' => $key, 'value' => $value], '已保存');
