<?php
/** CLI-only: seed the remote repair log of the disposable e2e site. */
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
    throw new RuntimeException('Refusing to seed a non-local SQLite fixture');
}
$action = $argv[1] ?? '';
if ($action === 'seed') {
    settingModel()->set('remote_repair_log', (string) json_encode([
        ['id' => 'fix-20261001-e2e00002', 'title' => 'E2E 修栏目别名', 'status' => 'failed', 'msg' => "step 1: no such column '…'", 'at' => time(), 'backup' => 'repair_fix-20261001-e2e00002_20261001_100000.sql'],
        ['id' => 'fix-20261001-e2e00001', 'title' => 'E2E 补跑迁移', 'status' => 'ok', 'msg' => '', 'at' => time() - 3600, 'backup' => ''],
    ], JSON_UNESCAPED_UNICODE), 'system');
} elseif ($action === 'cleanup') {
    db()->delete('settings', '`key` = ?', ['remote_repair_log']);
} else {
    throw new RuntimeException('Expected seed or cleanup');
}
settingModel()->clearCache();
