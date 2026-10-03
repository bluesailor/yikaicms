<?php
declare(strict_types=1);
// WordPress 导入 e2e：在一次性测试站上建迷你 WordPress 库（SQLite）、把站点默认语言切成英文并导入；restore 删掉导入的全部内容并还原设置。
if (PHP_SAPI !== 'cli') exit(1);
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-') || !is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) throw new RuntimeException('Disposable site required');
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
require ROOT_PATH . '/tests/fixtures/wordpress-fixture.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') throw new RuntimeException('Local SQLite required');
$state = STORAGE_PATH . '/e2e-wp-import.json';
$wpDb = STORAGE_PATH . '/e2e-wordpress.sqlite';
$action = $argv[1] ?? '';
if ($action === 'setup') {
    if (is_file($state)) throw new RuntimeException('Unclean fixture');
    $settings = [];
    foreach (['site_lang', 'enabled_languages', 'url_mode', 'html_cache_enabled'] as $key) $settings[$key] = config($key, '');
    file_put_contents($state, json_encode(['settings' => $settings], JSON_THROW_ON_ERROR));
    settingModel()->saveBatch(['site_lang' => 'en', 'enabled_languages' => json_encode(['en', 'ja']), 'url_mode' => 'pretty', 'html_cache_enabled' => '0']);
    @unlink($wpDb);
    echo json_encode(wordPressFixture(new PDO('sqlite:' . $wpDb)), JSON_THROW_ON_ERROR);
} elseif ($action === 'restore' && is_file($state)) {
    $saved = json_decode((string) file_get_contents($state), true, 512, JSON_THROW_ON_ERROR);
    $tables = ['category' => ['channels', 'channel'], 'page' => ['channels', 'channel'], 'post' => ['contents', 'content'], 'product' => ['products', 'product'],
        'product_cat' => ['product_categories', 'category'], 'product_tag' => ['product_tags', 'product_tag'], 'post_tag' => ['metas', 'content_tag']];
    foreach (db()->fetchAll('SELECT owner_id, meta_key, meta_value FROM ' . DB_PREFIX . "metas WHERE owner_type = 'wp_import'") as $row) {
        [$table, $kind] = $tables[$row['meta_key']] ?? [null, null];
        if ($table === null) continue;
        $id = (int) $row['meta_value'];
        productRouteModel()->remove($kind, $id);
        db()->delete($table, 'id = ?', [$id]);
        if ($table === 'products') { db()->delete('product_tag_map', 'product_id = ?', [$id]); db()->delete('metas', "owner_type = 'product' AND owner_id = ?", [$id]); }
    }
    db()->delete('metas', "owner_type = 'wp_import'");
    settingModel()->saveBatch($saved['settings']);
    settingModel()->rotateHtmlCacheGeneration();
    @unlink($wpDb);
    unlink($state);
} else throw new RuntimeException('Invalid action');
