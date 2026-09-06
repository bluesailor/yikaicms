<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__, 2));
if (!is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) {
    throw new RuntimeException('An active disposable smoke site is required');
}
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Refusing a non-local fixture');
}

$statePath = ROOT_PATH . '/storage/e2e-dynamic-url.json';
$action = (string) ($argv[1] ?? '');
if ($action === 'cleanup') {
    if (is_file($statePath)) {
        $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
        settingModel()->saveBatch($state);
        unlink($statePath);
    }
    exit(0);
}
if ($action !== 'seed') {
    throw new RuntimeException('Expected seed or cleanup');
}
if (!is_file($statePath)) {
    file_put_contents($statePath, json_encode([
        'url_mode' => config('url_mode', 'pretty'),
        'enabled_languages' => config('enabled_languages', ''),
    ], JSON_THROW_ON_ERROR));
}
settingModel()->saveBatch([
    'url_mode' => 'query',
    'enabled_languages' => '["zh-CN","en","ja"]',
]);
