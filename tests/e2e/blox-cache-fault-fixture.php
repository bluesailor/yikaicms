<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-')
    || !is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) {
    throw new RuntimeException('Disposable smoke site required');
}
require ROOT_PATH . '/config/config.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Local SQLite required');
}
$dir = ROOT_PATH . '/storage/cache/html';
$held = ROOT_PATH . '/storage/cache/html-fixture-held';
$parent = realpath(dirname($dir));
if ($parent !== realpath(ROOT_PATH . '/storage/cache')) throw new RuntimeException('Unexpected cache directory');
$action = $argv[1] ?? '';
if ($action === 'reset') {
    require ROOT_PATH . '/includes/functions.php';
    require ROOT_PATH . '/includes/models/autoload.php';
    require ROOT_PATH . '/includes/HtmlCache.php';
    HtmlCache::invalidate();
} elseif ($action === 'empty') {
    $files = glob($dir . '/*.html') ?: [];
    if (count($files) !== 1) throw new RuntimeException('Exactly one cached response required');
    if (file_put_contents($files[0], '') === false) throw new RuntimeException('Cannot seed empty cache');
} elseif ($action === 'block-writes') {
    if (file_exists($held) || !is_dir($dir) || !rename($dir, $held)) throw new RuntimeException('Cannot isolate cache');
    if (file_put_contents($dir, 'Cache write failure fixture') === false) throw new RuntimeException('Cannot block cache directory');
} elseif ($action === 'restore') {
    if (is_dir($held)) {
        if (is_file($dir)) unlink($dir);
        if (!rename($held, $dir)) throw new RuntimeException('Cannot restore cache');
    }
} else {
    throw new RuntimeException('Invalid fixture action');
}
echo 'ok';
