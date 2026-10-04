<?php
declare(strict_types=1);
// 高级字段 e2e：在一次性测试站上直接建字段定义（含专业版类型——授权只拦定义界面，不拦填写），
// inspect 读出各条目保存后的值，restore 删掉定义、配置与值。
if (PHP_SAPI !== 'cli') exit(1);
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-') || !is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) throw new RuntimeException('Disposable site required');
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') throw new RuntimeException('Local SQLite required');

const EF_E2E_KEYS = ['e2e_kind', 'e2e_size', 'e2e_buy', 'e2e_tint', 'e2e_specs', 'e2e_related', 'e2e_sheet', 'e2e_factory', 'e2e_banner', 'e2e_hidden_cat', 'e2e_models'];
$action = $argv[1] ?? '';
$define = static function (string $owner, string $key, string $type, array $config = [], array $extra = []): int {
    $id = (int) db()->insert('extfields', $extra + ['owner_type' => $owner, 'field_key' => $key, 'field_name' => ucfirst(str_replace('e2e_', '', $key)),
        'field_type' => $type, 'options' => '', 'placeholder' => '', 'help_text' => '', 'is_required' => 0, 'sort_order' => 0, 'status' => 1, 'created_at' => time()]);
    ExtFields::saveConfig($id, $type, $config);
    return $id;
};

if ($action === 'setup') {
    $products = db()->fetchAll('SELECT id, title, category_id FROM ' . DB_PREFIX . 'products WHERE deleted_at IS NULL OR deleted_at = 0 ORDER BY id LIMIT 3');
    if (count($products) < 2) throw new RuntimeException('Need two products');
    $define('product', 'e2e_kind', 'select', [], ['options' => "std|Standard\ncustom|Custom", 'sort_order' => 1]);
    $define('product', 'e2e_size', 'text', ['conditions' => [['field' => 'e2e_kind', 'op' => '==', 'value' => 'custom']]], ['is_required' => 1, 'sort_order' => 2]);
    $define('product', 'e2e_buy', 'link', [], ['sort_order' => 3]);
    $define('product', 'e2e_tint', 'color', [], ['sort_order' => 4]);
    $define('product', 'e2e_specs', 'repeater', ['sub_fields' => [['key' => 'model', 'name' => 'Model', 'type' => 'text'], ['key' => 'load', 'name' => 'Load', 'type' => 'number']]], ['sort_order' => 5]);
    $define('product', 'e2e_related', 'relationship', ['target' => 'product'], ['sort_order' => 6]);
    $define('product', 'e2e_sheet', 'file', [], ['sort_order' => 7]);
    // 挂在一个不存在的分类上：任何产品都不显示
    $define('product', 'e2e_hidden_cat', 'text', ['location' => [987654]], ['sort_order' => 8]);
    $define('site', 'e2e_factory', 'text');
    $define('product_category', 'e2e_banner', 'text');
    echo json_encode(['product' => (int) $products[0]['id'], 'related' => (int) $products[1]['id'], 'related_title' => (string) $products[1]['title']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} elseif ($action === 'inspect') {
    $product = (int) ($argv[2] ?? 0);
    $category = (int) (db()->fetchColumn('SELECT id FROM ' . DB_PREFIX . 'product_categories ORDER BY id LIMIT 1') ?: 0);
    echo json_encode(['product' => getAllMeta('product', $product), 'site' => getAllMeta('site', 0), 'category' => getAllMeta('product_category', $category), 'category_id' => $category],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} elseif ($action === 'restore') {
    foreach (db()->fetchAll('SELECT id, owner_type, field_key FROM ' . DB_PREFIX . 'extfields WHERE field_key LIKE ?', ['e2e%']) as $row) {
        if (!in_array($row['field_key'], EF_E2E_KEYS, true)) continue;
        db()->delete('metas', 'owner_type = ? AND meta_key = ?', [$row['owner_type'], $row['field_key']]);
        db()->delete('metas', "owner_type = 'extfield' AND owner_id = ?", [(int) $row['id']]);
        db()->delete('extfields', 'id = ?', [(int) $row['id']]);
    }
    settingModel()->rotateHtmlCacheGeneration();
} else throw new RuntimeException('Invalid action');
