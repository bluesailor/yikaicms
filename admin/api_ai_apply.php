<?php
/**
 * Yikai CMS - AI 变更「应用」端点
 *
 * 用户在界面确认后，按 set_id 取回**服务端暂存**的提案并真正执行；
 * 每条写入 ai_change_log（含操作前快照），供撤销。绝不信任前端回传的改动内容。
 *
 * POST：set_id（必填）、ids（可选，逗号分隔的 proposal_id 子集；缺省=全部）
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/AiStaging.php';
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

$setId = trim((string)($_POST['set_id'] ?? ''));
$idsRaw = trim((string)($_POST['ids'] ?? ''));
$onlyIds = $idsRaw !== '' ? array_filter(array_map('trim', explode(',', $idsRaw))) : null;

$items = $setId !== '' ? AiStaging::items($setId) : [];
if ($items === []) {
    echo json_encode(['success' => false, 'error' => '提案已过期或不存在，请重新发起对话'], JSON_UNESCAPED_UNICODE);
    exit;
}

$adminId   = (int) $_SESSION['admin_id'];
$adminName = (string) ($_SESSION['admin_username'] ?? '');
$applied = [];
$errors  = [];

foreach ($items as $it) {
    if ($onlyIds !== null && !in_array($it['id'], $onlyIds, true)) continue;

    $ability = (string) $it['ability'];
    $input   = (array) ($it['input'] ?? []);
    $summary = (string) ($it['preview']['summary'] ?? $ability);

    if (!Abilities::isMutating($ability)) { $errors[] = "「{$summary}」不是可应用的写操作"; continue; }
    if (!Abilities::permitted($ability))  { $errors[] = "「{$summary}」权限不足"; continue; }

    // 应用时重新取「操作前快照」，保证撤销准确
    $prev   = Abilities::previewChange($ability, $input);
    $before = $prev['success'] ? ($prev['preview']['before'] ?? null) : ($it['preview']['before'] ?? null);

    $exec = Abilities::execute($ability, $input);
    if (!empty($exec['success'])) {
        $target = (string) ($input['key'] ?? $input['id'] ?? '');
        $logId = AiChangeLog::record($adminId, $adminName, $ability, $target, $summary, $before, $input);
        $applied[] = ['proposal_id' => $it['id'], 'ability' => $ability, 'summary' => $summary, 'log_id' => $logId];
    } else {
        $errors[] = "「{$summary}」应用失败：" . (string) ($exec['error'] ?? '未知错误');
    }
}

// 应用过的整组暂存清掉（避免重复应用）
AiStaging::clear($setId);

echo json_encode([
    'success' => $errors === [],
    'applied' => $applied,
    'errors'  => $errors,
], JSON_UNESCAPED_UNICODE);
