<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__, 2));
$mysql = getenv('SITE_TEMPLATE_MYSQL') === '1';
define('DB_DRIVER', $mysql ? 'mysql' : 'sqlite');
$databasePath = $mysql ? '' : sys_get_temp_dir() . '/yk-siteprobe-' . bin2hex(random_bytes(6)) . '.sqlite';
define('DB_PATH', $databasePath);
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
function configOverrides(): array { return is_array($GLOBALS['_test_config_overrides'] ?? null) ? $GLOBALS['_test_config_overrides'] : []; }
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
mkdir($root . '/plugins/back-to-top', 0700, true);
mkdir($root . '/plugins/cookie-consent', 0700, true);
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
    settingModel()->saveBatch(['current_theme' => 'sample', 'site_name' => 'Source', 'site_url' => 'https://source.test/cms', 'site_logo' => '/uploads/logo.svg',
        'site_lang' => 'zh-CN', 'enabled_languages' => '["zh-CN"]', 'home_blox_published' => '{"text":"public"}', 'home_blox_data' => '{"text":"PRIVATE DRAFT"}',
        'theme_style_settings' => '{"themes":{"sample":{"button":{"radius":22}}}}', 'smtp_pass' => 'PRIVATE PASSWORD', 'license_key' => 'PRIVATE LICENSE',
        'shop_payment_secret' => 'PRIVATE SHOP SECRET', 'seo_api_key' => 'PRIVATE SEO KEY']);
    db()->insert('channels', ['id' => 71, 'name' => 'About', 'slug' => 'about', 'type' => 'page']);
    $publicImages = ['logo.svg', 'gallery-1.svg', 'gallery-2.svg', 'gallery-3.svg', 'gallery-4.svg'];
    $publicUrls = [
        'https://source.test/cms/uploads/logo.svg',
        '//SOURCE.test/cms/uploads/gallery-1.svg?size=large',
        'https:&#47;&#47;source.test/cms/uploads/gallery-2.svg#entity',
        'https://SOURCE.test:443/cms/uploads/gallery-3.svg',
        '/uploads/gallery-4.svg',
    ];
    $imageHtml = implode('', array_map(static fn(string $url): string => '<img src="' . $url . '">', $publicUrls));
    db()->insert('contents', ['id' => 91, 'channel_id' => 71, 'title' => 'About source', 'content' => $imageHtml]);
    db()->insert('media', ['name' => 'logo', 'path' => 'uploads/logo.svg', 'url' => '/uploads/logo.svg']);
    foreach (array_slice($publicImages, 1) as $index => $name) {
        db()->insert('media', ['name' => 'gallery-' . ($index + 1), 'path' => 'uploads/' . $name, 'url' => '/uploads/' . $name]);
    }
    db()->insert('media', ['name' => 'private', 'path' => 'uploads/private.pdf', 'url' => '/uploads/private.pdf']);
    foreach ($publicImages as $index => $name) file_put_contents($root . '/uploads/' . $name, '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><path d="M' . $index . ' 0h1v1H0z"/></svg>');
    file_put_contents($root . '/uploads/private.pdf', 'PRIVATE MEDIA');
    file_put_contents($root . '/plugins/back-to-top/plugin.json', '{"name":"Back to Top","version":"1.0.0"}');
    file_put_contents($root . '/plugins/back-to-top/main.php', '<?php throw new RuntimeException("PLUGIN FILE MUST NOT RUN");');
    file_put_contents($root . '/plugins/cookie-consent/plugin.json', '{"name":"Cookie Consent","version":"1.1.0"}');
    file_put_contents($root . '/plugins/cookie-consent/main.php', '<?php throw new RuntimeException("PLUGIN FILE MUST NOT RUN");');
    db()->insert('plugins', ['slug' => 'back-to-top', 'status' => 1, 'installed_at' => time(), 'activated_at' => time()]);
    db()->insert('plugins', ['slug' => 'cookie-consent', 'status' => 1, 'installed_at' => time(), 'activated_at' => time()]);
    db()->execute('CREATE TABLE ' . DB_PREFIX . 'shop_orders (id INTEGER PRIMARY KEY, secret TEXT)');
    db()->execute('INSERT INTO ' . DB_PREFIX . 'shop_orders (id, secret) VALUES (?, ?)', [1, 'PRIVATE ORDER']);
    file_put_contents($root . '/themes/sample/theme.json', '{"name":"Sample","version":"1.0.0"}');
    file_put_contents($root . '/themes/sample/layouts/header.php', '<?php declare(strict_types=1); ?><img src="https://SOURCE.test:443/cms/uploads/logo.svg"><a href="//source.test/cms/about.html?next=&sol;contact">About</a><a href="https:&#47;&#47;source.test/cms/contact.html">Contact</a><a href="https://source.test/outside.html">Outside</a><a href="https://third.test/cms/about.html">Third</a><p>A &sol; B, C &#47; D, E &bsol; F</p>');
    file_put_contents($root . '/themes/sample/layouts/footer.php', '<?php declare(strict_types=1); ?>Footer');
    $service = new SiteTemplateService($root);
    $GLOBALS['_test_config_overrides'] = ['current_theme' => 'sample', 'site_name' => 'Source'];
    check($service->exportCheck()['blocked'] === '', 'Export preflight should accept the source');
    $GLOBALS['_test_config_overrides']['site_name'] = 'Pinned source';
    check($service->exportCheck()['blocked'] === 'st_overrides', 'Divergent pinned settings must still block export');
    $GLOBALS['_test_config_overrides']['site_name'] = 'Source';
    db()->insert('channels', ['id' => 72, 'name' => 'Retired', 'slug' => 'retired', 'type' => 'page', 'status' => 0]);
    settingModel()->saveBatch(['footer_nav' => '[{"links":[{"url":"/retired.html"}]}]']);
    $preflightBefore = SiteTemplateData::fingerprint();
    $preflight = $service->exportCheck();
    check(count($preflight['issues']) === 2, 'Export preflight must report the omitted link and excluded plugin data');
    check(count(array_filter($preflight['issues'], static fn(array $issue): bool => $issue['code'] === 'usability_export_plugins_excluded')) === 1, 'Active plugin data exclusion is reported');
    check(hash_equals($preflightBefore, SiteTemplateData::fingerprint()), 'Export preflight must not change content');
    db()->delete('channels', 'id = ?', [72]);
    db()->delete('settings', '`key` = ?', ['footer_nav']);
    settingModel()->clearCache();
    $portableContent = $imageHtml
        . '<a href="https://SOURCE.test:443/cms/about.html?next=&sol;contact">About</a>'
        . '<a href="//source.test/cms/contact.html">Contact</a>'
        . '<a href="https://source.test/outside.html">Outside</a>'
        . '<a href="https:&#47;&#47;third.test/cms/about.html">Third</a>'
        . '<img src="https:&#47;&#47;third.test&sol;uploads&sol;missing.png">'
        . '<p>A &sol; B, C &#47; D, E &bsol; F</p>';
    db()->execute('UPDATE ' . DB_PREFIX . 'contents SET content = ? WHERE id = ?', [$portableContent, 91]);
    $zip = $root . '/export.zip';
    $summary = $service->export($zip);
    check($summary['media'] === 5, 'Referenced media only');
    $package = SiteTemplateArchive::read($zip);
    $payload = json_encode($package);
    check(!str_contains($payload, 'PRIVATE'), 'No secrets, drafts or private media');
    $exportedContent = (string) $package['manifest']['data']['tables']['contents'][0]['content'];
    check(!str_contains(strtolower($exportedContent), 'source.test/cms/uploads')
        && str_contains($exportedContent, '/uploads/gallery-1.svg?size=large')
        && str_contains($exportedContent, '/uploads/gallery-2.svg#entity'), 'Same-origin absolute, protocol-relative and entity URLs are portable');
    check(str_contains($exportedContent, 'href="/about.html?next=&sol;contact"')
        && str_contains($exportedContent, 'href="/contact.html"'), 'Same-origin ordinary data links are portable');
    check(str_contains($exportedContent, 'href="https://source.test/outside.html"')
        && str_contains($exportedContent, 'href="https:&#47;&#47;third.test/cms/about.html"')
        && str_contains($exportedContent, 'src="https:&#47;&#47;third.test&sol;uploads&sol;missing.png"')
        && str_contains($exportedContent, 'A &sol; B, C &#47; D, E &bsol; F'), 'Data links outside the deployment path and unrelated entities are byte-stable');
    $exportedHeader = $package['files']['theme/layouts/header.php'];
    check(str_contains($exportedHeader, 'src="/uploads/logo.svg"')
        && str_contains($exportedHeader, 'href="/about.html?next=&sol;contact"')
        && str_contains($exportedHeader, 'href="/contact.html"'), 'Theme same-origin media and ordinary links are portable');
    check(str_contains($exportedHeader, 'href="https://source.test/outside.html"')
        && str_contains($exportedHeader, 'href="https://third.test/cms/about.html"')
        && str_contains($exportedHeader, 'A &sol; B, C &#47; D, E &bsol; F'), 'Out-of-base, third-party and unrelated entity text are byte-stable');
    check($package['manifest']['plugins'] === [
        ['slug' => 'back-to-top', 'version' => '1.0.0'],
        ['slug' => 'cookie-consent', 'version' => '1.1.0'],
    ], 'Active plugin dependencies are declared with versions');
    check(!isset($package['manifest']['data']['tables']['shop_orders']), 'Plugin tables are not exported');
    check(!isset($package['manifest']['data']['settings']['shop_payment_secret'], $package['manifest']['data']['settings']['seo_api_key']), 'Plugin settings and secrets are not exported');
    check(array_filter(array_keys($package['files']), static fn(string $name): bool => str_starts_with($name, 'plugins/')) === [], 'Plugin files are not exported');
    $bad = $package['manifest'];
    $bad['data']['settings']['smtp_pass'] = 'blocked';
    SiteTemplateArchive::write($root . '/bad.zip', $bad, $package['files']);
    rejects(static fn() => SiteTemplateArchive::read($root . '/bad.zip'), 'st_invalid');
    $bad = $package['manifest'];
    $bad['cms'] = '0.0.0';
    SiteTemplateArchive::write($root . '/bad.zip', $bad, $package['files']);
    rejects(static fn() => SiteTemplateArchive::read($root . '/bad.zip'), 'st_schema');
    $bad = $package['manifest'];
    $bad['plugins'] = [['slug' => '../escape', 'version' => '1.0.0']];
    SiteTemplateArchive::write($root . '/bad.zip', $bad, $package['files']);
    rejects(static fn() => SiteTemplateArchive::read($root . '/bad.zip'), 'st_invalid');
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
    $GLOBALS['_test_config_overrides'] = [];
    foreach (SiteTemplateData::TABLES as $table) db()->execute('DELETE FROM ' . DB_PREFIX . $table);
    settingModel()->saveBatch(['current_theme' => 'default', 'site_name' => 'Fresh', 'site_logo' => '', 'site_url' => 'https://target.test']);
    // No-demo installers can retain a legacy channel reference after omitting demo albums.
    db()->insert('channels', ['id' => 88, 'name' => 'Legacy seed', 'slug' => 'legacy-seed', 'album_id' => 999]);
    $service->markFreshInstall();
    rejects(static fn() => $service->markFreshInstall(), 'st_not_fresh');
    $before = SiteTemplateData::fingerprint();
    $preview = $service->prepare($zip, 1);
    rejects(static fn() => $service->apply($preview['token'], 1, [], false), 'st_trust');
    check($preview['missing_plugins'] === [], 'Installed and active plugin dependencies are accepted');
    file_put_contents($root . '/plugins/cookie-consent/plugin.json', '{"name":"Cookie Consent","version":"1.0.0"}');
    $preview = $service->prepare($zip, 1);
    check($preview['missing_plugins'] === [['slug' => 'cookie-consent', 'version' => '1.1.0']], 'An older active plugin does not satisfy the declared version');
    file_put_contents($root . '/plugins/cookie-consent/plugin.json', '{"name":"Cookie Consent","version":"1.2.0"}');
    $preview = $service->prepare($zip, 1);
    check($preview['missing_plugins'] === [], 'A newer active plugin satisfies the declared minimum version');
    pluginModel()->deactivate('cookie-consent');
    $preview = $service->prepare($zip, 1);
    check($preview['missing_plugins'] === [['slug' => 'cookie-consent', 'version' => '1.1.0']], 'Inactive dependency is listed with its required version');
    rejects(static fn() => $service->apply($preview['token'], 1, [], false), 'st_plugin_missing');
    rejects(static fn() => $service->apply($preview['token'], 2, [], true), 'st_stale');
    settingModel()->set('home_blox_data', 'Unsaved new page');
    rejects(static fn() => $service->apply($preview['token'], 1, ['site_name' => 'Target'], true), 'st_not_fresh');
    settingModel()->set('home_blox_data', '{"text":"PRIVATE DRAFT"}');

    // ── E03 分阶段提取：测试环境把每轮压到 2 个文件，真实跨进程验证游标 ──────────
    putenv('APP_ENV=testing');
    putenv('YIKAI_SITE_TEMPLATE_STAGE_MAX_FILES=2');
    if ($mysql) putenv('SITE_TEMPLATE_DB_NAME=' . DB_NAME);
    $stageInChild = static function () use ($root, $databasePath, $mysql, $preview): array {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT_PATH . '/tests/fixtures/site-template-stage-worker.php')
            . ' ' . escapeshellarg($root) . ' ' . escapeshellarg($mysql ? 'mysql' : 'sqlite')
            . ' ' . escapeshellarg($mysql ? DB_NAME : $databasePath) . ' ' . escapeshellarg($preview['token']) . ' 1';
        $lines = [];
        $exit = 0;
        exec($command . ' 2>&1', $lines, $exit);
        check($exit === 0, 'Cross-process stage failed: ' . implode("\n", $lines));
        $result = json_decode((string) end($lines), true);
        check(is_array($result), 'Cross-process stage returned invalid JSON');
        return $result;
    };
    $first = $stageInChild();
    check($first === ['done' => 2, 'total' => 5, 'complete' => false], 'First process is bounded to two media files');
    $planPath = $root . '/storage/site-templates/plan.php';
    $guardBytes = "<?php http_response_code(404); exit; ?>\n";
    $planBytes = (string) file_get_contents($planPath);
    $plan = json_decode(substr($planBytes, strlen($guardBytes)), true, 64, JSON_THROW_ON_ERROR);
    $retryName = $plan['media'][1];
    $retryTarget = $root . '/uploads/' . $plan['alias'] . '/' . substr($retryName, 6);
    touch($retryTarget, 1000000000);
    $plan['staged'] = 1; // Simulate a process dying after rename but before cursor persistence.
    file_put_contents($planPath, $guardBytes . json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $retry = $stageInChild();
    check($retry === ['done' => 3, 'total' => 5, 'complete' => false], 'Retry advances from the persisted cursor without skipping unfinished media');
    clearstatcache(true, $retryTarget);
    check(filemtime($retryTarget) === 1000000000, 'Retry does not rewrite an already completed media file');
    $first = $stageInChild();
    check($first === ['done' => 5, 'total' => 5, 'complete' => true], 'A later process resumes and completes the remaining media');
    // 已完成后再调用是安全的空操作（浏览器重试/双击不该出错）
    $again = $stageInChild();
    check($again['complete'] && $again['done'] === $first['total'], 'Stage is idempotent once complete');
    // 归属与新鲜度在每次 stage 请求上都要重查，不能只在 prepare 时查一次
    rejects(static fn() => $service->stage($preview['token'], 2), 'st_stale');

    $service->apply($preview['token'], 1, ['site_name' => 'Target'], true);
    check(str_starts_with(config('current_theme'), 'sitepack-'), 'New theme alias');
    check(config('site_url') === 'https://target.test' && config('smtp_pass') === 'PRIVATE PASSWORD', 'Target identity unchanged');
    check(db()->fetchColumn('SELECT channel_id FROM yikai_contents WHERE id = 91') == 71, 'Stable relations');
    check(count(db()->fetchAll('SELECT * FROM yikai_media')) === 5, 'Public media imported');
    check((int) db()->fetchColumn('SELECT status FROM yikai_plugins WHERE slug = ?', ['cookie-consent']) === 0, 'Plugin activation state is not imported');
    check((string) db()->fetchColumn('SELECT secret FROM yikai_shop_orders WHERE id = 1') === 'PRIVATE ORDER', 'Plugin table data is not imported or replaced');
    check(config('shop_payment_secret') === 'PRIVATE SHOP SECRET' && config('seo_api_key') === 'PRIVATE SEO KEY', 'Plugin settings and secrets stay local');
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
    if (!$mysql && is_file($databasePath)) {
        $instance = new ReflectionProperty(Database::class, 'instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
        gc_collect_cycles();
        @unlink($databasePath);
    }
}
