<?php
/** CLI-only rows for the disposable catalog search integration site. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
define('ROOT_PATH', dirname(__DIR__, 2));
if (!is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) {
    throw new RuntimeException('An active disposable smoke site is required');
}
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Refusing to seed a non-local SQLite fixture');
}
$action = $argv[1] ?? '';
if (!in_array($action, ['seed', 'cleanup'], true)) {
    throw new RuntimeException('Expected seed or cleanup');
}
$fixtures = json_decode((string) file_get_contents(__DIR__ . '/../smoke/fixtures.json'), true, 512, JSON_THROW_ON_ERROR);
db()->beginTransaction();
try {
    foreach (['products' => ['category_id', 'product_cat'], 'contents' => ['channel_id', 'channel_list']] as $table => [$parent, $fixture]) {
        db()->delete($table, 'slug LIKE ?', ['e2e-catalog-zero-%']);
        if ($action === 'cleanup') {
            continue;
        }
        foreach ([0, 1] as $status) {
            for ($i = 1; $i <= 22; $i++) {
                $row = [$parent => (int) $fixtures[$fixture], 'lang' => siteLang(), 'status' => $status,
                    'title' => 'Catalog Zero 0 ' . $status . ' ' . $i,
                    'slug' => 'e2e-catalog-zero-' . $status . '-' . $i];
                if ($table === 'contents') {
                    $row['type'] = 'article';
                }
                db()->insert($table, $row);
            }
            db()->insert($table, [$parent => (int) $fixtures[$fixture], 'lang' => siteLang(),
                'status' => $status, 'title' => 'Catalog Unrelated',
                'slug' => 'e2e-catalog-zero-unrelated-' . $status]);
        }
    }
    db()->commit();
} catch (Throwable $error) {
    db()->rollback();
    throw $error;
}
echo "Catalog fixture ready\n";
