<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-') || !is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) throw new RuntimeException('Disposable site required');
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') throw new RuntimeException('Local SQLite required');
$file = STORAGE_PATH . '/e2e-content-urls.json';
$action = $argv[1] ?? '';
$m = productRouteModel();
if ($action === 'setup') {
    if (is_file($file)) throw new RuntimeException('Unclean fixture');
    $settings = [];
    foreach (['url_mode', 'html_cache_enabled'] as $key) $settings[$key] = config($key, '');
    settingModel()->saveBatch(['url_mode' => 'pretty', 'html_cache_enabled' => '0']);
    $migration = require ROOT_PATH . '/migrations/20260910_product_custom_urls.php';
    ($migration['php'])();
    $t = DB_PREFIX;
    $article = db()->fetchOne("SELECT id, slug, title, tags FROM {$t}contents WHERE type = 'article' AND status = 1 AND lang = 'zh-CN' ORDER BY id LIMIT 1");
    $news = db()->fetchOne("SELECT id FROM {$t}channels WHERE slug = 'news' AND lang = 'zh-CN'");
    $newsEn = db()->fetchOne("SELECT id, slug FROM {$t}channels WHERE translation_group_id = ? AND lang = 'en'", [(int) $news['id']]);
    $album = (int) albumModel()->create(['name' => 'WP lab album', 'slug' => 'wp-lab', 'lang' => 'zh-CN', 'translation_group_id' => 0,
        'status' => 1, 'description' => 'WP lab description', 'created_at' => time()]);
    if (!$article || !$news || !$newsEn) throw new RuntimeException('Demo article and news channels required');
    db()->execute("UPDATE {$t}contents SET tags = ? WHERE id = ?", ['worm gear, 回转支承', (int) $article['id']]);
    $tag = $m->contentTagId('worm gear', 'zh-CN');
    $m->assign('content', (int) $article['id'], '/slewing-bearing-installation/', 'zh-CN');
    $m->assign('channel', (int) $news['id'], '/category/news/', 'zh-CN');
    $m->assign('channel', (int) $newsEn['id'], '/en/category/news/', 'en');
    $m->assign('album', $album, '/albums/lab-equipment/', 'zh-CN');
    $m->assign('content_tag', $tag, '/tag/worm-gear/', 'zh-CN');
    $state = ['article' => $article, 'news' => (int) $news['id'], 'newsEn' => $newsEn, 'album' => $album, 'tag' => $tag, 'settings' => $settings];
    file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    echo json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} elseif ($action === 'restore' && is_file($file)) {
    $state = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $m->remove('content', (int) $state['article']['id']);
    $m->remove('channel', $state['news']);
    $m->remove('channel', (int) $state['newsEn']['id']);
    $m->remove('album', $state['album']);
    $m->remove('content_tag', $state['tag']);
    db()->delete('albums', 'id = ?', [$state['album']]);
    db()->delete('metas', 'id = ?', [$state['tag']]);
    db()->execute('UPDATE ' . DB_PREFIX . 'contents SET tags = ? WHERE id = ?', [(string) $state['article']['tags'], (int) $state['article']['id']]);
    settingModel()->saveBatch($state['settings']);
    unlink($file);
} else throw new RuntimeException('Invalid action');
