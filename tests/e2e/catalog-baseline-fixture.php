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
$action = $argv[1] ?? '';
$file = ROOT_PATH . '/storage/catalog-baseline-settings.json';
if ($action === 'restore') {
    if (is_file($file)) {
        settingModel()->saveBatch(json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR));
        unlink($file);
    }
    exit;
}
if (!in_array($action, ['pretty', 'query', 'business', 'minimal'], true)) {
    throw new RuntimeException('Invalid fixture mode');
}
$settings = ['url_mode', 'current_theme', 'home_layout_active', 'enabled_languages', 'html_cache_enabled'];
$settings = array_merge($settings, array_keys(getDefaults('pagination')));
if (!is_file($file)) {
    $before = [];
    foreach ($settings as $key) $before[$key] = config($key, '');
    file_put_contents($file, json_encode($before, JSON_THROW_ON_ERROR));
}
settingModel()->saveBatch([
    'url_mode' => in_array($action, ['query', 'pretty'], true) ? $action : 'pretty',
    'current_theme' => in_array($action, ['business', 'minimal'], true) ? $action : 'default',
    'home_layout_active' => '0', 'enabled_languages' => '["zh-CN","en","ja"]',
    'html_cache_enabled' => '0',
]);
