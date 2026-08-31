<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__, 2);
define('ROOT_PATH', $root);
require $root . '/config/config.php';
if (DB_DRIVER !== 'sqlite' || realpath(DB_PATH) !== realpath($root . '/storage/database.sqlite')
    || !str_starts_with(SITE_URL, 'http://127.0.0.1:') || !is_file($root . '/tests/smoke/fixtures.json')) {
    fwrite(STDERR, "Requires disposable smoke installation\n");
    exit(1);
}
require $root . '/includes/models/autoload.php';
$values = json_decode($argv[1] ?? '{}', true, 8, JSON_THROW_ON_ERROR);
$previous = [];
foreach ($values as $key => $value) {
    if (!in_array($key, ['demo_mode', 'demo_owner_token', 'cron_token'], true) || !is_string($value)) exit(1);
    $previous[$key] = (string) settingModel()->get($key, '');
    settingModel()->set($key, $value, 'system');
}
echo json_encode($previous, JSON_THROW_ON_ERROR);
