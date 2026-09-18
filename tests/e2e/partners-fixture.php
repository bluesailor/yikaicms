<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-')
    || realpath(dirname(ROOT_PATH)) !== realpath(sys_get_temp_dir())
    || !is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) {
    throw new RuntimeException('Disposable runner site required');
}
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/hooks.php';
require ROOT_PATH . '/includes/models/autoload.php';
require ROOT_PATH . '/includes/builder/bootstrap.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Local SQLite only');
}
$keys = ['site_lang', 'enabled_languages', 'home_layout_active', 'home_blox_active',
    'home_blox_data', 'home_blox_published', 'home_blox_history', 'html_cache_enabled'];
$backup = ROOT_PATH . '/storage/partners-backup.json';
$action = $argv[1] ?? '';
if ($action === 'restore') {
    if (!is_file($backup)) exit;
    $before = json_decode((string) file_get_contents($backup), true, 512, JSON_THROW_ON_ERROR);
    foreach ($keys as $key) db()->delete('settings', '`key` = ?', [$key]);
    foreach ($before['settings'] as $row) db()->insert('settings', $row);
    db()->delete('links', 'id > ?', [0]);
    foreach ($before['links'] as $row) db()->insert('links', $row);
    unlink($backup);
    exit;
}
if ($action === 'update' && is_file($backup)) {
    db()->update('links', ['name' => 'Shared updated'], 'name = ?', ['Shared zh-CN']);
    exit;
}
if ($action !== 'seed' || is_file($backup)) throw new RuntimeException('Invalid fixture state');
file_put_contents($backup, json_encode([
    'settings' => db()->fetchAll('SELECT * FROM ' . DB_PREFIX . 'settings WHERE `key` IN (' . implode(',', array_fill(0, count($keys), '?')) . ')', $keys),
    'links' => db()->fetchAll('SELECT * FROM ' . DB_PREFIX . 'links'),
], JSON_THROW_ON_ERROR));
db()->delete('links', 'id > ?', [0]);
foreach (['zh-CN', 'en', 'ja'] as $lang) {
    db()->insert('links', ['name' => 'Shared ' . $lang, 'url' => '/contact.html', 'logo' => '', 'lang' => $lang, 'status' => 1, 'sort_order' => 10]);
}
db()->insert('links', ['name' => 'Disabled partner', 'url' => '/contact.html', 'lang' => 'zh-CN', 'status' => 0]);
settingModel()->saveBatch(['site_lang' => 'zh-CN', 'enabled_languages' => '["zh-CN","en","ja"]',
    'home_layout_active' => '0', 'html_cache_enabled' => '0']);
HomeBloxDocument::saveDraft(json_encode(['schema' => 1, 'sections' => [[
    'id' => 'partners_s', 'type' => 'section', 'settings' => ['padding' => 'none'],
    'columns' => [['id' => 'partners_c', 'span' => 12, 'elements' => [[
        'id' => 'partners_e', 'type' => 'home-block',
        'data' => ['block_type' => 'partners', 'enabled' => true, 'override_title' => 'Partner baseline'],
    ]]]],
]]], JSON_THROW_ON_ERROR));
HomeBloxDocument::publishDraft();
