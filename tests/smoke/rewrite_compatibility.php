<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IK_CLI', true);
require dirname(__DIR__, 2) . '/includes/init.php';
if (config('site_url') !== 'http://127.0.0.1:8080' || !str_starts_with((string) config('current_theme'), 'sitepack-')) {
    throw new RuntimeException('Use the isolated site-template fixture only');
}
$before = (string) config('url_mode', 'pretty');
$jar = tempnam(sys_get_temp_dir(), 'yk-route-test-');
if ($jar === false) throw new RuntimeException('Cookie jar unavailable');
$request = static function(int $port, string $path, ?array $data = null) use ($jar): array {
    $curl = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false]);
    if ($data !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data)]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!is_string($body) || $status >= 500) throw new RuntimeException('HTTP failure: ' . $path);
    return [$status, $body];
};
$assert = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
try {
    $nonce = bin2hex(random_bytes(16));
    foreach (['/contact.html', '/en/contact.html'] as $path) {
        [$status, $body] = $request(8080, $path . '?__yk_route_probe=' . $nonce);
        $probe = json_decode($body, true);
        $assert($status === 200 && $probe === ['probe' => 'yikai-rewrite-v1', 'nonce' => $nonce, 'path' => $path], 'Rewrite probe mismatch');
        [$status] = $request(8081, $path . '?__yk_route_probe=' . $nonce);
        $assert($status === 404, 'No-rewrite fixture must reject pretty URLs');
    }
    [, $login] = $request(8081, '/admin/login.php');
    preg_match('/name="_token"[^>]*value="([^"]+)"/', $login, $match);
    [$status] = $request(8081, '/admin/login.php', ['username' => 'admin', 'password' => 'smoke@Test123', '_token' => $match[1]]);
    $assert($status === 302, 'Login failed');
    [, $page] = $request(8081, '/admin/setting.php?tab=url');
    preg_match('/name="csrf-token"\s+content="([a-f0-9]+)"/', $page, $match);
    $csrf = $match[1];
    $assert(str_contains($page, 'url-check-button'), 'Missing route check UI');
    settingModel()->clearCache();
    $assert(urlMode() === $before, 'Reading checks changed the mode');
    $other = $before === 'pretty' ? 'query' : 'pretty';
    $params = ['_token' => $csrf, 'settings' => ['url_mode' => $other]];
    [, $body] = $request(8081, '/admin/setting.php?tab=url', ['settings' => ['url_mode' => $other], 'url_mode_confirm' => '1', 'url_mode_expected' => $before]);
    $assert((json_decode($body, true)['code'] ?? 0) !== 0, 'Missing CSRF accepted');
    [, $body] = $request(8081, '/admin/setting.php?tab=url', $params);
    $assert((json_decode($body, true)['code'] ?? 0) !== 0, 'Unconfirmed change accepted');
    $params['url_mode_confirm'] = '1';
    $params['url_mode_expected'] = $other;
    [, $body] = $request(8081, '/admin/setting.php?tab=url', $params);
    $assert((json_decode($body, true)['code'] ?? 0) !== 0, 'Stale change accepted');
    $params['url_mode_expected'] = $before;
    [, $body] = $request(8081, '/admin/setting.php?tab=url', $params);
    $assert((json_decode($body, true)['code'] ?? 1) === 0, 'Confirmed change failed');
    settingModel()->clearCache();
    $assert(urlMode() === $other, 'Change not persisted');
    settingModel()->saveBatch(['url_mode' => 'query']);
    foreach (['/', '/index.php?yk_route=contact', '/index.php?yk_route=page&slug=about', '/index.php?yk_route=page&slug=services', '/index.php?yk_route=product_list', '/index.php?yk_route=news', '/index.php?yk_route=search&keyword=studio'] as $path) {
        [$status, $html] = $request(8081, $path);
        $assert($status === 200 && str_contains($html, '</html>'), 'Query page failed: ' . $path);
        $assert(!preg_match('/href="\/(?:contact|about|services)\.html/', $html), 'Legacy navigation leaked: ' . $path);
    }
    // Switching back restores original stored links; rendered compatibility must not persist into data.
    settingModel()->saveBatch(['url_mode' => 'pretty']);
    [, $html] = $request(8080, '/');
    $assert(str_contains($html, '/contact.html'), 'Pretty links did not return after switching back');
    echo "PASS: rewrite/no-rewrite servers, checks without mutation, confirmed/stale saves, seven query pages, reversible output\n";
} finally {
    settingModel()->saveBatch(['url_mode' => $before]);
    unlink($jar);
}
