<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-')
    || !is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) {
    throw new RuntimeException('Disposable site required');
}
$action = $argv[1] ?? '';
$language = $argv[2] ?? 'zh-CN';
if (!in_array($language, ['zh-CN', 'en', 'ja'], true)) throw new RuntimeException('Invalid language');
define('SITE_LANG', $language);
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Local SQLite required');
}
$backup = ROOT_PATH . '/storage/theme-site-baseline.json';
if ($action === 'restore') {
    if (is_file($backup)) {
        $before = json_decode((string) file_get_contents($backup), true, 512, JSON_THROW_ON_ERROR);
        settingModel()->saveBatch($before['settings']);
        foreach ($before['rows'] as $row) {
            db()->update($row['table'], ['title' => $row['title'], 'content' => $row['content']], 'id = ?', [$row['id']]);
        }
        unlink($backup);
    }
    exit;
}
if ($action === 'prepare') {
    // Native theme acceptance must not render editor-only pricing/FAQ fixtures.
    $blocks = json_decode(config('home_blocks_config', '[]'), true, 512, JSON_THROW_ON_ERROR);
    $blocks = array_values(array_filter($blocks, static fn(array $block): bool => !str_starts_with($block['type'] ?? '', 'custom:')));
    $values = ['blox_custom_header_enabled' => '0', 'blox_custom_footer_enabled' => '0', 'show_lang_switcher' => '1',
        'home_blocks_config' => json_encode($blocks, JSON_THROW_ON_ERROR), 'header_nav_layout' => 'right'];
    if (!is_file($backup)) {
        $before = ['settings' => [], 'rows' => []];
        foreach ($values as $key => $value) $before['settings'][$key] = config($key, '');
        foreach (['products', 'contents'] as $table) {
            foreach (['zh-CN', 'en', 'ja'] as $lang) {
                $row = $table === 'products'
                    ? db()->fetchOne('SELECT id, title, content, lang FROM ' . DB_PREFIX . 'products WHERE lang = ? AND status = 1 ORDER BY id LIMIT 1', [$lang])
                    : db()->fetchOne('SELECT c.id, c.title, c.content, c.lang FROM ' . DB_PREFIX . 'contents c JOIN '
                        . DB_PREFIX . "channels ch ON ch.id = c.channel_id WHERE c.lang = ? AND ch.lang = ? AND ch.type = 'list'"
                        . " AND c.type = 'article' AND c.status = 1 AND c.deleted_at IS NULL ORDER BY c.id LIMIT 1", [$lang, $lang]);
                if (!$row) throw new RuntimeException('Missing localized corpus row');
                $row['table'] = $table;
                $before['rows'][] = $row;
            }
        }
        file_put_contents($backup, json_encode($before, JSON_THROW_ON_ERROR));
    }
    $titles = [
        'zh-CN' => '面向跨区域制造企业的智能设备连接、生产协同与全生命周期数字化服务解决方案',
        'en' => 'Connected industrial equipment and collaborative manufacturing services for international enterprise operations',
        'ja' => '複数拠点の製造企業に向けたスマート設備の接続と生産連携およびライフサイクル全体のデジタル化サービス',
    ];
    $before = json_decode((string) file_get_contents($backup), true, 512, JSON_THROW_ON_ERROR);
    foreach ($before['rows'] as $row) {
        $title = $titles[$row['lang']];
        db()->update($row['table'], ['title' => $title, 'content' => '<p>' . e($title) . '</p>' . $row['content']], 'id = ?', [$row['id']]);
    }
    settingModel()->saveBatch($values);
    exit;
}
if (in_array($action, ['nav-right', 'nav-below'], true)) {
    if (!is_file($backup)) throw new RuntimeException('Prepare fixture first');
    settingModel()->saveBatch(['header_nav_layout' => $action === 'nav-below' ? 'below' : 'right']);
    exit;
}
if ($action !== 'manifest') throw new RuntimeException('Invalid action');

// Resolve real, localized demo rows; no magic record IDs or synthetic page endpoint.
$routes = [['kind' => 'home', 'url' => rtrim(langUrl('/', $language), '/') . '/', 'title' => '']];
foreach (['product', 'list', 'case', 'download', 'job'] as $type) {
    $channel = channelModel()->findWhere(['type' => $type, 'lang' => $language, 'parent_id' => 0, 'status' => 1]);
    if (!$channel) throw new RuntimeException('Missing demo channel: ' . $type . '/' . $language);
    $routes[] = ['kind' => $type, 'url' => channelUrl($channel), 'title' => $channel['name']];
}
$contact = db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'channels WHERE lang = ? AND status = 1 AND slug IN (?, ?) ORDER BY id LIMIT 1',
    [$language, 'contact', 'contact-' . $language]);
if (!$contact) throw new RuntimeException('Missing contact channel');
$routes[] = ['kind' => 'contact', 'url' => channelUrl($contact), 'title' => $contact['name']];
$product = db()->fetchOne('SELECT p.*, c.slug AS category_slug FROM ' . DB_PREFIX . 'products p LEFT JOIN '
    . DB_PREFIX . 'product_categories c ON c.id = p.category_id WHERE p.lang = ? AND p.status = 1 ORDER BY p.id LIMIT 1', [$language]);
$article = db()->fetchOne('SELECT c.*, ch.slug AS channel_slug, ch.type AS channel_type FROM ' . DB_PREFIX . 'contents c JOIN '
    . DB_PREFIX . 'channels ch ON ch.id = c.channel_id WHERE c.lang = ? AND ch.lang = ? AND ch.type = ? AND c.type = ? AND c.status = 1 AND c.deleted_at IS NULL ORDER BY c.id LIMIT 1', [$language, $language, 'list', 'article']);
if (!$product || !$article) throw new RuntimeException('Missing localized demo detail');
$routes[] = ['kind' => 'product-detail', 'url' => productUrl($product), 'title' => $product['title']];
$routes[] = ['kind' => 'article-detail', 'url' => contentUrl($article), 'title' => $article['title']];
echo json_encode($routes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
