<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
define('ROOT_PATH', dirname(__DIR__, 2));
if (!is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')
    || !str_starts_with(basename(ROOT_PATH), 'yikai-e2e-')) {
    throw new RuntimeException('Disposable site required');
}
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Local SQLite required');
}
echo productModel()->create([
    'title' => 'E2E legacy specs', 'slug' => 'e2e-legacy-specs-' . uniqid(),
    'lang' => siteLang(), 'status' => 0,
    'specs' => json_encode([
        'plain' => '100mm',
        'nested' => ['name' => 'Size', 'value' => '200mm'],
        'options' => ['label' => 'Color', 'options' => ['黑', '白']],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
]);
