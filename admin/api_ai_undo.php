<?php
/**
 * Yikai CMS - AI 变更「撤销」端点
 *
 * 按 ai_change_log.id 撤销一条 AI 改动（缺省=该管理员最近一条未撤销）。
 * 调对应 ability 的 revert(before, input) 用操作前快照回退。
 *
 * POST：id（可选，日志 id；缺省=最近一条）
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/AiChangeLog.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 本端点自建登录判断、不走 checkLogin()，身份刷新要自己调（否则沿用登录时的旧权限快照）
refreshAdminIdentity();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST only'], JSON_UNESCAPED_UNICODE);
    exit;
}
verifyCsrf();

$adminId = (int) $_SESSION['admin_id'];
$id = (int) ($_POST['id'] ?? 0);

$row = $id > 0 ? AiChangeLog::get($id) : AiChangeLog::lastUndoable($adminId);
if (!$row) {
    echo json_encode(['success' => false, 'error' => '没有可撤销的记录'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ((int) $row['admin_id'] !== $adminId) {
    echo json_encode(['success' => false, 'error' => '无权撤销该记录'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ((int) $row['undone'] === 1) {
    echo json_encode(['success' => false, 'error' => '该记录已撤销'], JSON_UNESCAPED_UNICODE);
    exit;
}

$before = json_decode((string) $row['before_json'], true);
$input  = json_decode((string) $row['input_json'], true);
if (!is_array($input)) $input = [];

$r = Abilities::revertChange((string) $row['ability'], $before, $input);
if (empty($r['success'])) {
    echo json_encode(['success' => false, 'error' => '撤销失败：' . (string) ($r['error'] ?? '未知')], JSON_UNESCAPED_UNICODE);
    exit;
}

AiChangeLog::markUndone((int) $row['id']);
echo json_encode([
    'success' => true,
    'id'      => (int) $row['id'],
    'summary' => (string) $row['summary'],
    'message' => '已撤销：' . (string) $row['summary'],
], JSON_UNESCAPED_UNICODE);
