<?php
/**
 * Yikai CMS - 后台命令面板搜索端点
 *
 * GET ?q=关键词 → JSON 命中页面列表（不依赖 AI，纯本地匹配）
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/admin_pages_catalog.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['admin_id'])) {
    echo json_encode(['code' => 401, 'msg' => 'Unauthorized']);
    exit;
}

// 本端点自建登录判断、不走 checkLogin()，身份刷新要自己调（否则沿用登录时的旧权限快照）
refreshAdminIdentity();

$q = (string)($_GET['q'] ?? '');
$results = adminPagesSearch($q, 8);

echo json_encode([
    'code' => 0,
    'data' => array_map(fn($p) => [
        'url'   => $p['url'],
        'title' => $p['title'],
        'group' => $p['group'],
    ], $results),
], JSON_UNESCAPED_UNICODE);
