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
require ROOT_PATH . '/includes/models/autoload.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Local SQLite required');
}
$action = $argv[1] ?? '';
$backup = ROOT_PATH . '/storage/language-boundary-backup.json';
if ($action === 'restore') {
    if (!is_file($backup)) exit;
    $state = json_decode((string) file_get_contents($backup), true, 512, JSON_THROW_ON_ERROR);
    foreach ($state['keys'] as $key) db()->delete('settings', '`key` = ?', [$key]);
    foreach ($state['settings'] as $row) db()->insert('settings', $row);
    foreach ($state['rows'] as $table => $rows) foreach ($rows as $row) {
        db()->update($table, ['status' => $row['status'], 'deleted_at' => $row['deleted_at'],
            'translation_group_id' => $row['translation_group_id']], 'id = ?', [$row['id']]);
    }
    unlink($backup);
    exit;
}
if ($action !== 'prepare' || is_file($backup)) throw new RuntimeException('Invalid action or unrestored fixture');
$mode = $argv[2] ?? 'pretty';
$lang = $argv[3] ?? 'zh-CN';
$theme = $argv[4] ?? 'default';
$availability = $argv[5] ?? 'published';
if (!in_array($mode, ['pretty', 'query'], true) || !in_array($lang, ['zh-CN', 'en', 'ja'], true)
    || !in_array($theme, ['default', 'business', 'minimal'], true)
    || !in_array($availability, ['published', 'missing', 'draft', 'deleted'], true)) throw new RuntimeException('Invalid fixture parameters');
$values = ['url_mode' => $mode, 'site_lang' => $lang, 'current_theme' => $theme,
    'enabled_languages' => $lang === 'zh-CN' ? '["zh-CN","en","ja"]' : json_encode([$lang], JSON_THROW_ON_ERROR),
    'html_cache_enabled' => '0', 'home_layout_active' => '0', 'home_blox_active' => '0',
    'blox_custom_header_enabled' => '0', 'blox_custom_footer_enabled' => '0', 'show_lang_switcher' => '1'];
$keys = array_keys($values);
$state = ['keys' => $keys, 'settings' => db()->fetchAll('SELECT * FROM ' . DB_PREFIX . 'settings WHERE `key` IN ('
    . implode(',', array_fill(0, count($keys), '?')) . ')', $keys), 'rows' => []];
$manifest = [];
foreach (['article' => 'contents', 'product' => 'products'] as $kind => $table) {
    $source = db()->fetchOne('SELECT * FROM ' . DB_PREFIX . $table
        . ' WHERE lang = ? AND status = 1 AND deleted_at IS NULL'
        . ($kind === 'article' ? " AND type = 'article'" : '') . ' ORDER BY id LIMIT 1', ['zh-CN']);
    if (!$source) throw new RuntimeException('Missing source row');
    $group = (int) ($source['translation_group_id'] ?: $source['id']);
    $rows = db()->fetchAll('SELECT * FROM ' . DB_PREFIX . $table . ' WHERE translation_group_id = ? OR id = ?', [$group, $source['id']]);
    $state['rows'][$table] = $rows;
    $byLanguage = [];
    foreach ($rows as $row) $byLanguage[$row['lang']] = $row;
    foreach (['zh-CN', 'en', 'ja'] as $required) if (!isset($byLanguage[$required])) throw new RuntimeException('Missing translation fixture');
    $manifest[$kind] = $byLanguage;
}
file_put_contents($backup, json_encode($state, JSON_THROW_ON_ERROR));
settingModel()->saveBatch($values);
foreach (['article' => 'contents', 'product' => 'products'] as $kind => $table) {
    $target = $manifest[$kind]['en'];
    $change = match ($availability) {
        'missing' => ['translation_group_id' => 0],
        'draft' => ['status' => 0],
        'deleted' => ['deleted_at' => time()],
        default => [],
    };
    if ($change) db()->update($table, $change, 'id = ?', [$target['id']]);
}
echo json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
