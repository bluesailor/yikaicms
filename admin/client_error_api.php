<?php
/**
 * 后台前端错误上报（2.1.1）：assets/js/admin-error-report.js 发来的脚本错误写进错误日志（级别 JS），
 * 后台「系统 → 错误日志」可看，站点健康按近 7 天统计。
 *
 * 只收已登录后台用户带 CSRF 的 POST；每个会话每小时最多 MAX_PER_HOUR 条（前端每页另限 10 条）；
 * 字段截断、去掉换行，来源只留路径。不回显任何内容。
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

const CLIENT_ERROR_MAX_PER_HOUR = 30;

checkLogin();
header('Cache-Control: no-store, max-age=0');
if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    error(__('admin_illegal_request'), 405);
}
verifyCsrf();

$now = time();
$quota = is_array($_SESSION['client_error_quota'] ?? null) ? $_SESSION['client_error_quota'] : ['since' => $now, 'count' => 0];
if ($now - (int) ($quota['since'] ?? 0) >= 3600) {
    $quota = ['since' => $now, 'count' => 0];
}
if ((int) $quota['count'] >= CLIENT_ERROR_MAX_PER_HOUR) {
    success(['logged' => false]);
}

$field = static function (string $key, int $max): string {
    $value = $_POST[$key] ?? '';
    $value = is_string($value) ? $value : '';
    return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $value)), 0, $max);
};
$message = $field('message', 500);
if ($message === '') {
    success(['logged' => false]);
}
$page = $field('page', 200);
$expression = $field('expression', 300);
$stack = $field('stack', 800);
$at = strpos($stack, ' at ');   // Chrome 的堆栈第一行重复消息，只留后面的调用位置
$frames = $at === false ? '' : trim(substr($stack, $at));
$source = (string) (parse_url($field('source', 300), PHP_URL_PATH) ?? '');

$text = '[' . ($page !== '' ? $page : 'admin') . '] ' . $message
    . ($expression !== '' ? ' | expression: ' . $expression : '')
    . ($frames !== '' ? ' | ' . $frames : '');
ErrorHandler::log('JS', $text, $source, max(0, (int) ($_POST['line'] ?? 0)));

$quota['count'] = (int) $quota['count'] + 1;
$_SESSION['client_error_quota'] = $quota;
success(['logged' => true]);
