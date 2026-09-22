<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
define('ROOT_PATH', dirname(__DIR__, 2));
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
if (!db()->isSqlite() || !is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')
    || realpath(DB_PATH) !== realpath(ROOT_PATH . '/storage/database.sqlite')) throw new RuntimeException('Smoke setup required');
$base = $argv[1] ?? 'http://127.0.0.1:8187';
if (!preg_match('~^http://127\.0\.0\.1:[0-9]+$~D', $base)) throw new RuntimeException('Loopback target required');
$jar = tempnam(sys_get_temp_dir(), 'usability-http-');
if ($jar === false) throw new RuntimeException('Cookie storage unavailable');
function usabilityRequest(string $path, ?array $data = null): array
{
    global $base, $jar;
    $curl = curl_init($base . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar, CURLOPT_TIMEOUT => 30]);
    if ($data !== null) curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($data)]);
    $body = (string) curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return [$status, $body];
}
function usabilityAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
$plugins = db()->fetchAll('SELECT id, status FROM ' . DB_PREFIX . 'plugins');
$settings = settingModel()->getAll();
$channelId = 0;
$failure = null;
try {
    [, $login] = usabilityRequest('/admin/login.php');
    preg_match('/name="csrf-token"\s+content="([a-f0-9]+)"/', $login, $token);
    if (!isset($token[1])) preg_match('/name="_token"[^>]*value="([a-f0-9]+)"/', $login, $token);
    usabilityAssert(isset($token[1]), 'Login token missing');
    [$status] = usabilityRequest('/admin/login.php', ['username' => 'admin', 'password' => 'smoke@Test123', '_token' => $token[1]]);
    usabilityAssert($status === 302, 'Login failed');
    // 临时调整仅限 setup.php 创建的可恢复冒烟库；finally 恢复所有触及数据。
    foreach ($plugins as $plugin) db()->update('plugins', ['status' => 0], 'id = ?', [(int) $plugin['id']]);
    $channelId = (int) db()->insert('channels', ['name' => 'Usability retired fixture', 'slug' => 'usability-retired-fixture', 'type' => 'page', 'status' => 0]);
    settingModel()->saveBatch(['footer_nav' => '[{"links":[{"url":"/usability-retired-fixture.html"}]}]']);
    [, $page] = usabilityRequest('/admin/site_templates.php');
    preg_match('/name="_token"[^>]*value="([a-f0-9]+)"/', $page, $token);
    usabilityAssert(isset($token[1]), 'Export token missing');
    [$status, $checked] = usabilityRequest('/admin/site_templates.php', ['action' => 'check_export', '_token' => $token[1]]);
    usabilityAssert($status === 200 && str_contains($checked, 'name="confirm_export"'), 'Warning acknowledgement missing');
    usabilityAssert(str_contains($checked, '/usability-retired-fixture.html'), 'Omitted channel warning missing');
    [$status, $refused] = usabilityRequest('/admin/site_templates.php', ['action' => 'export', '_token' => $token[1]]);
    usabilityAssert($status === 200 && str_contains($refused, __('st_export_review')), 'Unacknowledged export must stay on review');
    [$status] = usabilityRequest('/admin/site_templates.php', ['action' => 'check_export', '_token' => 'invalid']);
    usabilityAssert($status >= 400, 'Invalid CSRF must be rejected');
    // 模拟主题页先写入设置，但老库没有下拉框 options 元数据的情况。
    settingModel()->saveBatch(['motion_intensity' => '0']);
    [$status, $basic] = usabilityRequest('/admin/setting.php');
    usabilityAssert($status === 200, 'Basic settings failed');
    usabilityAssert(str_contains($basic, e(__('setting_motion_intensity'))), 'Motion label missing');
    usabilityAssert(!preg_match('/>\s*motion_(title|hint)\s*</', $basic), 'Raw motion translation key');
    preg_match('/<select name="settings\[motion_intensity\]"[^>]*>(.*?)<\/select>/s', $basic, $select);
    usabilityAssert(isset($select[1]), 'Motion selector missing');
    preg_match_all('/<option value="([^"]+)"/', $select[1], $options);
    usabilityAssert($options[1] === ['none', 'light', 'standard'], 'Motion options must not be boolean');
    usabilityAssert((bool) preg_match('/value="standard"\s+selected/', $select[1]), 'Legacy motion value must display standard');
    settingModel()->clearCache();
    usabilityAssert(settingModel()->get('motion_intensity') === '0', 'GET must not rewrite settings');
    [$status] = usabilityRequest('/admin/setting.php', ['_token' => $token[1], 'settings' => ['motion_intensity' => '0']]);
    usabilityAssert($status === 422, 'Invalid motion level must be rejected');
    [$status, $saved] = usabilityRequest('/admin/setting.php', ['_token' => $token[1], 'settings' => ['motion_intensity' => 'light']]);
    usabilityAssert($status === 200 && (json_decode($saved, true)['code'] ?? -1) === 0, 'Valid motion level save failed');
    settingModel()->clearCache();
    usabilityAssert(settingModel()->get('motion_intensity') === 'light', 'Motion level not saved');
    $footerFixture = json_encode([['title' => 'Footer probe', 'content' => '<p><strong>FooterFormatProbe</strong></p><ul><li>List item</li></ul><a href="/contact.html">Contact</a>{{site_description}}', 'col_span' => 1, 'menu_id' => 0]]);
    [$status, $saved] = usabilityRequest('/admin/setting.php?tab=footer&lang=zh-CN', ['_token' => $token[1], 'settings' => ['footer_columns' => $footerFixture]]);
    usabilityAssert($status === 200 && (json_decode($saved, true)['code'] ?? -1) === 0, 'Footer rich content save failed');
    foreach (['en', 'ja'] as $footerLang) {
        [$status, $saved] = usabilityRequest('/admin/setting.php?tab=footer&lang=' . $footerLang, ['_token' => $token[1], 'settings' => ['footer_columns' => str_replace('FooterFormatProbe', 'FooterFormatProbe-' . $footerLang, $footerFixture)]]);
        usabilityAssert($status === 200 && (json_decode($saved, true)['code'] ?? -1) === 0, 'Translated footer save failed');
    }
    settingModel()->clearCache();
    usabilityAssert(settingModel()->get('footer_columns') === $footerFixture, 'Translated footer overwrote source');
    foreach (['en', 'ja'] as $footerLang) usabilityAssert(str_contains((string) settingModel()->get('footer_columns_' . $footerLang), 'FooterFormatProbe-' . $footerLang), 'Footer translation missing');
    settingModel()->saveBatch(['current_theme' => 'default', 'blox_custom_footer_enabled' => '0']);
    [$status, $front] = usabilityRequest('/?footer_editor_probe=1');
    usabilityAssert($status === 200 && str_contains($front, 'yk-footer-column-content'), 'Native footer not rendered');
    usabilityAssert(str_contains($front, '<strong>FooterFormatProbe</strong>') && str_contains($front, '<ul><li>List item</li></ul>'), 'Footer formatting lost');
    echo "Template usability HTTP checks passed\n";
} catch (Throwable $error) {
    $failure = $error->getMessage();
} finally {
    foreach ($plugins as $plugin) db()->update('plugins', ['status' => (int) $plugin['status']], 'id = ?', [(int) $plugin['id']]);
    if ($channelId > 0) db()->delete('channels', 'id = ?', [$channelId]);
    if (array_key_exists('footer_nav', $settings)) settingModel()->saveBatch(['footer_nav' => $settings['footer_nav']]);
    else db()->delete('settings', '`key` = ?', ['footer_nav']);
    if (array_key_exists('motion_intensity', $settings)) settingModel()->saveBatch(['motion_intensity' => $settings['motion_intensity']]);
    else db()->delete('settings', '`key` = ?', ['motion_intensity']);
    foreach (['footer_columns', 'footer_columns_en', 'footer_columns_ja', 'current_theme', 'blox_custom_footer_enabled'] as $footerKey) {
        if (array_key_exists($footerKey, $settings)) settingModel()->saveBatch([$footerKey => $settings[$footerKey]]);
        else db()->delete('settings', '`key` = ?', [$footerKey]);
    }
    @unlink($jar);
}
if ($failure !== null) { fwrite(STDERR, $failure . "\n"); exit(1); }
