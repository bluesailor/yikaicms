<?php
/**
 * 公开内容 API v1 — 共享引导。
 *
 * 只读 JSON 接口，供小程序 / App / 静态站 / AI 取站点内容。默认**关闭**，
 * 在后台「系统 → 开放接口」开启并可选设置 API Key。开启后：
 *   - 仅 GET；统一信封 {code,msg,data}（复用 functions.php 的 json/success/error）
 *   - 设了 Key 则校验 X-API-Key 头或 ?key=（hash_equals）
 *   - 按 IP 滑动窗口限流
 *   - 不走 checkLogin/CSRF（自然免会话/CSRF），只出已发布内容 + 字段白名单
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/init.php';

// CORS：只读跨域取数
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: X-API-Key, Content-Type');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'GET') {
    apiError('仅支持 GET 请求', 405);
}

// 总开关
if (config('public_api_enabled', '0') !== '1') {
    apiError('开放接口未启用', 403);
}

// 可选 API Key（配置了才校验）
$key = (string) config('public_api_key', '');
if ($key !== '') {
    $sent = (string) ($_SERVER['HTTP_X_API_KEY'] ?? ($_GET['key'] ?? ''));
    if (!hash_equals($key, $sent)) {
        apiError('无效的 API Key', 401);
    }
}

apiThrottle();

/** 成功信封（HTTP 200）。 */
function apiOk(mixed $data): never
{
    json(['code' => 0, 'msg' => 'ok', 'data' => $data], 200);
}

/** 错误信封（带真实 HTTP 状态码）。 */
function apiError(string $msg, int $http = 400): never
{
    json(['code' => $http, 'msg' => $msg, 'data' => null], $http);
}

/** 按 IP 滑动窗口限流（public_api_rate 次/分钟，0=不限）。 */
function apiThrottle(): void
{
    $limit = (int) config('public_api_rate', '60');
    if ($limit <= 0) {
        return;
    }
    $ip  = function_exists('getClientIp') ? getClientIp() : (string) ($_SERVER['REMOTE_ADDR'] ?? '0');
    $dir = ROOT_PATH . '/storage/api_throttle';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $file = $dir . '/' . md5($ip) . '.json';
    $now  = time();
    // flock 独占锁下 读-改-写：无锁时并发请求彼此覆盖计数（都读到 N、都写回 N+1），
    // 限流会被并发冲掉。与登录限流同一套模式。
    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        return;   // 存储不可写时放行——限流是保护层，不该把 API 打挂
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            return;
        }
        $hits = json_decode((string) stream_get_contents($fh), true) ?: [];
        $hits = array_values(array_filter($hits, static fn ($t): bool => (int) $t > $now - 60));
        if (count($hits) >= $limit) {
            flock($fh, LOCK_UN);
            fclose($fh);
            apiError('请求过于频繁，请稍后再试', 429);
        }
        $hits[] = $now;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) json_encode($hits));
        flock($fh, LOCK_UN);
    } finally {
        if (is_resource($fh)) {
            fclose($fh);
        }
    }
}
