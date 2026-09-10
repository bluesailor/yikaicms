<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-') || !is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) throw new RuntimeException('Disposable site required');
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') throw new RuntimeException('Local SQLite required');
$file = STORAGE_PATH . '/e2e-form-ip-state.json';
$action = $argv[1] ?? '';
if ($action === 'setup') {
    if (is_file($file) || formModerationModel()->isBlocked('127.0.0.1')) throw new RuntimeException('Unclean fixture');
    $ids = [];
    foreach (['127.0.0.1', '127.0.0.1', '192.0.2.20'] as $n => $ip) {
        $ids[] = (int) formModel()->create(['type' => $n === 0 ? 'contact' : 'product-inquiry', 'source' => $n === 0 ? 'contact' : 'product',
            'name' => 'IP moderation fixture', 'phone' => '13800000000', 'content' => 'Disposable IP test ' . $n,
            'status' => $n, 'ip' => $ip, 'created_at' => time()]);
    }
    file_put_contents($file, json_encode($ids, JSON_THROW_ON_ERROR));
    echo json_encode($ids, JSON_THROW_ON_ERROR);
} elseif ($action === 'result') {
    echo json_encode(['blocked' => formModerationModel()->isBlocked('127.0.0.1'),
        'rows' => formModel()->where(['name' => 'IP moderation fixture'])], JSON_THROW_ON_ERROR);
} elseif ($action === 'restore') {
    if (is_file($file)) {
        formModel()->deleteByIds(json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR));
        formModerationModel()->unblock('127.0.0.1');
        unlink($file);
    }
} else throw new RuntimeException('Invalid action');
