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
    foreach (['site_lang', 'enabled_languages', 'url_mode', 'html_cache_enabled', 'wp_legacy_urls'] as $key) $settings[$key] = config($key, '');
    file_put_contents($state, json_encode(['settings' => $settings], JSON_THROW_ON_ERROR));
    settingModel()->saveBatch(['site_lang' => 'en', 'enabled_languages' => json_encode(['en', 'ja']), 'url_mode' => 'pretty', 'html_cache_enabled' => '0']);
    @unlink($wpDb);
    echo json_encode(wordPressFixture(new PDO('sqlite:' . $wpDb)), JSON_THROW_ON_ERROR);
} elseif ($action === 'restore' && is_file($state)) {
    $saved = json_decode((string) file_get_contents($state), true, 512, JSON_THROW_ON_ERROR);
    $tables = ['category' => ['channels', 'channel'], 'page' => ['channels', 'channel'], 'post' => ['contents', 'content'], 'product' => ['products', 'product'],
        'product_cat' => ['product_categories', 'category'], 'product_tag' => ['product_tags', 'product_tag'], 'post_tag' => ['metas', 'content_tag'],
        'form' => ['form_templates', null], 'nav_menu' => ['nav_menus', null]];
    foreach (db()->fetchAll('SELECT owner_id, meta_key, meta_value FROM ' . DB_PREFIX . "metas WHERE owner_type = 'wp_import'") as $row) {
        if (str_starts_with((string) $row['meta_key'], 'acf_field:')) {
            // ACF 导入的扩展字段：定义、类型配置、各条目上的值
            $field = db()->fetchOne('SELECT owner_type, field_key FROM ' . DB_PREFIX . 'extfields WHERE id = ?', [(int) $row['meta_value']]);
            if ($field) db()->delete('metas', 'owner_type = ? AND meta_key = ?', [$field['owner_type'], $field['field_key']]);
            db()->delete('metas', "owner_type = 'extfield' AND owner_id = ?", [(int) $row['meta_value']]);
            db()->delete('extfields', 'id = ?', [(int) $row['meta_value']]);
            continue;
        }
        [$table, $kind] = $tables[$row['meta_key']] ?? [null, null];
        if ($table === null) continue;
        $id = (int) $row['meta_value'];
        if ($kind !== null) productRouteModel()->remove($kind, $id);
        db()->delete($table, 'id = ?', [$id]);
        if ($table === 'products') { db()->delete('product_tag_map', 'product_id = ?', [$id]); db()->delete('metas', "owner_type = 'product' AND owner_id = ?", [$id]); }
    }
    db()->delete('metas', "owner_type IN ('wp_import', 'form_template_lang', 'nav_menu_lang')");
    settingModel()->saveBatch($saved['settings']);
    settingModel()->rotateHtmlCacheGeneration();
    @unlink($wpDb);
    unlink($state);
} elseif ($action === 'inspect') {
    // 导入后的表单模板与菜单组（按 WordPress 原 ID 找）
    $mapped = static fn (string $kind, int $wpId): int => (int) (getMeta('wp_import', $wpId, $kind) ?? 0);
    $ids = json_decode((string) ($argv[2] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
    $form = db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'form_templates WHERE id = ?', [$mapped('form', (int) $ids['form_quote'])]) ?: [];
    $menuId = $mapped('nav_menu', (int) $ids['menu_main_tt']);
    $menu = db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'nav_menus WHERE id = ?', [$menuId]) ?: [];
    echo json_encode([
        'form' => ['slug' => $form['slug'] ?? '', 'fields' => $form['fields'] ?? '', 'fields_ja' => $form['fields_ja'] ?? '',
            'fields_de' => (string) (getMeta('form_template_lang', (int) ($form['id'] ?? 0), 'fields_de') ?? ''),
            'success_de' => (string) (getMeta('form_template_lang', (int) ($form['id'] ?? 0), 'success_de') ?? '')],
        'menu' => ['name' => $menu['name'] ?? '', 'items' => json_decode((string) ($menu['items'] ?? '[]'), true),
            'ja' => (int) (getMeta('nav_menu_lang', $menuId, 'ja') ?? 0)],
        // ACF：字段定义（类型 + 配置）与导入后的值
        'acf' => (static function () use ($mapped, $ids): array {
            $product = $mapped('product', (int) $ids['product_drive']);
            $fields = [];
            foreach (['product', 'product_category', 'site'] as $owner) {
                foreach (ExtFields::fields($owner, false) as $f) {
                    $fields[$owner . ':' . $f['field_key']] = ['type' => $f['field_type'], 'config' => $f['config'], 'options' => $f['options']];
                }
            }
            $category = (int) (db()->fetchColumn('SELECT id FROM ' . DB_PREFIX . "product_categories WHERE slug = 'worm-gear-slew-drive-cat'") ?: 0);
            return ['fields' => $fields, 'product' => getAllMeta('product', $product), 'site' => getAllMeta('site', 0),
                'category' => getAllMeta('product_category', $category), 'category_id' => $category,
                'post' => $mapped('post', (int) $ids['post_install'])];
        })(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} else throw new RuntimeException('Invalid action');
