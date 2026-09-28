<?php
declare(strict_types=1);

/**
 * 内部工具：远程检查 YikaiCMS 站点的后台登录会话（判定逻辑见 tools/LoginCheck.php）。
 *
 *   php tools/login-check.php --url=https://example.com [--cookie=IK_XXXX] [--insecure] [--json]
 *
 * --url 可以是站点根地址或 /admin/login.php；子目录安装写到子目录为止。
 */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/LoginCheck.php';

$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m)) $opts[$m[1]] = $m[2] ?? true;
}
$base = is_string($opts['url'] ?? null) ? trim($opts['url']) : '';
if (!preg_match('~^https?://~i', $base) || !function_exists('curl_init')) {
    fwrite(STDERR, "Usage: php tools/login-check.php --url=https://example.com [--cookie=IK_XXXX] [--insecure] [--json]  (needs the curl extension)\n");
    exit(2);
}
$url = preg_match('~/admin/login\.php$~', $base) ? $base : rtrim($base, '/') . '/admin/login.php';
$insecure = !empty($opts['insecure']);
$cookieName = is_string($opts['cookie'] ?? null) ? $opts['cookie'] : '';

// 手动跟随最多 3 次跳转：跳转本身（http→https、换域名）也是线索，最终网址用来判断 Secure 与域名
$fetch = static function (string $target, string $cookie) use ($insecure): ?array {
    for ($hop = 0; $hop <= 3; $hop++) {
        $headers = [];
        $ch = curl_init($target);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => !$insecure, CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
            CURLOPT_HTTPHEADER => array_values(array_filter(['Cache-Control: no-cache', 'Accept-Language: zh-CN,en;q=0.8', $cookie !== '' ? 'Cookie: ' . $cookie : ''])),
            CURLOPT_USERAGENT => 'Mozilla/5.0 (YikaiCMS login-check)',
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
            fwrite(STDERR, "请求失败: $target $error\n");
            return null;
        }
        $location = $headers['location'][0] ?? '';
        if ($status >= 300 && $status < 400 && $location !== '') {
            $next = preg_match('~^https?://~i', $location) ? $location
                : preg_replace('~^(https?://[^/]+).*$~i', '$1', $target) . '/' . ltrim($location, '/');
            echo "  → $status $next\n";
            $target = $next;
            continue;
        }
        return ['status' => $status, 'headers' => $headers, 'body' => $body, 'url' => $target];
    }
    return null;
};

$first = $fetch($url, '');
if ($first === null) exit(1);
$session = LoginCheck::sessionCookie($first['headers']['set-cookie'] ?? [], $cookieName);
$second = $session !== null ? $fetch($first['url'], $session['name'] . '=' . $session['value']) : null;
$checks = LoginCheck::analyze($first, $second, $cookieName);
$failed = array_values(array_filter($checks, static fn(array $c): bool => !$c['ok']));

if (!empty($opts['json'])) {
    echo json_encode(['url' => $first['url'], 'ok' => $failed === [], 'checks' => $checks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
} else {
    foreach ($checks as $check) {
        echo ($check['ok'] ? '✓ ' : '✗ '), $check['message'], $check['detail'] !== '' ? ' (' . $check['detail'] . ')' : '', "\n";
    }
    echo $failed === [] ? "✓ 登录会话检查通过\n" : "✗ 登录会话检查发现问题\n";
}
exit($failed === [] ? 0 : 1);
