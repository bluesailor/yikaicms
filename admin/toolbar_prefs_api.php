<?php
/**
 * 后台顶栏设置（2.3.1）：保存当前登录管理员自己的顶栏偏好（见 includes/AdminToolbarPrefs.php）。
 *
 * 只收带 CSRF 的 POST；账号只取会话里的管理员 ID，不接受请求里指定别人的账号。
 * 不需要额外权限：每个能进后台的账号都能整理自己的顶栏；入口本身能不能用仍按原规则判断。
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/AdminToolbarPrefs.php';

checkLogin();
header('Cache-Control: no-store, max-age=0');
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    error(__('admin_illegal_request'), 405);
}
verifyCsrf();

$adminId = getAdminId();
$action = post('action');

if ($action === 'set_item') {
    $item = post('item');
    if (!in_array($item, AdminToolbarPrefs::ITEMS, true)) {
        error(__('admin_illegal_request'));
    }
    try {
        $hidden = AdminToolbarPrefs::setPinned($adminId, $item, post('pinned') === '1');
    } catch (\Throwable) {
        error(__('operation_failed'));
    }
    success(['hidden' => $hidden], '');
}

if ($action === 'reset') {
    AdminToolbarPrefs::reset($adminId);
    success(['hidden' => []], '');
}

error(__('admin_illegal_request'));
