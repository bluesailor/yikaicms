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
require ROOT_PATH . '/includes/SiteTemplateService.php';
function pluginCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function pluginRejects(callable $action, string $code): void {
    try { $action(); } catch (RuntimeException $e) { pluginCheck($e->getMessage() === $code, $code . ': ' . $e->getMessage()); return; }
    throw new RuntimeException('Expected rejection: ' . $code);
}
$root = sys_get_temp_dir() . '/yk-plugin-site-' . bin2hex(random_bytes(8));
mkdir($root . '/themes/sample/layouts', 0700, true);
mkdir($root . '/uploads', 0700, true);
try {
    db()->getPdo()->exec((string) file_get_contents(ROOT_PATH . '/install/sql/sqlite.sql'));
    foreach (SiteTemplateData::TABLES as $table) db()->execute('DELETE FROM ' . DB_PREFIX . $table);
    db()->execute('DELETE FROM ' . DB_PREFIX . 'settings');
    settingModel()->clearCache();
    db()->execute('CREATE TABLE ' . DB_PREFIX . 'shop_products (product_id INTEGER PRIMARY KEY, sku TEXT, price TEXT NULL, stock INTEGER, status INTEGER, specs_json TEXT NULL, sales INTEGER DEFAULT 0, created_at INTEGER DEFAULT 0, updated_at INTEGER DEFAULT 0)');
    db()->execute('CREATE TABLE ' . DB_PREFIX . 'shop_orders (id INTEGER PRIMARY KEY, secret TEXT)');
    settingModel()->saveBatch(['current_theme' => 'sample', 'site_name' => 'Source', 'show_price' => '1', 'shop_payment_secret' => 'PRIVATE KEY']);
    foreach (['stay-inquiry' => '1.0.0', 'pet-cart-demo' => '1.1.0', 'shop' => '0.8.0'] as $slug => $version) {
        mkdir($root . '/plugins/' . $slug, 0700, true);
        file_put_contents($root . '/plugins/' . $slug . '/plugin.json', json_encode(['name' => $slug, 'version' => $version]));
        file_put_contents($root . '/plugins/' . $slug . '/main.php', '<?php throw new RuntimeException("Must not execute");');
        db()->insert('plugins', ['slug' => $slug, 'status' => 1, 'installed_at' => time(), 'activated_at' => time()]);
    }
    db()->insert('products', ['id' => 41, 'title' => 'Room', 'slug' => 'room', 'translation_group_id' => 41, 'status' => 1]);
    db()->insert('products', ['id' => 42, 'title' => 'Room translation', 'slug' => 'room-en', 'translation_group_id' => 41, 'status' => 1, 'lang' => 'en']);
    db()->insert('form_templates', ['id' => 51, 'name' => 'Inquiry', 'slug' => 'room-booking', 'fields' => '[]']);
    $variant = ['id' => substr(hash('sha256', "large\0SKU-L"), 0, 20), 'label' => 'Large', 'sku' => 'SKU-L', 'price' => '12.30', 'stock' => 6];
    db()->insert('shop_products', ['product_id' => 41, 'sku' => 'DEMO', 'price' => null, 'stock' => 6, 'status' => 1, 'specs_json' => json_encode([$variant]), 'sales' => 4321]);
    db()->insert('shop_products', ['product_id' => 999, 'sku' => 'PRIVATE SKU', 'stock' => 1, 'status' => 1]);
    db()->insert('shop_orders', ['id' => 1, 'secret' => 'PRIVATE CUSTOMER']);
    file_put_contents($root . '/themes/sample/theme.json', '{"name":"Sample","version":"1.0.0"}');
    foreach (['header', 'footer'] as $name) file_put_contents($root . '/themes/sample/layouts/' . $name . '.php', '<?php declare(strict_types=1); ?>Sample');
    $service = new SiteTemplateService($root);
    $zip = $root . '/export.zip';
    $service->export($zip);
    $package = SiteTemplateArchive::read($zip);
    pluginCheck($package['manifest']['version'] === 2, 'Plugin contracts require a format older importers reject');
    $downgraded = $package['manifest'];
    $downgraded['version'] = 1;
    SiteTemplateArchive::write($root . '/bad.zip', $downgraded, $package['files']);
    pluginRejects(static fn() => SiteTemplateArchive::read($root . '/bad.zip'), 'st_invalid');
    $payload = $package['manifest']['plugin_data'];
    pluginCheck(count($payload['shop']['products']) === 1, 'Only canonical published products export');
    pluginCheck($payload['shop']['products'][0]['product_id'] === 41, 'Translation stock is shared');
    pluginCheck($payload['shop']['settings'] === ['show_price' => '1'], 'Public display preference exports');
    pluginCheck(!str_contains(json_encode($package), 'PRIVATE') && !str_contains(json_encode($package), '4321'), 'Private data and sales statistics never export');
    foreach (['secret-setting', 'private-column', 'dangling-product', 'missing-adapter', 'private-variant'] as $case) {
        $bad = $package['manifest'];
        if ($case === 'secret-setting') $bad['plugin_data']['shop']['settings']['shop_payment_secret'] = 'blocked';
        if ($case === 'private-column') $bad['plugin_data']['shop']['products'][0]['sales'] = 1;
        if ($case === 'dangling-product') $bad['plugin_data']['shop']['products'][0]['product_id'] = 999;
        if ($case === 'missing-adapter') unset($bad['plugin_data']['shop']);
        if ($case === 'private-variant') { $badVariant = $variant + ['secret' => 'blocked']; $bad['plugin_data']['shop']['products'][0]['specs_json'] = json_encode([$badVariant]); }
        SiteTemplateArchive::write($root . '/bad.zip', $bad, $package['files']);
        pluginRejects(static fn() => SiteTemplateArchive::read($root . '/bad.zip'), $case === 'dangling-product' ? 'st_references' : 'st_invalid');
    }
    foreach (SiteTemplateData::TABLES as $table) db()->execute('DELETE FROM ' . DB_PREFIX . $table);
    db()->execute('DELETE FROM ' . DB_PREFIX . 'shop_products');
    db()->execute('DELETE FROM ' . DB_PREFIX . 'shop_orders');
    settingModel()->saveBatch(['current_theme' => 'default', 'site_name' => 'Fresh', 'show_price' => '0', 'shop_payment_secret' => 'TARGET KEY']);
    $service->markFreshInstall();
    $before = SiteTemplateData::fingerprint();
    db()->update('plugins', ['status' => 0], 'slug = ?', ['shop']);
    $preview = $service->prepare($zip, 1);
    pluginCheck(count($preview['missing_plugins']) === 1, 'Missing shop dependency is visible');
    pluginRejects(static fn() => $service->apply($preview['token'], 1, ['site_name' => 'Target'], true), 'st_plugin_missing');
    db()->update('plugins', ['status' => 1], 'slug = ?', ['shop']);
    $preview = $service->prepare($zip, 1);
    $service->stage($preview['token'], 1);
    db()->execute('CREATE TRIGGER reject_plugin_insert BEFORE INSERT ON ' . DB_PREFIX . "shop_products BEGIN SELECT RAISE(ABORT, 'probe rollback'); END");
    $failed = false;
    try { $service->apply($preview['token'], 1, ['site_name' => 'Target'], true); } catch (Throwable $error) { $failed = true; }
    pluginCheck($failed && hash_equals($before, SiteTemplateData::fingerprint()), 'Plugin insertion failure rolls core and plugin state back together');
    db()->execute('DROP TRIGGER reject_plugin_insert');
    $preview = $service->prepare($zip, 1);
    $service->stage($preview['token'], 1);
    $service->apply($preview['token'], 1, ['site_name' => 'Target'], true);
    $sale = db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'shop_products WHERE product_id = ?', [41]);
    pluginCheck((int) $sale['stock'] === 6 && (int) $sale['sales'] === 0 && $sale['specs_json'] !== null, 'Sales config imports without old transaction statistics');
    pluginCheck(config('show_price') === '1' && config('shop_payment_secret') === 'TARGET KEY', 'Only whitelisted display settings change');
    pluginCheck((int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'shop_orders') === 0, 'No customer orders imported');
    db()->insert('shop_orders', ['id' => 2, 'secret' => 'NEW CUSTOMER']);
    pluginRejects(static fn() => $service->restore(), 'st_restore_changed');
    db()->delete('shop_orders', 'id = ?', [2]);
    $service->restore();
    pluginCheck(hash_equals($before, SiteTemplateData::fingerprint()), 'Persistent restore reproduces public plugin state and settings');
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
