<?php
/**
 * S2 限流计数的多进程并发验证：多个独立进程同时对同一个（全新）桶计数，检查不丢计数、放行数精确等于限额；
 * 以及多个进程同时为同一账号签发令牌，有效令牌数不超过每人上限。
 * 表结构取自 20261031_ai_access_tokens 迁移。连接方式与 S1-03 相同（YK_S103_* 环境变量，库/文件名受 s103 前缀约束）。
 *
 *   YK_S103_DRIVER=sqlite YK_S103_SQLITE_PATH=/tmp/s103-throttle.sqlite php tests/ai-access/throttle-run.php
 *   YK_S103_DRIVER=mysql YK_S103_MYSQL_NAME=s103_txn_test … php tests/ai-access/throttle-run.php
 *
 * 退出码：0 = 通过；1 = 失败；2 = 环境错误。
 */

declare(strict_types=1);

require __DIR__ . '/proposal-fixture.php';
require_once dirname(__DIR__, 2) . '/includes/ai-access/AiAccessThrottle.php';
require_once dirname(__DIR__, 2) . '/includes/ai-access/AiAccessTokenStore.php';

if (($argv[1] ?? '') === '--issuer') {
    // 子进程：等起跑信号，为同一账号连续签发，输出成功与被拒次数。
    [, , $count, $go] = $argv;
    $db = s103Boot();
    if ($db->isSqlite()) $db->getPdo()->setAttribute(PDO::ATTR_TIMEOUT, 30);
    $store = new AiAccessTokenStore($db);
    while (!is_file($go)) usleep(2000);
    $issued = 0;
    $refused = 0;
    $errors = 0;
    for ($i = 0; $i < (int) $count; $i++) {
        try {
            $store->issue(7, 'race ' . getmypid() . ' ' . $i, ['site_info'], 30, 1800000000);
            $issued++;
        } catch (RuntimeException $error) {
            $error->getMessage() === 'ai_token_limit_reached' ? $refused++ : $errors++;
        } catch (Throwable) {
            $errors++;
        }
    }
    echo json_encode(['issued' => $issued, 'refused' => $refused, 'errors' => $errors]);
    exit(0);
}

if (($argv[1] ?? '') === '--worker') {
    // 子进程：等起跑信号，然后连续计数，输出放行次数。
    [, , $bucket, $count, $limit, $go, $now] = $argv;
    $db = s103Boot();
    if ($db->isSqlite()) $db->getPdo()->setAttribute(PDO::ATTR_TIMEOUT, 30);
    $throttle = new AiAccessThrottle($db);
    while (!is_file($go)) usleep(2000);
    $allowed = 0;
    $errors = 0;
    for ($i = 0; $i < (int) $count; $i++) {
        try {
            $allowed += $throttle->hit($bucket, (int) $limit, (int) $now) ? 1 : 0;
        } catch (RuntimeException) {
            $errors++;
        }
    }
    echo json_encode(['allowed' => $allowed, 'errors' => $errors]);
    exit(0);
}

try {
    $db = s103Boot();
    require_once ROOT_PATH . '/includes/Migrator.php';
    $migration = require ROOT_PATH . '/migrations/20261031_ai_access_tokens.php';
    foreach (['api_rate_limits', 'api_tokens', 'users'] as $table) $db->execute('DROP TABLE IF EXISTS ' . DB_PREFIX . $table);
    foreach ($migration['sqls'] as $sql) {
        if (!str_contains($sql, 'api_rate_limits') && !str_contains($sql, 'api_tokens')) continue;
        $db->execute($db->isSqlite() ? (string) _sqlToSqlite($sql) : $sql);
    }
    foreach (s103CoreTableSql($db->isSqlite(), 'users') as $sql) $db->execute($sql);
    $db->execute('INSERT INTO ' . DB_PREFIX . "users (id, username, password, role_id, status, created_at, updated_at) VALUES (7, 'race', '', 1, 1, 0, 0)");
} catch (Throwable $error) {
    fwrite(STDERR, 'environment error: ' . $error->getMessage() . "\n");
    exit(2);
}

