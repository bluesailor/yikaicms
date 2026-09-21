<?php
declare(strict_types=1);

// Run only after tests/smoke/setup.php in an isolated checkout.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__, 2));
if (!is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) {
    throw new RuntimeException('Prepare an isolated smoke installation first.');
}
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
if (DB_DRIVER !== 'sqlite' || realpath(DB_PATH) !== realpath(ROOT_PATH . '/storage/database.sqlite')) {
    throw new RuntimeException('This probe only supports the isolated smoke database.');
}
$base = 'http://127.0.0.1:8080';
$jar = tempnam(sys_get_temp_dir(), 'site-setup-smoke-');
if ($jar === false) throw new RuntimeException('Cannot create a cookie jar.');
$request = static function (string $path, ?array $post = null) use ($base, $jar): string {
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 15]);
    if ($post !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!is_string($body) || $status >= 500) throw new RuntimeException('HTTP probe failed.');
    return $body;
};
$token = static function (string $html): string {
    if (!preg_match('/name="csrf-token"\s+content="([a-f0-9]+)"/', $html, $matches)
        && !preg_match('/name="_token"[^>]*value="([a-f0-9]+)"/', $html, $matches)) throw new RuntimeException('Missing CSRF token.');
    return $matches[1];
};
$assert = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};
$keys = ['home_layout_active', 'home_blox_active', 'home_layout_published'];
$before = [];
foreach ($keys as $key) $before[$key] = db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'settings WHERE `key` = ?', [$key]);
try {
    $csrf = $token($request('/admin/login.php'));
    $request('/admin/login.php', ['username' => 'admin', 'password' => 'smoke@Test123', '_token' => $csrf]);
    settingModel()->set('home_layout_active', '1', 'home');
    settingModel()->set('home_blox_active', '0', 'home');
    $published = '{"schema":1,"sections":[]}';
    settingModel()->set('home_layout_published', $published, 'home');
    $html = $request('/admin/site_setup.php');
    $csrf = $token($html);
    $assert((bool) preg_match('/name="fingerprint" value="([a-f0-9]+)"/', $html, $matches), 'Missing homepage switch.');
    $stamp = $matches[1];
    $request('/admin/site_setup.php', ['action' => 'theme_home', 'confirm' => '1', 'fingerprint' => $stamp, '_token' => $csrf]);
    settingModel()->clearCache();
    $assert(config('home_layout_active') === '0', 'Homepage did not switch.');
    $assert(config('home_layout_published') === $published, 'Publication changed.');
    $request('/admin/site_setup.php', ['action' => 'undo_home', '_token' => $csrf]);
    settingModel()->clearCache();
    $assert(config('home_layout_active') === '1', 'Undo did not restore the mode.');
    $result = json_decode($request('/admin/recipe.php', ['action' => 'apply', 'slug' => 'business-site', '_token' => $csrf]), true);
    $assert(is_array($result) && (int) ($result['code'] ?? 0) !== 0, 'Unpreviewed recipe was accepted.');
    $result = json_decode($request('/admin/setting_contact.php', ['settings' => ['contact_form_fields' => '[]'], '_token' => $csrf]), true);
    $assert(is_array($result) && (int) ($result['code'] ?? 0) !== 0, 'Legacy form fields were accepted.');
    $form = $request('/admin/setting_contact.php?tab=form');
    $assert(!str_contains($form, 'id="contactFormFieldsEditor"'), 'Legacy editor is visible.');
    $assert(str_contains($form, 'edit=contact'), 'Contact editor deep link is absent.');
    echo "PASS: homepage switch/undo, publication preservation, preview gate, single form editor\n";
} finally {
    foreach ($before as $key => $row) {
        if ($row === null) db()->delete('settings', '`key` = ?', [$key]);
        else settingModel()->set($key, (string) $row['value'], (string) $row['group']);
    }
    unlink($jar);
}
