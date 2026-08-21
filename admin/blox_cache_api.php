<?php
/** Blox editor cache maintenance endpoint. */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/HtmlCache.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['code' => 1, 'msg' => __('admin_failed')], JSON_UNESCAPED_UNICODE);
    exit;
}

verifyCsrf();
$count = HtmlCache::invalidate();
adminLog('cache', 'clear', 'Blox 编辑器清空缓存：' . $count . ' 个文件');

echo json_encode([
    'code' => 0,
    'msg' => str_replace(':n', (string) $count, __('scache_cleared')),
], JSON_UNESCAPED_UNICODE);
