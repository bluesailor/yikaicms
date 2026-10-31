<?php

declare(strict_types=1);

// 仅供 PHPUnit 启动的临时本地 HTTP 进程，不是产品路由。
if (PHP_SAPI !== 'cli-server' || !is_string(getenv('YK_AI_BOOTSTRAP_FIXTURE_ROOT'))) { http_response_code(404); exit; }
$root = (string) getenv('YK_AI_BOOTSTRAP_FIXTURE_ROOT');
header('Content-Type: application/json; charset=utf-8');
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/health') { echo '{}'; return; }
$sourceRoot = dirname(__DIR__, 2);
require_once $sourceRoot . '/includes/ai-access/AiAccessRuntime.php';
try {
    if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/normal') {
        define('ROOT_PATH', $root);
        require $root . '/config/config.php';
        require_once $sourceRoot . '/includes/SessionStorage.php';
        SessionStorage::recover($root);
        SessionStorage::isolate($root, '');
        $_SESSION['admin_id'] = 999; // 有效后台会话也不能给外部凭据补权。
        $_SESSION['admin_permissions'] = ['*'];
        $out = ['success' => true, 'normal_session_active' => session_status() === PHP_SESSION_ACTIVE];
    } else {
        $identity = AiAccessRuntime::boot($root);
        $now = time();
        $context = $identity->authenticate((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''), static function (string $digest) use ($now): ?array {
            $fixtureHash = hash('sha256', str_repeat('a', 64));
            return !hash_equals($fixtureHash, $digest) ? null : [
                'id' => 9, 'admin_id' => 7, 'token_hash' => $fixtureHash, 'revoked_at' => null,
                'expires_at' => $now + 60, 'scopes' => '["site_info","article_read"]',
            ];
        }, $now);
        $out = ['success' => true, 'actor_id' => $context->actorId, 'article_read' => $context->allowsTool('article_read'),
            'session_status' => session_status(), 'session_id' => session_id(), 'session_array_exists' => isset($_SESSION),
            'functions_loaded' => function_exists('config'), 'plugins_loaded' => function_exists('loadActivePlugins'),
            'scheduled_publish_loaded' => class_exists('ScheduledPublish', false)];
    }
} catch (RuntimeException $error) {
    http_response_code(in_array($error->getCode(), [401, 403, 503], true) ? $error->getCode() : 500);
    $out = ['success' => false, 'error' => $error->getMessage(), 'session_status' => session_status(), 'session_id' => session_id()];
}
echo json_encode($out, JSON_THROW_ON_ERROR);
