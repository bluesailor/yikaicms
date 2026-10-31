<?php
/**
 * Yikai CMS - AI 变更「应用」端点
 *
 * 用户在界面确认后，按 set_id 取回**服务端暂存**的提案并真正执行；绝不信任前端回传的改动内容。
 * 每条在一个事务里完成「执行 + 变更日志（操作前快照、应用后版本）」，失败整条回滚；
 * 只移出已应用的条目，未选/失败的留在暂存里可再次应用（AiAssistantChanges）。
 *
 * POST：set_id（必填）、ids（可选，逗号分隔的 proposal_id 子集；缺省=全部）
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/AiStaging.php';
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

$setId = trim((string)($_POST['set_id'] ?? ''));
$idsRaw = trim((string)($_POST['ids'] ?? ''));
$onlyIds = $idsRaw !== '' ? array_filter(array_map('trim', explode(',', $idsRaw))) : null;

$items = $setId !== '' ? AiStaging::items($setId) : [];
if ($items === []) {
    echo json_encode(['success' => false, 'error' => '提案已过期或不存在，请重新发起对话'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = (new AiAssistantChanges(db()))->applyStaged(
    (int) $_SESSION['admin_id'],
    (string) ($_SESSION['admin_username'] ?? ''),
    $setId,
    $onlyIds === null ? null : array_values($onlyIds)
);

echo json_encode($result, JSON_UNESCAPED_UNICODE);
