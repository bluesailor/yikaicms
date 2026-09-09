<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-')
    || !is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) {
    throw new RuntimeException('Disposable site required');
}
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Local SQLite required');
}
$mode = $argv[1] ?? '';
$lang = $argv[2] ?? 'en';
$backup = ROOT_PATH . '/storage/theme-classic-language.json';
if ($mode === 'restore') {
    if (is_file($backup)) {
        foreach (json_decode((string) file_get_contents($backup), true, 512, JSON_THROW_ON_ERROR) as $key => $row) {
            db()->delete('settings', '"key" = ?', [$key]);
            if ($row !== null) db()->insert('settings', $row);
        }
        unlink($backup);
    }
    exit;
}
if ($mode === 'snapshot') {
    echo json_encode(db()->fetchAll('SELECT "key", value FROM ' . DB_PREFIX . 'settings ORDER BY "key"'), JSON_THROW_ON_ERROR);
    exit;
}
if (!in_array($mode, ['explicit', 'factory', 'custom'], true)
    || !in_array($lang, ['zh-CN', 'en', 'ja'], true)) throw new RuntimeException('Invalid fixture mode');
$keys = [];
for ($i = 1; $i <= 4; $i++) {
    $keys[] = "home_stat_{$i}_text";
    $keys[] = "home_adv_{$i}_title";
    $keys[] = "home_adv_{$i}_desc";
}
$values = ['home_blocks_config' => json_encode([
    ['type' => 'stats', 'enabled' => true], ['type' => 'advantage', 'enabled' => true],
], JSON_THROW_ON_ERROR), 'blox_custom_header_enabled' => '0', 'blox_custom_footer_enabled' => '0'];
$defaults = getDefaults('home');
$pack = require ROOT_PATH . '/lang/' . $lang . '.php';
$expected = [];
foreach ($keys as $index => $key) {
    $base = $mode === 'custom' ? '客户保留文案 ' . $index : $defaults[$key]['value'];
    $values[$key] = $base;
    foreach (['zh-CN', 'en', 'ja'] as $variant) {
        $values[$key . '_' . $variant] = $mode === 'explicit' ? $variant . ' localized copy ' . $index : '';
    }
    $expected[] = $mode === 'explicit' ? $values[$key . '_' . $lang]
        : ($mode === 'custom' || $lang === 'zh-CN' ? $base : $pack[$key]);
}
if (!is_file($backup)) {
    $before = [];
    foreach ($values as $key => $_) $before[$key] = db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'settings WHERE "key" = ?', [$key]);
    file_put_contents($backup, json_encode($before, JSON_THROW_ON_ERROR));
}
settingModel()->saveBatch($values);
echo json_encode($expected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