$results = [];
$tmp = sys_get_temp_dir() . '/s104-throttle-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);
$now = 1800000000;
foreach ([['fresh bucket, 6 workers × 20 hits, limit 60', 6, 20, 60], ['fresh bucket, 8 workers × 15 hits, limit 100 (under limit)', 8, 15, 100]] as $round => [$label, $workers, $count, $limit]) {
    $bucket = 'token:' . (900 + $round) . ':read';
    $go = $tmp . '/go-' . $round;
    $procs = [];
    for ($w = 0; $w < $workers; $w++) {
        $procs[] = proc_open([PHP_BINARY, __FILE__, '--worker', $bucket, (string) $count, (string) $limit, $go, (string) $now],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT_PATH, getenv());
        $procPipes[$w] = $pipes;
    }
    usleep(500000);
    touch($go);
    $allowed = 0;
    $errors = 0;
    foreach ($procs as $w => $proc) {
        $out = json_decode((string) stream_get_contents($procPipes[$w][1]), true);
        $err = (string) stream_get_contents($procPipes[$w][2]);
        fclose($procPipes[$w][1]);
        fclose($procPipes[$w][2]);
        proc_close($proc);
        if (!is_array($out)) {
            fwrite(STDERR, "worker output invalid: {$err}\n");
            $errors++;
            continue;
        }
        $allowed += (int) $out['allowed'];
        $errors += (int) $out['errors'];
    }
    $total = $workers * $count;
    $hits = (int) $db->fetchColumn('SELECT hits FROM ' . DB_PREFIX . 'api_rate_limits WHERE bucket = ? AND window_start = ?', [$bucket, $now - $now % 60]);
    $rows = (int) $db->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'api_rate_limits WHERE bucket = ?', [$bucket]);
    $results[] = [$errors === 0, "{$label}: no storage errors (got {$errors})"];
    $results[] = [$hits === $total && $rows === 1, "{$label}: counter = {$total} in one row (got {$hits} in {$rows})"];
    $results[] = [$allowed === min($limit, $total), "{$label}: allowed = " . min($limit, $total) . " (got {$allowed})"];
}
// 签发名额：6 个进程各签 4 枚（共 24 次）抢同一账号的 10 个名额。
$go = $tmp . '/go-issue';
$procs = [];
$procPipes = [];
for ($w = 0; $w < 6; $w++) {
    $procs[] = proc_open([PHP_BINARY, __FILE__, '--issuer', '4', $go], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT_PATH, getenv());
    $procPipes[$w] = $pipes;
}
usleep(500000);
touch($go);
$issued = 0;
$refused = 0;
$errors = 0;
foreach ($procs as $w => $proc) {
    $out = json_decode((string) stream_get_contents($procPipes[$w][1]), true);
    $err = (string) stream_get_contents($procPipes[$w][2]);
    fclose($procPipes[$w][1]);
    fclose($procPipes[$w][2]);
    proc_close($proc);
    if (!is_array($out)) {
        fwrite(STDERR, "issuer output invalid: {$err}
");
        $errors++;
        continue;
    }
    $issued += (int) $out['issued'];
    $refused += (int) $out['refused'];
    $errors += (int) $out['errors'];
}
$active = (int) $db->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'api_tokens WHERE admin_id = 7 AND revoked_at = 0');
$max = AiAccessTokenStore::MAX_ACTIVE_PER_USER;
$results[] = [$active === $max && $issued === $max, "concurrent issue, 6 workers × 4 for one account: exactly {$max} active tokens (got {$active}, issued {$issued})"];
$results[] = [$refused + $errors === 24 - $max && $errors === 0, "concurrent issue: the other " . (24 - $max) . " refused as limit_reached, no storage errors (refused {$refused}, errors {$errors})"];

foreach (glob($tmp . '/*') ?: [] as $file) @unlink($file);
@rmdir($tmp);
foreach (['api_rate_limits', 'api_tokens', 'users'] as $table) $db->execute('DROP TABLE IF EXISTS ' . DB_PREFIX . $table);

$failed = 0;
foreach ($results as [$ok, $label]) {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    $failed += $ok ? 0 : 1;
}
$server = $db->isSqlite() ? 'sqlite ' . $db->fetchColumn('SELECT sqlite_version()') : 'mysql ' . $db->fetchColumn('SELECT VERSION()');
printf("php=%s %s assertions=%d failed=%d\n", PHP_VERSION, $server, count($results), $failed);
exit($failed === 0 ? 0 : 1);
