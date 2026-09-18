<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-') || !is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) throw new RuntimeException('Disposable site required');
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') throw new RuntimeException('Local SQLite required');
$file = STORAGE_PATH . '/e2e-product-urls.json';
$action = $argv[1] ?? '';
if ($action === 'setup') {
    if (is_file($file)) throw new RuntimeException('Unclean fixture');
    $settings = [];
    foreach (['url_mode', 'catalog_product_page_size', 'html_cache_enabled'] as $key) $settings[$key] = config($key, '');
    settingModel()->saveBatch(['url_mode' => 'pretty', 'catalog_product_page_size' => '1', 'html_cache_enabled' => '0']);
    $migration = require ROOT_PATH . '/migrations/20260910_product_custom_urls.php';
    ($migration['php'])();
    $cat = (int) productCategoryModel()->create(['name' => 'WP route category', 'slug' => 'wp-route-category', 'lang' => 'zh-CN', 'status' => 1, 'parent_id' => 0, 'created_at' => time()]);
    $seed = productModel()->find(1);
    if (!$seed) throw new RuntimeException('Demo product required');
    unset($seed['id']);
    $ids = [];
    foreach ([1, 2, 3] as $number) {
        $ids[] = (int) productModel()->create(array_replace($seed, ['title' => 'WP Gateway ' . $number, 'slug' => 'wp-gateway-' . $number,
            'category_id' => $cat, 'lang' => 'zh-CN', 'status' => 1, 'deleted_at' => null, 'sort_order' => $number, 'is_top' => 0]));
    }
    $state = ['cat' => $cat, 'ids' => $ids, 'settings' => $settings];
    file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR));
    echo json_encode($state, JSON_THROW_ON_ERROR);
} elseif ($action === 'restore' && is_file($file)) {
    $state = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    foreach ($state['ids'] as $id) {
        db()->delete('products', 'id = ?', [$id]);
        db()->delete('product_routes', 'entity_type = ? AND entity_id = ?', ['product', $id]);
    }
    db()->delete('product_categories', 'id = ?', [$state['cat']]);
    db()->delete('product_routes', 'entity_type = ? AND entity_id = ?', ['category', $state['cat']]);
    settingModel()->saveBatch($state['settings']);
    unlink($file);
} elseif ($action === 'dynamic') {
    settingModel()->saveBatch(['url_mode' => 'query']);
} else throw new RuntimeException('Invalid action');
