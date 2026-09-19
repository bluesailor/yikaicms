<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-')
    || !is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) throw new RuntimeException('Disposable site required');
$mode = $argv[1] ?? '';
$lang = $argv[2] ?? 'en';
if (!in_array($lang, ['zh-CN', 'en', 'ja'], true)) throw new RuntimeException('Invalid language');
define('SITE_LANG', $lang);
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') throw new RuntimeException('Local SQLite required');
$backup = ROOT_PATH . '/storage/home-language-carousel.json';
$state = is_file($backup) ? json_decode((string) file_get_contents($backup), true, 512, JSON_THROW_ON_ERROR) : null;
if ($mode === 'restore') {
    if ($state !== null) {
        foreach ($state['ids'] as $id) db()->delete('products', 'id = ?', [$id]);
        foreach ($state['settings'] as $key => $row) {
            db()->delete('settings', '"key" = ?', [$key]);
            if ($row !== null) db()->insert('settings', $row);
        }
        if (array_key_exists('plugin', $state)) {
            db()->delete('plugins', 'slug = ?', ['product-carousel']);
            if ($state['plugin'] !== null) db()->insert('plugins', $state['plugin']);
        }
        unlink($backup);
    }
    exit;
}
if ($mode === 'prepare' && $state === null) {
    // 安装种子自 v1.20.1 起不再登记不随包的插件；测试站由源码复制，插件目录在，夹具自行启用。
    if (!is_file(ROOT_PATH . '/plugins/product-carousel/plugin.json')) {
        throw new RuntimeException('Carousel plugin source missing');
    }
    $pluginRow = db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'plugins WHERE slug = ?', ['product-carousel']);
    if ((int) ($pluginRow['status'] ?? 0) !== 1) pluginModel()->activate('product-carousel');
    $state = ['ids' => [], 'groups' => [], 'settings' => [], 'plugin' => $pluginRow ?: null];
    foreach (['home_blocks_config', 'blox_custom_header_enabled', 'blox_custom_footer_enabled'] as $key) {
        $state['settings'][$key] = db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'settings WHERE "key" = ?', [$key]);
    }
    file_put_contents($backup, json_encode($state, JSON_THROW_ON_ERROR));
    $titles = ['zh-CN' => '测试网关', 'en' => 'Test Gateway', 'ja' => 'テストゲートウェイ'];
    foreach (['a', 'b', 'missing', 'hidden', 'off', 'deleted'] as $group) {
        $rootId = 0;
        $languages = $group === 'missing' ? ['zh-CN'] : ($group === 'b' ? ['en', 'zh-CN', 'ja'] : ['zh-CN', 'en', 'ja']);
        foreach ($languages as $language) {
            $row = db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'products WHERE lang = ? AND status = 1 AND deleted_at IS NULL ORDER BY id LIMIT 1', [$language]);
            if (!$row) throw new RuntimeException('Missing localized seed');
            unset($row['id']);
            $row['title'] = $titles[$language] . ' ' . $group;
            $row['slug'] = 'e8-carousel-' . $group . '-' . strtolower($language);
            $row['translation_group_id'] = $rootId;
            $row['status'] = ($group === 'hidden' && $language === 'en') || ($group === 'off' && $language === 'zh-CN') ? 0 : 1;
            $row['deleted_at'] = ($group === 'hidden' && $language === 'ja') || ($group === 'deleted' && $language === 'zh-CN') ? time() : null;
            $id = (int) db()->insert('products', $row);
            $rootId = $rootId ?: $id;
            $state['ids'][] = $id;
            $state['groups'][$group][$language] = $id;
            file_put_contents($backup, json_encode($state, JSON_THROW_ON_ERROR));
        }
    }
    $g = $state['groups'];
    settingModel()->saveBatch(['blox_custom_header_enabled' => '0', 'blox_custom_footer_enabled' => '0',
        'home_blocks_config' => json_encode([['type' => 'product_carousel', 'enabled' => true, 'title' => 'Customer selected products',
            'per_row' => 4, 'autoplay' => 0, 'product_ids' => [$g['b']['zh-CN'], $g['a']['zh-CN'], $g['missing']['zh-CN'],
                $g['hidden']['zh-CN'], $g['off']['zh-CN'], $g['deleted']['zh-CN'], $g['a']['zh-CN'], $g['b']['en']]]], JSON_THROW_ON_ERROR)]);
    exit;
}
if ($state === null) throw new RuntimeException('Prepare fixture first');
if ($mode === 'snapshot') {
    $rows = [];
    foreach ($state['ids'] as $id) $rows[] = db()->fetchOne('SELECT id, title, lang, translation_group_id, status, deleted_at FROM '
        . DB_PREFIX . 'products WHERE id = ?', [$id]);
    echo json_encode(['products' => $rows, 'blocks' => config('home_blocks_config')], JSON_THROW_ON_ERROR);
    exit;
}
if ($mode !== 'manifest') throw new RuntimeException('Invalid mode');
$g = $state['groups'];
$ids = [$g['b'][$lang], $g['a'][$lang], $g['missing']['zh-CN'], $g['hidden']['zh-CN'], $g['a'][$lang], $g['b'][$lang]];
$expected = [];
foreach ($ids as $id) {
    $row = productModel()->getPublished($id);
    if (!$row) throw new RuntimeException('Missing expected product');
    $expected[] = ['title' => $row['title'], 'url' => productUrl($row)];
}
echo json_encode($expected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
