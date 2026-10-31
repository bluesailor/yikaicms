<?php
/**
 * Yikai CMS - AI 变更「撤销」端点
 *
 * 按 ai_change_log.id 撤销一条 AI 改动（缺省=该管理员最近一条未撤销）。
 * 调对应 ability 的 revert(before, input) 用操作前快照回退；回退与标记已撤销同一事务，
 * 且只有目标仍是应用后的样子才执行（之后被人改过就拒绝，不覆盖）。见 AiAssistantChanges。
 *
 * POST：id（可选，日志 id；缺省=最近一条）
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/AiAssistantChanges.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'error' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 本端点自建登录判断、不走 checkLogin()，身份刷新要自己调（否则沿用登录时的旧权限快照）
refreshAdminIdentity();
// 演示站限制（只读拦写、受保护页整页拒绝）：本端点不走 checkLogin()，要自己调
enforceDemoRestrictions();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST only'], JSON_UNESCAPED_UNICODE);
    exit;
}
verifyCsrf();

$adminId = (int) $_SESSION['admin_id'];
$result = (new AiAssistantChanges(db()))->undo($adminId, (int) ($_POST['id'] ?? 0));
if (!$result['success']) {
    echo json_encode(['success' => false, 'error' => $result['error']], JSON_UNESCAPED_UNICODE);
    exit;
}

$row = $result['row'];
echo json_encode([
    'success' => true,
    'id'      => (int) $row['id'],
    'summary' => (string) $row['summary'],
    'message' => '已撤销：' . (string) $row['summary'],
], JSON_UNESCAPED_UNICODE);
