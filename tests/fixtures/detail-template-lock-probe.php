<?php
declare(strict_types=1);

// CLI-only probe with its own disposable database; never loads site configuration.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$worker = ($argv[1] ?? '') === 'worker';
$path = $worker ? (string) ($argv[2] ?? '') : tempnam(sys_get_temp_dir(), 'yk-detail-lock-');
if (!$path) {
    throw new RuntimeException('Cannot create probe database');
}
define('ROOT_PATH', dirname(__DIR__, 2));
define('DB_DRIVER', 'sqlite');
define('DB_PATH', $path);
define('DB_PREFIX', 'probe_');
require ROOT_PATH . '/config/database.php';
require ROOT_PATH . '/includes/builder/DetailTemplatePublishGuard.php';

db()->execute('PRAGMA busy_timeout = 3000');
if ($worker) {
    $before = db()->fetchColumn('SELECT draft_data FROM probe_blox_templates WHERE id = ?', [1]);
    echo "ready\n";
    flush();
    if (trim((string) fgets(STDIN)) !== 'lock') {
        exit(2);
    }
    db()->beginTransaction();
    DetailTemplatePublishGuard::lockForPublish('product-detail', 1);
    $after = db()->fetchColumn('SELECT draft_data FROM probe_blox_templates WHERE id = ?', [1]);
    db()->rollback();
    echo json_encode(['before' => $before, 'after_lock' => $after], JSON_THROW_ON_ERROR) . "\n";
    exit;
}

$process = null;
$pipes = [];
try {
    db()->execute('CREATE TABLE probe_blox_templates (id INTEGER PRIMARY KEY, type TEXT, draft_data TEXT, updated_at INTEGER)');
    db()->execute('INSERT INTO probe_blox_templates VALUES (?, ?, ?, ?)', [1, 'product-detail', 'old-draft', 0]);
    db()->beginTransaction();
    DetailTemplatePublishGuard::lockForPublish('product-detail', 1);
    db()->execute('UPDATE probe_blox_templates SET draft_data = ? WHERE id = ?', ['new-draft', 1]);
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $path], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start lock worker');
    }
    if (trim((string) fgets($pipes[1])) !== 'ready') {
        throw new RuntimeException('Worker failed: ' . stream_get_contents($pipes[2]));
    }
    fwrite($pipes[0], "lock\n");
    fflush($pipes[0]);
    usleep(150000);
    db()->commit();
    $result = json_decode((string) stream_get_contents($pipes[1]), true, 512, JSON_THROW_ON_ERROR);
    if ($result !== ['before' => 'old-draft', 'after_lock' => 'new-draft']) {
        throw new RuntimeException('Locked read did not observe the committed draft');
    }
    $error = stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    $pipes = [];
    $exit = proc_close($process);
    $process = null;
    if ($exit !== 0) {
        throw new RuntimeException('Worker failed: ' . $error);
    }

    db()->execute('CREATE TABLE probe_digest (id INTEGER PRIMARY KEY, parent_id INTEGER)');
    db()->beginTransaction();
    for ($id = 1; $id <= 20000; $id++) {
        db()->execute('INSERT INTO probe_digest VALUES (?, ?)', [$id, $id % 100]);
    }
    db()->commit();
    $start = hrtime(true);
    $digest = (new ReflectionMethod(DetailTemplatePublishGuard::class, 'rowDigest'))->invoke(null, 'probe_digest', 'id, parent_id', '1 = 1', []);
    echo json_encode([
        'lock_probe' => $result,
        'digest_rows' => 20000,
        'digest_ms' => round((hrtime(true) - $start) / 1e6, 2),
        'digest_length' => strlen($digest),
    ], JSON_THROW_ON_ERROR) . "\n";
} finally {
    if (db()->getPdo()->inTransaction()) {
        db()->rollback();
    }
    if (is_resource($process)) {
        proc_terminate($process);
    }
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    if (is_resource($process)) {
        proc_close($process);
    }
    // Release the SQLite connection before deleting the file on Windows.
    $instance = new ReflectionProperty(Database::class, 'instance');
    $instance->setValue(null, null);
    unlink($path);
}
