<?php
/**
 * 多语言网址回归夹具（2.0.5 LocalizedUrl）：造一篇只有中文的文章，切换伪静态 / 动态网址，打开语言切换器。
 *   php localized-urls-fixture.php setup | mode pretty|query | restore
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-') || !is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) throw new RuntimeException('Disposable site required');
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') throw new RuntimeException('Local SQLite required');
$file = STORAGE_PATH . '/e2e-localized-urls.json';
$action = $argv[1] ?? '';
$t = DB_PREFIX;
if ($action === 'setup') {
    if (is_file($file)) throw new RuntimeException('Unclean fixture');
    $settings = [];
    foreach (['url_mode', 'html_cache_enabled', 'show_lang_switcher'] as $key) $settings[$key] = config($key, '');
    settingModel()->saveBatch(['url_mode' => 'pretty', 'html_cache_enabled' => '0', 'show_lang_switcher' => '1']);
    // 有中英日三个版本的文章
    $translated = db()->fetchOne("SELECT c.id, c.slug, c.translation_group_id FROM {$t}contents c WHERE c.type = 'article' AND c.lang = 'zh-CN' AND c.status = 1
        AND (SELECT COUNT(*) FROM {$t}contents x WHERE x.translation_group_id = c.translation_group_id AND x.lang IN ('en', 'ja') AND x.status = 1) = 2 ORDER BY c.id LIMIT 1");
    if (!$translated) throw new RuntimeException('Demo article with en/ja translations required');
    $ja = db()->fetchOne("SELECT slug FROM {$t}contents WHERE translation_group_id = ? AND lang = 'ja'", [(int) $translated['translation_group_id']]);
    $en = db()->fetchOne("SELECT slug FROM {$t}contents WHERE translation_group_id = ? AND lang = 'en'", [(int) $translated['translation_group_id']]);
    // 只有中文的文章：复制一篇，自成翻译组
    $row = db()->fetchOne("SELECT * FROM {$t}contents WHERE id = ?", [(int) $translated['id']]);
    unset($row['id']);
    $row = array_merge($row, ['slug' => 'only-chinese-e2e', 'title' => '只有中文的新闻 E2E', 'translation_group_id' => 0, 'views' => 0]);
    $only = (int) db()->insert('contents', $row);
    db()->update('contents', ['translation_group_id' => $only], 'id = ?', [$only]);
    // 有英文版、没有子栏目的单页栏目（父单页会 302 到第一个子栏目）；两边网址用改动前就有的 channelPrettyUrl 算
    $leaf = db()->fetchOne("SELECT p.* FROM {$t}channels p WHERE p.type = 'page' AND p.lang = 'zh-CN' AND p.status = 1
        AND NOT EXISTS (SELECT 1 FROM {$t}channels k WHERE k.parent_id = p.id AND k.status = 1)
        AND EXISTS (SELECT 1 FROM {$t}channels e WHERE e.translation_group_id = p.translation_group_id AND e.lang = 'en' AND e.status = 1 AND e.id <> p.id)
        AND p.translation_group_id > 0 ORDER BY p.id LIMIT 1");
    if (!$leaf) throw new RuntimeException('Demo page channel with an English version required');
    $leafEn = db()->fetchOne("SELECT * FROM {$t}channels WHERE translation_group_id = ? AND lang = 'en' AND status = 1 AND id <> ?", [(int) $leaf['translation_group_id'], (int) $leaf['id']]);
    // 只有中文的单页栏目：复制上面的单页，自成翻译组
    $channel = $leaf;
    unset($channel['id']);
    $onlyChannel = (int) db()->insert('channels', array_merge($channel, ['slug' => 'only-chinese-channel-e2e', 'name' => '只有中文的单页 E2E', 'translation_group_id' => 0, 'parent_id' => 0, 'is_nav' => 0]));
    db()->update('channels', ['translation_group_id' => $onlyChannel], 'id = ?', [$onlyChannel]);
    $state = ['settings' => $settings, 'only' => $only, 'translated' => $translated['slug'], 'en' => (string) $en['slug'], 'ja' => (string) $ja['slug'],
        'onlyChannel' => $onlyChannel, 'pageZh' => channelPrettyUrl($leaf), 'pageEn' => channelPrettyUrl($leafEn)];
    settingModel()->rotateHtmlCacheGeneration();
    file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    echo json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
} elseif ($action === 'mode' && in_array($argv[2] ?? '', ['pretty', 'query'], true) && is_file($file)) {
    settingModel()->saveBatch(['url_mode' => $argv[2]]);
    settingModel()->rotateHtmlCacheGeneration();
} elseif ($action === 'restore' && is_file($file)) {
    $state = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    db()->delete('contents', 'id = ?', [(int) $state['only']]);
    db()->delete('channels', 'id = ?', [(int) $state['onlyChannel']]);
    settingModel()->saveBatch($state['settings']);
    settingModel()->rotateHtmlCacheGeneration();
    unlink($file);
} else throw new RuntimeException('Invalid action');
