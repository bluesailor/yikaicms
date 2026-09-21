<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('IK_CLI', true);
require dirname(__DIR__, 2) . '/includes/init.php';
require ROOT_PATH . '/includes/SiteTemplateService.php';
$base = 'http://127.0.0.1:8080';
$theme = (string) config('current_theme');
if (config('site_url') !== $base || !str_starts_with($theme, 'sitepack-') || config('smtp_host', '') !== '') throw new RuntimeException('Isolated imported fixture with no email transport required');
$key = 'theme_content_' . $theme;
$before = settingModel()->get($key, null);
$ownValue = $before;
$submissionId = 0;
$jar = tempnam(sys_get_temp_dir(), 'yk-site-actions-');
if ($jar === false) throw new RuntimeException('Cannot create cookie jar');
$request = static function(string $path, ?array $data = null) use ($base, $jar): string {
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30]);
    if ($data !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data)]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if (!is_string($body) || $status >= 500) throw new RuntimeException('HTTP failure: ' . $path);
    return $body;
};
$field = static function(string $html, string $name): string {
    if (!preg_match('/name="' . preg_quote($name, '/') . '"[^>]*value="([^"]*)"/', $html, $match)) throw new RuntimeException('Missing field: ' . $name);
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
};
$assert = static function(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); };
try {
    $login = $request('/admin/login.php');
    $request('/admin/login.php', ['username' => 'admin', 'password' => 'smoke@Test123', '_token' => $field($login, '_token')]);
    $page = $request('/admin/theme_content.php');
    $csrf = $field($page, '_token');
    $stamp = $field($page, 'fingerprint');
    $values = ThemeContent::values($theme, 'zh-CN', ThemeContent::schema($theme));
    $values['hero_title'] = 'HTTP edited title';
    $params = ['_token' => $csrf, 'theme' => $theme, 'fingerprint' => $stamp, 'fields' => $values];
    $saved = $request('/admin/theme_content.php', $params);
    settingModel()->clearCache();
    $ownValue = settingModel()->get($key);
    $assert(str_contains($saved, '主题内容已保存'), 'Content save failed');
    $assert(str_contains($request('/'), 'HTTP edited title'), 'Published output did not change');
    $stale = $request('/admin/theme_content.php', $params);
    $assert(str_contains($stale, '已被其他操作修改'), 'Stale editor was not blocked');
    $params['fingerprint'] = $field($saved, 'fingerprint');
    $params['fields']['button_url'] = 'javascript:alert(1)';
    $invalid = $request('/admin/theme_content.php', $params);
    $assert(str_contains($invalid, 'http/https'), 'Unsafe URL not rejected');
    settingModel()->clearCache();
    $assert(settingModel()->get($key) === $ownValue, 'Rejected field modified storage');
    $english = $request('/admin/theme_content.php?lang=en');
    $enValues = ThemeContent::values($theme, 'en', ThemeContent::schema($theme));
    $enValues['hero_title'] = 'English field update';
    $request('/admin/theme_content.php?lang=en', ['_token' => $csrf, 'theme' => $theme, 'fingerprint' => $field($english, 'fingerprint'), 'fields' => $enValues]);
    settingModel()->clearCache();
    $ownValue = settingModel()->get($key);
    $stored = json_decode($ownValue, true);
    $assert($stored['zh-CN']['hero_title'] === 'HTTP edited title' && $stored['en']['hero_title'] === 'English field update', 'Language isolation failed');
    $contact = $request('/contact.html');
    $slug = $field($contact, 'form_slug');
    $timestamp = $field($contact, 'form_ts');
    $signature = $field($contact, 'form_sig');
    usleep(2100000); // Exercise the public anti-instant-submit guard, never forge a timestamp/signature.
    $marker = 'Site template form probe ' . bin2hex(random_bytes(6));
    $result = json_decode($request('/form_submit.php', ['form_slug' => $slug, 'form_ts' => $timestamp, 'form_sig' => $signature,
        'name' => 'Template tester', 'phone' => '13800000000', 'email' => 'fixture@example.test', 'content' => $marker]), true);
    $assert(is_array($result) && ($result['code'] ?? 1) === 0, 'Contact form submission failed');
    $submissionId = (int) db()->fetchColumn('SELECT id FROM ' . DB_PREFIX . 'forms WHERE content = ? ORDER BY id DESC LIMIT 1', [$marker]);
    $assert($submissionId > 0, 'Contact form did not persist');
    $assert(!(new SiteTemplateService(ROOT_PATH))->recovery()['can_restore'], 'Recovery must not overwrite edited content or an inquiry');
    echo "PASS: content fields, publication, stale writes, URL policy, language isolation, public contact submission\n";
} finally {
    settingModel()->clearCache();
    if (settingModel()->get($key, null) === $ownValue) {
        if ($before === null) db()->delete('settings', '`key` = ?', [$key]);
        else settingModel()->set($key, (string) $before, 'theme');
        settingModel()->clearCache();
        do_action('data_changed', 'settings', 0, [$key => $before]);
    }
    if ($submissionId > 0) db()->delete('forms', 'id = ?', [$submissionId]);
    unlink($jar);
}
