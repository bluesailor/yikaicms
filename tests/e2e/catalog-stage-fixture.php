<?php
/** Isolated multilingual catalog data for the stage closure gate. */
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
    throw new RuntimeException('Refusing a non-local fixture');
}
$statePath = ROOT_PATH . '/storage/e2e-catalog-stage.json';
$action = $argv[1] ?? '';
if ($action === 'cleanup') {
    foreach (['contents', 'products', 'channels', 'product_categories'] as $table) {
        db()->delete($table, 'slug LIKE ?', ['e2e-stage-%']);
    }
    if (is_file($statePath)) {
        settingModel()->saveBatch(json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR));
        unlink($statePath);
    }
    exit(0);
}
if ($action !== 'seed' || is_file($statePath)) {
    throw new RuntimeException('Expected seed on a clean fixture');
}
file_put_contents($statePath, json_encode(['enabled_languages' => config('enabled_languages', '')], JSON_THROW_ON_ERROR));
settingModel()->saveBatch(['enabled_languages' => '["zh-CN","en","ja"]']);
$result = [];
foreach (['zh-CN', 'en', 'ja'] as $lang) {
    $product = channelModel()->findWhere(['type' => 'product', 'parent_id' => 0, 'lang' => $lang]);
    $news = channelModel()->findWhere(['type' => 'list', 'parent_id' => 0, 'lang' => $lang]);
    if (!$product || !$news) {
        throw new RuntimeException('Missing translated catalog fixture');
    }
    $category = db()->insert('product_categories', ['name' => 'Stage category ' . $lang,
        'slug' => 'e2e-stage-category-' . $lang, 'lang' => $lang, 'status' => 1]);
    $child = db()->insert('channels', ['name' => 'Stage channel ' . $lang, 'parent_id' => (int) $news['id'],
        'slug' => 'e2e-stage-channel-' . $lang, 'type' => 'list', 'lang' => $lang, 'status' => 1]);
    $result[$lang] = ['product' => (int) $product['id'], 'article' => (int) $news['id'],
        'category' => (int) $category, 'child' => (int) $child];
    for ($i = 1; $i <= 24; $i++) {
        $row = ['title' => 'Stage gate 0 ' . $lang . ' ' . $i, 'slug' => 'e2e-stage-row-' . $lang . '-' . $i,
            'lang' => $lang, 'status' => 1, 'views' => $i, 'created_at' => $i, 'updated_at' => $i];
        db()->insert('products', $row + ['category_id' => $category, 'price' => $i, 'sort_order' => 25 - $i]);
        db()->insert('contents', $row + ['channel_id' => $child, 'type' => 'article', 'publish_time' => $i]);
    }
    foreach (['draft' => ['status' => 0], 'deleted' => ['deleted_at' => 123]] as $suffix => $extra) {
        $row = $extra + ['title' => 'Stage gate 0 ' . $lang . ' ' . $suffix,
            'slug' => 'e2e-stage-' . $suffix . '-' . $lang, 'lang' => $lang, 'status' => 1];
        db()->insert('products', $row + ['category_id' => $category]);
        db()->insert('contents', $row + ['channel_id' => $child, 'type' => 'article']);
    }
    foreach (['case' => ['channel_id' => $child, 'type' => 'case'],
        'outside' => ['channel_id' => $product['id'], 'type' => 'article']] as $suffix => $extra) {
        db()->insert('contents', $extra + ['title' => 'Stage gate 0 ' . $lang . ' ' . $suffix,
            'slug' => 'e2e-stage-' . $suffix . '-' . $lang, 'lang' => $lang, 'status' => 1]);
    }
    db()->insert('contents', ['title' => 'Stage gate 0 foreign row in ' . $lang,
        'slug' => 'e2e-stage-foreign-' . $lang, 'channel_id' => $child, 'type' => 'article',
        'lang' => $lang === 'en' ? 'ja' : 'en', 'status' => 1]);
}
echo json_encode($result, JSON_THROW_ON_ERROR);
