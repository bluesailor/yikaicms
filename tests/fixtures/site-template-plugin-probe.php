<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__, 2));
define('DB_DRIVER', 'sqlite');
define('DB_PATH', ':memory:');
define('DB_PREFIX', 'yikai_');
define('DB_CHARSET', 'utf8mb4');
define('DEBUG', true);
function config(string $key, mixed $default = ''): mixed { return settingModel()->get($key, $default); }
function configOverrides(): array { return []; }
function __(string $key, array $params = []): string { return $key; }
require ROOT_PATH . '/config/version.php';
require ROOT_PATH . '/config/database.php';
require ROOT_PATH . '/includes/models/autoload.php';
require ROOT_PATH . '/includes/hooks.php';
require ROOT_PATH . '/includes/SiteTemplateService.php';

function pluginCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function pluginRejects(callable $action, string $code): void
{
    try { $action(); } catch (RuntimeException $error) {
        pluginCheck($error->getMessage() === $code, $code . ': ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $code);
}

$schemaVersion = 1;
$failImport = false;
$importCalls = [];
$schemaHash = static function (int $version): string {
    return 'sha256:' . hash('sha256', json_encode([
        'contract' => $version,
        'table' => 'demo_catalog',
        'columns' => ['product_id:int', 'sku:string', 'price:?decimal-string'],
        'settings' => ['demo_display:bool-string'],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
};
$exportEnvelope = static function () use (&$schemaVersion, $schemaHash): array {
    $rows = db()->fetchAll('SELECT product_id, sku, price FROM ' . DB_PREFIX . 'demo_catalog ORDER BY product_id');
    foreach ($rows as &$row) {
        $row['product_id'] = (int) $row['product_id'];
        $row['sku'] = (string) $row['sku'];
        $row['price'] = $row['price'] === null ? null : (string) $row['price'];
    }
    unset($row);
    $payload = ['products' => $rows, 'settings' => ['demo_display' => (string) settingModel()->get('demo_display', '0')]];
    $privateCount = (int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'demo_orders');
    return [
        'contract' => 1,
        'schema' => ['id' => 'portable-demo/catalog', 'version' => $schemaVersion, 'sha256' => $schemaHash($schemaVersion)],
        'payload' => $payload,
        'state' => [
            'sha256' => 'sha256:' . hash('sha256', json_encode([$payload, 'private_orders' => $privateCount], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'replaceable' => $privateCount === 0,
        ],
    ];
};

add_filter('site_template_plugin_export', static function (mixed $entry, string $slug) use ($exportEnvelope): mixed {
    return $slug === 'portable-demo' ? $exportEnvelope() : $entry;
});
add_action('site_template_plugin_import', static function (array $payload, string $slug) use (&$failImport, &$importCalls): void {
    if ($slug !== 'portable-demo') return;
    $importCalls[] = [$slug, $payload];
    if (array_keys($payload) !== ['products', 'settings'] || !is_array($payload['products'])
        || $payload['settings'] !== ['demo_display' => (string) ($payload['settings']['demo_display'] ?? '')]
        || !in_array($payload['settings']['demo_display'], ['0', '1'], true)) throw new RuntimeException('st_invalid');
    if ((int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'demo_orders') !== 0) throw new RuntimeException('st_not_fresh');
    db()->execute('DELETE FROM ' . DB_PREFIX . 'demo_catalog');
    foreach ($payload['products'] as $row) {
        if (!is_array($row) || array_keys($row) !== ['price', 'product_id', 'sku'] || !is_int($row['product_id'])
            || !is_string($row['sku']) || ($row['price'] !== null && !is_string($row['price']))) throw new RuntimeException('st_invalid');
        db()->insert('demo_catalog', $row);
        if ($failImport) throw new RuntimeException('probe rollback');
    }
    settingModel()->saveBatch($payload['settings']);
    settingModel()->clearCache();
});

$root = sys_get_temp_dir() . '/yk-plugin-site-' . bin2hex(random_bytes(8));
mkdir($root . '/themes/sample/layouts', 0700, true);
mkdir($root . '/uploads', 0700, true);
try {
    db()->getPdo()->exec((string) file_get_contents(ROOT_PATH . '/install/sql/sqlite.sql'));
    foreach (SiteTemplateData::TABLES as $table) db()->execute('DELETE FROM ' . DB_PREFIX . $table);
    db()->execute('DELETE FROM ' . DB_PREFIX . 'settings');
    settingModel()->clearCache();
    db()->execute('CREATE TABLE ' . DB_PREFIX . 'demo_catalog (product_id INTEGER PRIMARY KEY, sku TEXT NOT NULL, price TEXT NULL)');
    db()->execute('CREATE TABLE ' . DB_PREFIX . 'demo_orders (id INTEGER PRIMARY KEY, customer_secret TEXT NOT NULL)');
    settingModel()->saveBatch([
        'current_theme' => 'sample', 'site_name' => 'Source', 'demo_display' => '1',
        'demo_payment_secret' => 'PRIVATE KEY',
    ]);
    foreach (['portable-demo' => '1.0.0', 'stateless-demo' => '1.0.0'] as $slug => $version) {
        mkdir($root . '/plugins/' . $slug, 0700, true);
        file_put_contents($root . '/plugins/' . $slug . '/plugin.json', json_encode(['name' => $slug, 'version' => $version]));
        db()->insert('plugins', ['slug' => $slug, 'status' => 1, 'installed_at' => time(), 'activated_at' => time()]);
    }
    db()->insert('products', ['id' => 41, 'title' => 'Demo', 'slug' => 'demo', 'translation_group_id' => 41, 'status' => 1]);
    db()->insert('demo_catalog', ['product_id' => 41, 'sku' => 'PUBLIC-SKU', 'price' => '12.30']);
    db()->insert('demo_orders', ['id' => 1, 'customer_secret' => 'PRIVATE CUSTOMER']);
    file_put_contents($root . '/themes/sample/theme.json', '{"name":"Sample","version":"1.0.0"}');
    foreach (['header', 'footer'] as $name) file_put_contents($root . '/themes/sample/layouts/' . $name . '.php', '<?php declare(strict_types=1); ?>Sample');

    $service = new SiteTemplateService($root);
    $zip = $root . '/export.zip';
    $service->export($zip);
    $package = SiteTemplateArchive::read($zip);
    pluginCheck($package['manifest']['version'] === 2, 'Plugin data requires format v2');
    pluginCheck($package['manifest']['plugin_data'] === ['portable-demo' => 'plugin-data/portable-demo.json'], 'Manifest owns fixed plugin path');
    pluginCheck(isset($package['manifest']['files']['plugin-data/portable-demo.json']), 'Plugin JSON participates in file sha256');
    pluginCheck($package['plugin_data']['portable-demo']['payload']['products'][0]['sku'] === 'PUBLIC-SKU', 'Public payload decodes');
    $archiveText = json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    pluginCheck(!str_contains($archiveText, 'PRIVATE CUSTOMER') && !str_contains($archiveText, 'PRIVATE KEY'), 'Private rows/settings never export');
    pluginCheck(!str_contains($package['files']['plugin-data/portable-demo.json'], '"state"'), 'Target-only state never enters package');

    $writeDerivedPackage = static function (string $path, array $manifest, array $files): void {
        $derived = new ZipArchive();
        pluginCheck($derived->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 'Derived fixture opens');
        pluginCheck($derived->addFromString('site.json', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 'Derived manifest writes');
        foreach ($files as $name => $bytes) pluginCheck($derived->addFromString($name, $bytes), 'Derived entry writes');
        pluginCheck($derived->close(), 'Derived fixture closes');
    };
    $rejectBothReaders = static function (string $path): void {
        pluginRejects(static fn() => SiteTemplateArchive::inspect($path), 'st_missing_media');
        pluginRejects(static fn() => SiteTemplateArchive::read($path), 'st_missing_media');
    };

    $pluginMissingFiles = $package['files'];
    $pluginMissingManifest = $package['manifest'];
    $pluginDocument = json_decode($pluginMissingFiles['plugin-data/portable-demo.json'], true, 32, JSON_THROW_ON_ERROR);
    $pluginDocument['payload']['image'] = '/uploads/plugin-only.webp';
    $pluginMissingFiles['plugin-data/portable-demo.json'] = json_encode($pluginDocument, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $pluginMissingManifest['files']['plugin-data/portable-demo.json'] = hash('sha256', $pluginMissingFiles['plugin-data/portable-demo.json']);
    $pluginMissing = $root . '/plugin-missing-media.zip';
    $writeDerivedPackage($pluginMissing, $pluginMissingManifest, $pluginMissingFiles);
    $rejectBothReaders($pluginMissing);

    $coreMissingManifest = $package['manifest'];
    $coreMissingManifest['data']['settings']['site_logo'] = '/uploads/core-only.webp';
    $coreMissing = $root . '/core-missing-media.zip';
    $writeDerivedPackage($coreMissing, $coreMissingManifest, $package['files']);
    $rejectBothReaders($coreMissing);

    $themeMissingFiles = $package['files'];
    $themeMissingManifest = $package['manifest'];
    $themeMissingFiles['theme/layouts/header.php'] .= '<img src="/uploads/theme-only.webp">';
    $themeMissingManifest['files']['theme/layouts/header.php'] = hash('sha256', $themeMissingFiles['theme/layouts/header.php']);
    $themeMissing = $root . '/theme-missing-media.zip';
    $writeDerivedPackage($themeMissing, $themeMissingManifest, $themeMissingFiles);
    $rejectBothReaders($themeMissing);

    $tampered = $root . '/tampered.zip';
    copy($zip, $tampered);
    $tamperZip = new ZipArchive();
    pluginCheck($tamperZip->open($tampered) === true, 'Tamper fixture opens');
    $tamperZip->addFromString('plugin-data/portable-demo.json', str_replace('PUBLIC-SKU', 'TAMPERED-X', $package['files']['plugin-data/portable-demo.json']));
    $tamperZip->close();
    pluginRejects(static fn() => SiteTemplateArchive::inspect($tampered), 'st_invalid');

    foreach (SiteTemplateData::TABLES as $table) db()->execute('DELETE FROM ' . DB_PREFIX . $table);
    db()->execute('DELETE FROM ' . DB_PREFIX . 'demo_catalog');
    db()->execute('DELETE FROM ' . DB_PREFIX . 'demo_orders');
    settingModel()->saveBatch([
        'current_theme' => 'default', 'site_name' => 'Fresh', 'demo_display' => '0',
        'demo_payment_secret' => 'TARGET KEY',
    ]);
    $service->markFreshInstall();
    $before = SiteTemplateData::fingerprint();

    $schemaVersion = 2;
    pluginRejects(static fn() => $service->prepare($zip, 1), 'st_schema');
    pluginCheck(!is_dir($root . '/uploads/sitepack-'), 'Schema mismatch rejects before staging');
    $schemaVersion = 1;

    db()->update('plugins', ['status' => 0], 'slug = ?', ['portable-demo']);
    $preview = $service->prepare($zip, 1);
    pluginCheck(count($preview['missing_plugins']) === 1, 'Missing data plugin is visible');
    pluginRejects(static fn() => $service->apply($preview['token'], 1, ['site_name' => 'Target'], false), 'st_plugin_missing');
    $callsBeforeSkip = count($importCalls);
    $service->apply($preview['token'], 1, ['site_name' => 'Target'], true);
    pluginCheck(count($importCalls) === $callsBeforeSkip && (int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'demo_catalog') === 0,
        'Trusted import skips only the missing plugin payload');
    $service->restore();
    pluginCheck(hash_equals($before, SiteTemplateData::fingerprint()), 'Core state restores after a skipped plugin');

    db()->update('plugins', ['status' => 1], 'slug = ?', ['portable-demo']);
    $preview = $service->prepare($zip, 1);
    $service->stage($preview['token'], 1);
    $failImport = true;
    $failed = false;
    try { $service->apply($preview['token'], 1, ['site_name' => 'Target'], true); } catch (Throwable $error) { $failed = true; }
    $failImport = false;
    pluginCheck($failed && hash_equals($before, SiteTemplateData::fingerprint())
        && (int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'demo_catalog') === 0,
        'Plugin failure rolls core and plugin state back together');

    $preview = $service->prepare($zip, 1);
    $service->stage($preview['token'], 1);
    $service->apply($preview['token'], 1, ['site_name' => 'Target'], true);
    $sale = db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'demo_catalog WHERE product_id = ?', [41]);
    pluginCheck($sale !== null && $sale['sku'] === 'PUBLIC-SKU' && config('demo_display') === '1'
        && config('demo_payment_secret') === 'TARGET KEY', 'Public plugin state imports without target secret changes');
    pluginCheck(($service->recovery()['can_restore'] ?? false) === true, 'Unchanged plugin state is recoverable');
    db()->insert('demo_orders', ['id' => 2, 'customer_secret' => 'NEW CUSTOMER']);
    pluginCheck(($service->recovery()['can_restore'] ?? true) === false, 'New private activity closes recovery');
    pluginRejects(static fn() => $service->restore(), 'st_restore_changed');
    db()->delete('demo_orders', 'id = ?', [2]);
    $service->restore();
    pluginCheck(hash_equals($before, SiteTemplateData::fingerprint())
        && (int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'demo_catalog') === 0
        && config('demo_display') === '0', 'Persistent restore replays the plugin backup through the same hook');
    echo "Site template plugin roundtrip passed\n";
} finally {
    $resolved = realpath($root);
    $temporary = realpath(sys_get_temp_dir());
    if ($resolved !== false && $temporary !== false && str_starts_with($resolved, $temporary . DIRECTORY_SEPARATOR)
        && str_starts_with(basename($resolved), 'yk-plugin-site-')) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) { if ($entry->isDir()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
        rmdir($resolved);
    }
}
