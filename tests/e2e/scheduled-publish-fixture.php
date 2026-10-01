<?php
/** CLI-only helpers for the disposable e2e site: read status, make a schedule due, clean up. */
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
    throw new RuntimeException('Local SQLite required');
}
[$action, $table, $id] = [$argv[1] ?? '', $argv[2] ?? '', (int) ($argv[3] ?? 0)];
if (!in_array($table, ['contents', 'products'], true)) {
    throw new RuntimeException('Expected contents or products');
}
$t = DB_PREFIX . $table;
if ($action === 'status') {
    echo json_encode(db()->fetchOne("SELECT status FROM {$t} WHERE id = ?", [$id]));
} elseif ($action === 'due') {
    // 把定时时间拨到过去，并清掉 60 秒限流，下一次前台访问就会扫描上线（产品的时间在 metas）
    if ($table === 'contents') {
        db()->execute("UPDATE {$t} SET publish_time = ? WHERE id = ?", [time() - 60, $id]);
    } else {
        db()->execute('UPDATE ' . DB_PREFIX . "metas SET meta_value = ? WHERE owner_type = 'product' AND owner_id = ? AND meta_key = 'publish_time'", [(string) (time() - 60), $id]);
    }
    settingModel()->set('sched_sweep_at', '0', 'system');
} elseif ($action === 'cleanup') {
    db()->execute("DELETE FROM {$t} WHERE title LIKE 'E2E 定时%'");
} else {
    throw new RuntimeException('Expected status, due or cleanup');
}
