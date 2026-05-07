<?php
/**
 * Yikai CMS - AI Agent 端点
 *
 * 接收一段自然语言指令，让 AI 通过 Abilities 注册中心自主调用 CMS 能力，
 * 自动循环直到给出最终答复。返回 final content + tool 调用日志。
 *
 * POST 参数：
 *   prompt:        必填，用户指令
 *   abilities:     选填，逗号分隔白名单，如 "cms.search_content,cms.list_drafts"；默认全部
 *   system:        选填，覆盖系统提示词
 *   max_iter:      选填，最大循环次数（默认 5）
 */

declare(strict_types=1);

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => "{$errstr} in {$errfile}:{$errline}"], JSON_UNESCAPED_UNICODE);
    exit;
});
set_exception_handler(function (\Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
});

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

if (!class_exists('AiService')) {
    require_once ROOT_PATH . '/includes/AiService.php';
}

if (empty($_SESSION['admin_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST only'], JSON_UNESCAPED_UNICODE);
    exit;
}

$prompt    = trim((string)($_POST['prompt'] ?? ''));
$absRaw    = trim((string)($_POST['abilities'] ?? ''));
$abilities = $absRaw !== '' ? array_filter(array_map('trim', explode(',', $absRaw))) : [];
$system    = trim((string)($_POST['system'] ?? ''));
$maxIter   = max(1, min(10, (int)($_POST['max_iter'] ?? 5)));

if ($prompt === '') {
    echo json_encode(['success' => false, 'error' => 'prompt 不能为空'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($system === '') {
    $siteName = config('site_name', 'Yikai CMS');
    $system = "你是 {$siteName} 后台的 AI 助手。当用户的请求需要查询或操作 CMS 数据时，使用提供的工具（function calling）来完成；不要凭空编造数据。完成后用简洁的中文给出最终回复。";
}

AiService::$action = 'agent';
$result = aiService()->chatWithTools($prompt, $abilities, $system, 0.5, $maxIter);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
