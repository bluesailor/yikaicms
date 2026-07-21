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

// CSRF：本端点自建登录判断、未走 checkLogin 的自动校验，此处补上（前端 fetch 已由全局拦截器附带 _token）
verifyCsrf();

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
    $system =
        "你是 {$siteName} 后台的 AI 助手，能通过工具（function calling）查询和修改本站。\n\n" .
        "严格遵守：\n" .
        "1. 任何查询或修改，都【必须】实际调用对应的工具函数来完成。严禁只用文字描述你「将要做」或「已经做」的操作，严禁编造数据、结果或提案编号——不调用工具就等于什么都没做。\n" .
        "2. 修改站点的工具（写操作）调用后会返回 staged=true，表示改动已【暂存】、尚未生效，需用户在界面点「确认」才应用。这是正常流程：你只管调用工具，然后据实告诉用户你准备了哪些改动、请其确认，不要声称已改好。\n" .
        "3. 不确定设置项键名时，先调用 cms_list_common_settings 查询（例：ICP 备案号=site_icp，公安备案号=site_police）。\n" .
        "4. 完成后用简洁中文回复。";
}

AiService::$action = 'agent';
// stageMutations=true：写类能力转为提案暂存，读类照常执行
$result = aiService()->chatWithTools($prompt, $abilities, $system, 0.5, $maxIter, true);

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
