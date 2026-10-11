<?php
/**
 * 铃铛用例的诊断页（2026-10-11）：CI 上夹具（命令行）确认主题提醒已写入，后台页面却读不到。
 * 这里从网页请求这一侧报告实际用的数据库文件和读到的值，用例与命令行一侧对比。只在本机一次性测试站可用。
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-')
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
require ROOT_PATH . '/includes/UpdateNotice.php';
require __DIR__ . '/admin-notice-probe-lib.php';
header('Content-Type: application/json');
echo json_encode(adminNoticeProbe());
