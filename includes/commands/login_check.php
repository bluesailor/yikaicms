<?php
/**
 * Command: site:login-check
 *
 * 后台登录不了时给服务器管理员用：像浏览器一样取两次登录页（第二次带上第一次拿到的会话 Cookie），
 * 判断会话 Cookie 有没有下发、登录页是否被缓存、Secure 标记与网址协议是否冲突、服务器是否真的保存了会话。
 * 不提交登录表单，不会触发登录限流或写登录失败记录。判定逻辑在 LoginDiagnostics::analyze()。
 */
declare(strict_types=1);

if (!defined('IK_CLI')) {
    return;
}

CLI::register('site:login-check', __('login_check_desc'), function (array $args, array $opts): int {
    require_once ROOT_PATH . '/includes/LoginDiagnostics.php';
    if (!function_exists('curl_init')) {
        CLI::err('PHP curl extension is required');
        return 1;
    }
    $siteUrl = trim((string) config('site_url', ''));
    if ($siteUrl === '' && defined('SITE_URL')) $siteUrl = (string) SITE_URL;
    $base = is_string($opts['url'] ?? null) ? trim($opts['url']) : rtrim($siteUrl, '/');
    if (!preg_match('~^https?://~i', $base)) {
        CLI::err(__('login_check_request_failed') . ': site_url / --url');
        return 1;
    }
    $url = preg_match('~/admin/login\.php$~', $base) ? $base : rtrim($base, '/') . '/admin/login.php';
    $insecure = !empty($opts['insecure']);

    // 手动跟随最多 3 次跳转：跳转本身（http→https、换域名）也是线索，最终网址用来判断 Secure 与域名
    $fetch = static function (string $target, string $cookie) use ($insecure): ?array {
        for ($hop = 0; $hop <= 3; $hop++) {
            $headers = [];
            $ch = curl_init($target);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => !$insecure, CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
                CURLOPT_HTTPHEADER => array_values(array_filter(['Cache-Control: no-cache', 'Accept-Language: zh-CN,en;q=0.8', $cookie !== '' ? 'Cookie: ' . $cookie : ''])),
                CURLOPT_USERAGENT => 'YikaiCMS-login-check',
                CURLOPT_HEADERFUNCTION => static function ($h, string $line) use (&$headers): int {
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) $headers[strtolower(trim($parts[0]))][] = trim($parts[1]);
                    return strlen($line);
                },
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if (!is_string($body)) {
                CLI::err(__('login_check_request_failed') . ': ' . $target . ' ' . $error);
                return null;
            }
            $location = $headers['location'][0] ?? '';
            if ($status >= 300 && $status < 400 && $location !== '') {
                $next = preg_match('~^https?://~i', $location) ? $location
                    : preg_replace('~^(https?://[^/]+).*$~i', '$1', $target) . '/' . ltrim($location, '/');
                CLI::info('→ ' . $status . ' ' . $next);
                $target = $next;
                continue;
            }
            return ['status' => $status, 'headers' => $headers, 'body' => $body, 'url' => $target];
        }
        return null;
    };

    $first = $fetch($url, '');
    if ($first === null) return 1;
    $cookieValue = '';
    foreach ($first['headers']['set-cookie'] ?? [] as $line) {
        $parsed = LoginDiagnostics::parseSetCookie($line);
        if ($parsed['name'] === SESSION_NAME) $cookieValue = $parsed['value'];
    }
    $second = $cookieValue !== '' ? $fetch($first['url'], SESSION_NAME . '=' . $cookieValue) : null;
    $checks = LoginDiagnostics::analyze($first, $second, SESSION_NAME, CSRF_TOKEN_NAME);
    $failed = array_values(array_filter($checks, static fn(array $c): bool => !$c['ok']));

    if (!empty($opts['json'])) {
        CLI::out((string) json_encode(['url' => $first['url'], 'ok' => $failed === [], 'checks' => $checks],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    } else {
        foreach ($checks as $check) {
            $line = __($check['key']) . ($check['detail'] !== '' ? ' (' . $check['detail'] . ')' : '');
            $check['ok'] ? CLI::ok($line) : CLI::err($line);
        }
        $failed === [] ? CLI::ok(__('login_check_summary_ok')) : CLI::err(__('login_check_summary_fail'));
    }
    return $failed === [] ? 0 : 1;
}, ['usage' => 'site:login-check [--url=https://example.com] [--insecure] [--json]']);
