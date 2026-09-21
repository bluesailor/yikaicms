<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__, 2));
$mysql = getenv('SITE_TEMPLATE_MYSQL') === '1';
define('DB_DRIVER', $mysql ? 'mysql' : 'sqlite');
define('DB_PATH', ':memory:');
if ($mysql) {
    define('DB_HOST', getenv('SITE_TEMPLATE_DB_HOST') ?: '127.0.0.1');
    define('DB_PORT', getenv('SITE_TEMPLATE_DB_PORT') ?: '3306');
    define('DB_USER', getenv('SITE_TEMPLATE_DB_USER') ?: 'root');
    define('DB_PASS', getenv('SITE_TEMPLATE_DB_PASS') ?: '');
    define('DB_NAME', 'yk_siteprobe_' . bin2hex(random_bytes(6)));
}
define('DB_PREFIX', 'yikai_');
define('DB_CHARSET', 'utf8mb4');
define('DEBUG', true);
function config(string $key, mixed $default = ''): mixed { return settingModel()->get($key, $default); }
function __(string $key, array $params = []): string { return $key; }
require ROOT_PATH . '/config/version.php';
require ROOT_PATH . '/config/database.php';
require ROOT_PATH . '/includes/models/autoload.php';
require ROOT_PATH . '/includes/SiteTemplateService.php';
require ROOT_PATH . '/includes/SiteContentChecks.php';
$server = null;
if ($mysql) {
    // Test-only PDO: create a uniquely named disposable database, never a customer database.
    $server = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $server->exec('CREATE DATABASE `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    fwrite(STDERR, 'MySQL runtime: ' . $server->query('SELECT VERSION()')->fetchColumn() . "\n");
}
$root = sys_get_temp_dir() . '/yikai-site-test-' . bin2hex(random_bytes(8));
mkdir($root . '/themes/sample/layouts', 0700, true);
mkdir($root . '/uploads', 0700, true);
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(callable $action, string $code): void {
    try { $action(); } catch (RuntimeException $e) { check($e->getMessage() === $code, $code . ': ' . $e->getMessage()); return; }
    throw new RuntimeException('Expected rejection: ' . $code);
}
try {
    db()->getPdo()->exec((string) file_get_contents(ROOT_PATH . '/install/sql/' . ($mysql ? 'mysql' : 'sqlite') . '.sql'));
    foreach (SiteTemplateData::TABLES as $table) db()->execute('DELETE FROM ' . DB_PREFIX . $table);
    db()->execute('DELETE FROM ' . DB_PREFIX . 'settings');
    settingModel()->clearCache();
    settingModel()->saveBatch(['current_theme' => 'sample', 'site_name' => 'Source', 'site_url' => 'https://source.test', 'site_logo' => '/uploads/logo.svg',
        'site_lang' => 'zh-CN', 'enabled_languages' => '["zh-CN"]', 'home_blox_published' => '{"text":"public"}', 'home_blox_data' => '{"text":"PRIVATE DRAFT"}',
        'theme_style_settings' => '{"themes":{"sample":{"button":{"radius":22}}}}', 'smtp_pass' => 'PRIVATE PASSWORD', 'license_key' => 'PRIVATE LICENSE']);
    db()->insert('channels', ['id' => 71, 'name' => 'About', 'slug' => 'about', 'type' => 'page']);
    db()->insert('contents', ['id' => 91, 'channel_id' => 71, 'title' => 'About source', 'content' => '<img src="https://source.test/uploads/logo.svg">']);
    db()->insert('media', ['name' => 'logo', 'path' => 'uploads/logo.svg', 'url' => '/uploads/logo.svg']);
    db()->insert('media', ['name' => 'private', 'path' => 'uploads/private.pdf', 'url' => '/uploads/private.pdf']);
    file_put_contents($root . '/uploads/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><path d="M0 0h10v10H0z"/></svg>');
    file_put_contents($root . '/uploads/private.pdf', 'PRIVATE MEDIA');
    file_put_contents($root . '/themes/sample/theme.json', '{"name":"Sample","version":"1.0.0"}');
    file_put_contents($root . '/themes/sample/layouts/header.php', '<?php declare(strict_types=1); ?><img src="/uploads/logo.svg">');
    file_put_contents($root . '/themes/sample/layouts/footer.php', '<?php declare(strict_types=1); ?>Footer');
    $service = new SiteTemplateService($root);
    $zip = $root . '/export.zip';
    $summary = $service->export($zip);
    check($summary['media'] === 1, 'Referenced media only');
    $package = SiteTemplateArchive::read($zip);
    $payload = json_encode($package);
    check(!str_contains($payload, 'PRIVATE'), 'No secrets, drafts or private media');
    $bad = $package['manifest'];
    $bad['data']['settings']['smtp_pass'] = 'blocked';
    SiteTemplateArchive::write($root . '/bad.zip', $bad, $package['files']);
    rejects(static fn() => SiteTemplateArchive::read($root . '/bad.zip'), 'st_invalid');
    $bad = $package['manifest'];
    $bad['cms'] = '0.0.0';
    SiteTemplateArchive::write($root . '/bad.zip', $bad, $package['files']);
    rejects(static fn() => SiteTemplateArchive::read($root . '/bad.zip'), 'st_schema');
    $bad = $package['manifest'];
    $bad['data']['tables']['contents'][0]['channel_id'] = 999;
    SiteTemplateArchive::write($root . '/bad.zip', $bad, $package['files']);
    rejects(static fn() => SiteTemplateArchive::read($root . '/bad.zip'), 'st_references');
    copy($zip, $root . '/bad.zip');
    $tampered = new ZipArchive();
    $tampered->open($root . '/bad.zip');
    $tampered->addFromString('../escape.php', '<?php echo 1;');
    $tampered->close();
    rejects(static fn() => SiteTemplateArchive::read($root . '/bad.zip'), 'st_limit');
    rejects(static fn() => $service->prepare($zip, 1), 'st_not_fresh');
    foreach (SiteTemplateData::TABLES as $table) db()->execute('DELETE FROM ' . DB_PREFIX . $table);
    settingModel()->saveBatch(['current_theme' => 'default', 'site_name' => 'Fresh', 'site_logo' => '', 'site_url' => 'https://target.test']);
    // No-demo installers can retain a legacy channel reference after omitting demo albums.
    db()->insert('channels', ['id' => 88, 'name' => 'Legacy seed', 'slug' => 'legacy-seed', 'album_id' => 999]);
    $service->markFreshInstall();
    rejects(static fn() => $service->markFreshInstall(), 'st_not_fresh');
    $before = SiteTemplateData::fingerprint();
    $preview = $service->prepare($zip, 1);
    rejects(static fn() => $service->apply($preview['token'], 1, [], false), 'st_trust');
    rejects(static fn() => $service->apply($preview['token'], 2, [], true), 'st_stale');
    settingModel()->set('home_blox_data', 'Unsaved new page');
    rejects(static fn() => $service->apply($preview['token'], 1, ['site_name' => 'Target'], true), 'st_not_fresh');
    settingModel()->set('home_blox_data', '{"text":"PRIVATE DRAFT"}');
    $service->apply($preview['token'], 1, ['site_name' => 'Target'], true);
    check(str_starts_with(config('current_theme'), 'sitepack-'), 'New theme alias');
    check(config('site_url') === 'https://target.test' && config('smtp_pass') === 'PRIVATE PASSWORD', 'Target identity unchanged');
    check(db()->fetchColumn('SELECT channel_id FROM yikai_contents WHERE id = 91') == 71, 'Stable relations');
    check(count(db()->fetchAll('SELECT * FROM yikai_media')) === 1, 'Public media imported');
    $media = db()->fetchOne('SELECT * FROM yikai_media');
    check(is_file($media['path']), 'Media local path rewritten');
    check(str_contains(config('theme_style_settings'), config('current_theme')), 'Theme profile remapped');
    $service = new SiteTemplateService($root);
    check($service->recovery()['can_restore'], 'Durable recovery');
    $submission = db()->insert('forms', ['name' => 'Test inquiry']);
    rejects(static fn() => $service->restore(), 'st_restore_changed');
    db()->delete('forms', 'id = ?', [$submission]);
    settingModel()->set('site_name', 'Edited');
    rejects(static fn() => $service->restore(), 'st_restore_changed');
    settingModel()->set('site_name', 'Target');
    $service->restore();
    check(hash_equals($before, SiteTemplateData::fingerprint()), 'Exact restoration');
    check($service->canApply(), 'Fresh again');
    rejects(static fn() => $service->apply($preview['token'], 1, ['site_name' => 'Target'], true), 'st_stale');
    db()->insert('channels', ['id' => 501, 'name' => 'Your Company', 'slug' => 'empty-page', 'type' => 'page', 'image' => '/uploads/missing.png']);
    $checkBefore = SiteTemplateData::fingerprint();
    $report = SiteContentChecks::run($root);
    $kinds = array_column($report['issues'], 'kind');
    foreach (['sc_missing', 'sc_empty', 'sc_demo'] as $kind) check(in_array($kind, $kinds, true), 'Content checker: ' . $kind);
    check($checkBefore === SiteTemplateData::fingerprint(), 'Content checker must be read-only');
    check(SiteTemplateArchive::rewrite('https://other.test/uploads/a.png /uploads/a.png', ['/uploads/' => '/uploads/new/']) === 'https://other.test/uploads/a.png /uploads/new/a.png', 'Third-party references preserved');
    foreach (['../secret', '/absolute', 'a/CON.png', 'a\\b', 'a/..', 'a//b'] as $path) check(!SiteTemplateArchive::safePath($path), 'Unsafe path');
    echo "Site template roundtrip passed\n";
} finally {
    // Only this process-owned, random temporary fixture directory is removed.
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
    if ($server !== null && preg_match('/^yk_siteprobe_[a-f0-9]{12}$/D', DB_NAME)) $server->exec('DROP DATABASE `' . DB_NAME . '`');
}
