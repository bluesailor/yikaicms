<?php
declare(strict_types=1);

// Run only against the disposable site created by tests/smoke/setup.php.
if (PHP_SAPI !== 'cli') exit('CLI only');
define('ROOT_PATH', dirname(__DIR__, 2));
require ROOT_PATH . '/config/config.php';
if (DB_DRIVER !== 'sqlite' || !is_file(__DIR__ . '/fixtures.json')
    || !is_dir(ROOT_PATH . '/storage/.smoke-state-backup')) {
    exit("Run smoke setup in a disposable checkout first.\n");
}
require_once ROOT_PATH . '/includes/models/autoload.php';
require_once ROOT_PATH . '/includes/Totp.php';

$base = 'http://127.0.0.1:8080';
$jars = [];
$checks = 0;
function dlCheck(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    ++$checks;
    echo "PASS {$label}\n";
}
function dlRequest(string $path, string $client, ?array $post = null): array
{
    global $base, $jars;
    $jars[$client] ??= tempnam(sys_get_temp_dir(), 'dl-smoke-');
    $handle = curl_init($base . $path);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIEJAR => $jars[$client], CURLOPT_COOKIEFILE => $jars[$client],
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20]);
    if ($post !== null) curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    $raw = curl_exec($handle);
    if (!is_string($raw)) throw new RuntimeException('HTTP request failed.');
    $length = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $result = [(int) curl_getinfo($handle, CURLINFO_HTTP_CODE), substr($raw, $length), substr($raw, 0, $length)];
    curl_close($handle);
    return $result;
}
function dlCsrf(string $html): string
{
    if (!preg_match('/name="_token"[^>]*value="([a-f0-9]+)"/', $html, $match)
        && !preg_match('/name="csrf-token"\s+content="([a-f0-9]+)"/', $html, $match)) throw new RuntimeException('CSRF field missing.');
    return $match[1];
}
function dlIssue(int $userId, string $note = 'Smoke verification'): string
{
    [, $page] = dlRequest('/admin/plugin_page.php?plugin=dologin', 'owner');
    [$status, $body] = dlRequest('/admin/plugin_page.php?plugin=dologin', 'owner', [
        '_token' => dlCsrf($page), 'dl_action' => 'issue', 'user_id' => $userId, 'minutes' => 60, 'note' => $note,
    ]);
    if ($status !== 200 || !preg_match('/dologin=1#([a-f0-9]{64})/', $body, $match)) throw new RuntimeException('Link generation failed.');
    dlCheck(!str_contains($body, '<script>bad-note</script>'), 'Note output escaped');
    return $match[1];
}
function dlRedeem(string $token, string $client): array
{
    [, $page] = dlRequest('/admin/login.php?dologin=1', $client);
    return dlRequest('/admin/login.php?dologin=1', $client, ['_token' => dlCsrf($page), 'dologin_token' => $token]);
}
try {
    [, $login] = dlRequest('/admin/login.php', 'owner');
    [$status] = dlRequest('/admin/login.php', 'owner', ['_token' => dlCsrf($login), 'username' => 'admin', 'password' => 'smoke@Test123']);
    dlCheck($status === 302, 'Password login remains functional');
    [, $plugins] = dlRequest('/admin/plugin.php', 'owner');
    [$status, $body] = dlRequest('/admin/plugin.php', 'owner', ['_token' => dlCsrf($plugins), 'action' => 'activate', 'slug' => 'dologin']);
    dlCheck($status === 200 && (json_decode($body, true)['code'] ?? -1) === 0, 'Plugin activation');
    [, $page] = dlRequest('/admin/plugin_page.php?plugin=dologin', 'owner');
    [$status, $page] = dlRequest('/admin/plugin_page.php?plugin=dologin', 'owner', ['_token' => dlCsrf($page), 'dl_action' => 'initialize']);
    dlCheck($status === 200 && db()->tableExists('dologin_links'), 'Initialize via shared migration');
    [$status] = dlRequest('/admin/plugin_page.php?plugin=dologin', 'anonymous');
    dlCheck($status === 302, 'Anonymous management denied');
    [$status] = dlRequest('/admin/plugin_page.php?plugin=dologin', 'owner', ['dl_action' => 'issue', 'user_id' => 1, 'minutes' => 60]);
    dlCheck($status === 403, 'Management CSRF enforced');
    $token = dlIssue(1, '<script>bad-note</script>');
    [$status, $page, $headers] = dlRequest('/admin/login.php?dologin=1', 'recipient');
    dlCheck($status === 200 && str_contains($headers, 'no-store') && str_contains($headers, 'no-referrer'), 'Confirmation page privacy headers');
    dlCheck((int) db()->fetchColumn('SELECT used_at FROM yikai_dologin_links ORDER BY id DESC LIMIT 1') === 0, 'GET preview does not consume link');
    [$status] = dlRequest('/admin/login.php?dologin=1', 'recipient', ['dologin_token' => $token]);
    dlCheck($status === 403, 'Redemption CSRF enforced');
    [$status, , $headers] = dlRedeem($token, 'recipient');
    dlCheck($status === 302 && str_contains($headers, 'Location: /admin/'), 'Link authenticates recipient');
    [$status, $page] = dlRequest('/admin/plugin_page.php?plugin=dologin', 'recipient');
    dlCheck($status === 200 && str_contains($page, 'dl-admin'), 'Recipient session has account permissions');
    [$status, , $headers] = dlRedeem($token, 'replay');
    dlCheck($status === 200 && !str_contains($headers, 'Location: /admin/'), 'Replay refused');
    $logs = db()->fetchAll('SELECT request_data, url FROM yikai_admin_logs');
    dlCheck(!str_contains(json_encode($logs), $token), 'Raw token absent from audit logs');
    $token = dlIssue(1);
    $id = (int) db()->fetchColumn('SELECT MAX(id) FROM yikai_dologin_links');
    [, $page] = dlRequest('/admin/plugin_page.php?plugin=dologin', 'owner');
    dlRequest('/admin/plugin_page.php?plugin=dologin', 'owner', ['_token' => dlCsrf($page), 'dl_action' => 'revoke', 'link_id' => $id]);
    dlCheck(dlRedeem($token, 'revoked')[0] === 200, 'Revoked link refused');
    $token = dlIssue(1);
    db()->execute('UPDATE yikai_dologin_links SET expires_at = ? WHERE id = (SELECT id FROM (SELECT MAX(id) AS id FROM yikai_dologin_links) latest)', [time() - 1]);
    dlCheck(dlRedeem($token, 'expired')[0] === 200, 'Expired link refused');
    $token = dlIssue(1);
    settingModel()->set('admin_ip_whitelist', '192.0.2.1', 'security');
    dlCheck(dlRequest('/admin/login.php?dologin=1', 'ip-denied')[0] === 403, 'Core IP allowlist enforced');
    settingModel()->set('admin_ip_whitelist', '', 'security');
    // Existing failed attempts include the invalid-link tests above.
    $throttle = ROOT_PATH . '/storage/login_throttle/127.0.0.1.json';
    file_put_contents($throttle, json_encode(['count' => 100, 'last' => time()]));
    dlCheck(dlRedeem($token, 'locked')[0] === 200, 'IP lockout refuses valid link');
    dlCheck((int) db()->fetchColumn('SELECT used_at FROM yikai_dologin_links ORDER BY id DESC LIMIT 1') === 0, 'Lockout does not consume valid link');
    unlink($throttle);
    [, $page] = dlRequest('/admin/plugin_page.php?plugin=dologin', 'owner');
    dlRequest('/admin/plugin_page.php?plugin=dologin', 'owner', ['_token' => dlCsrf($page), 'dl_action' => 'protection', 'attempts' => 6, 'lock_minutes' => 10]);
    dlCheck((string) db()->fetchColumn('SELECT value FROM yikai_settings WHERE `key` = ?', ['login_max_attempts']) === '6', 'Protection settings saved to CMS');
    $limitedRole = db()->fetchOne('SELECT * FROM yikai_roles WHERE id = 1');
    unset($limitedRole['id']);
    $limitedRole['name'] = 'dl_smoke_limited';
    $limitedRole['permissions'] = '["content"]';
    $limitedRoleId = (int) db()->insert('roles', $limitedRole);
    $target = db()->fetchOne('SELECT * FROM yikai_users WHERE id = 1');
    unset($target['id']);
    $target['username'] = 'dl_smoke_editor';
    $target['totp_secret'] = 'JBSWY3DPEHPK3PXP';
    $target['role_id'] = $limitedRoleId;
    $targetId = (int) db()->insert('users', $target);
    $token = dlIssue($targetId);
    [$status, , $headers] = dlRedeem($token, 'twofactor');
    dlCheck($status === 302 && str_contains($headers, 'Location: /admin/login.php'), 'TOTP required after link redemption');
    dlCheck(dlRequest('/admin/plugin_page.php?plugin=dologin', 'twofactor')[0] === 302, 'TOTP pending session cannot access admin');
    [, $page] = dlRequest('/admin/login.php', 'twofactor');
    $code = Totp::codeAt(Totp::base32Decode('JBSWY3DPEHPK3PXP'), (int) floor(time() / 30));
    [$status, , $headers] = dlRequest('/admin/login.php', 'twofactor', ['_token' => dlCsrf($page), 'action' => 'totp', 'totp_code' => $code]);
    dlCheck($status === 302 && str_contains($headers, 'Location: /admin/'), 'Valid TOTP completes login');
    [$status, $page] = dlRequest('/admin/plugin_page.php?plugin=dologin', 'twofactor');
    dlCheck(!str_contains($page, 'dl-admin'), 'Non-admin cannot manage links');
    $token = dlIssue($targetId);
    db()->update('users', ['status' => 0], 'id = ?', [$targetId]);
    dlCheck(dlRedeem($token, 'disabled-account')[0] === 200, 'Disabled target refused at HTTP entry');
    $token = dlIssue(1);
    [, $page] = dlRequest('/admin/plugin.php', 'owner');
    dlRequest('/admin/plugin.php', 'owner', ['_token' => dlCsrf($page), 'action' => 'deactivate', 'slug' => 'dologin']);
    [$status, $page] = dlRequest('/admin/login.php?dologin=1', 'disabled-plugin');
    dlCheck($status === 200 && !str_contains($page, 'dl-login-form'), 'Disabled plugin removes redemption entry');
    [$status] = dlRequest('/admin/login.php?dologin=1', 'disabled-plugin', ['_token' => dlCsrf($page), 'dologin_token' => $token]);
    dlCheck($status !== 302, 'Disabled plugin cannot redeem');
    [, $page] = dlRequest('/admin/plugin.php', 'owner');
    dlRequest('/admin/plugin.php', 'owner', ['_token' => dlCsrf($page), 'action' => 'activate', 'slug' => 'dologin']);
    echo "OK: {$checks} HTTP checks\n";
} finally {
    settingModel()->set('admin_ip_whitelist', '', 'security');
    foreach ($jars as $jar) if (is_file($jar)) unlink($jar);
}
