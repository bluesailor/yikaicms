<?php
/**
 * 在线升级「事务化回滚」端到端冒烟（v1.18.6 收官项）：
 *   构造一个只碰沙箱目录的增量包 → 走真实 HTTP 的 apply_prepare / apply_batch /
 *   apply_finalize / apply_rollback 四步，断言覆盖前快照、rollback.json 清单、
 *   删除文件也进快照、回滚后三类文件（被覆盖/新建/被删）逐一还原。
 *
 * 单测只锁了状态机（UpgradeApplyState*Test）和健康检查（UpgradeHealthTest）；
 * 快照→清单→恢复这条主链路跨四个 AJAX 动作 + 真实文件系统，此前零覆盖——
 * 回滚是「升级打挂站点」时的最后保障，坏了只会在客户事故现场被发现。
 *
 * 安全边界：包内 payload 与删除清单只含 e2e-rb-sandbox/ 下的文件；回滚的
 * uo_copy_tree 恢复源是本次升级自己的快照目录，故整个流程对工作树的写入
 * 严格限定在沙箱 + storage/{upgrade,backups}，结束后全部清理。
 *
 * 用法：先 tests/smoke/setup.php 装机 + php -S（与 admin_pages.php 同一套），再跑本脚本。
 */
declare(strict_types=1);

$BASE = getenv('SMOKE_BASE') ?: 'http://127.0.0.1:8080';
$JAR  = sys_get_temp_dir() . '/smoke_rollback_cookies_' . getmypid() . '.txt';
$ROOT = dirname(__DIR__, 2);
define('ROOT_PATH', $ROOT);
require $ROOT . '/config/config.php';
if (DB_DRIVER !== 'sqlite' || realpath(DB_PATH) !== realpath($ROOT . '/storage/database.sqlite')
    || SITE_URL !== $BASE || !str_starts_with($BASE, 'http://127.0.0.1:')
    || !is_file($ROOT . '/tests/smoke/fixtures.json')) {
    fwrite(STDERR, "Requires disposable smoke installation\n");
    exit(2);
}
$SANDBOX = $ROOT . '/e2e-rb-sandbox';
@unlink($JAR);

function rbReq(string $url): array
{
    global $JAR, $BASE;
    $ch = curl_init($BASE . $url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $JAR, CURLOPT_COOKIEFILE => $JAR,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

function rbPost(string $url, array $post): array
{
    global $JAR, $BASE;
    $ch = curl_init($BASE . $url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $JAR, CURLOPT_COOKIEFILE => $JAR,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post),
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

/** 升级 AJAX 动作：POST + CSRF，返回解码后的 JSON（解不动即失败退出）。 */
function rbAction(string $action, array $extra = []): array
{
    global $CSRF;
    [$code, $body] = rbPost('/admin/upgrade_online.php', ['action' => $action, '_token' => $CSRF] + $extra);
    $data = json_decode($body, true);
    if ($code !== 200 || !is_array($data)) {
        rbFail("动作 {$action} 响应异常（HTTP {$code}）：" . mb_substr($body, 0, 300));
    }
    return $data;
}

$CLEANUP = [];
function rbCleanup(): void
{
    global $CLEANUP, $SANDBOX, $ROOT;
    db()->execute('DROP TABLE IF EXISTS ' . DB_PREFIX . 'smoke_rollback_probe');
    $rm = function (string $d) use (&$rm): void {
        if (!is_dir($d)) { @unlink($d); return; }
        foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $it) {
            $p = $d . '/' . $it;
            is_dir($p) ? $rm($p) : @unlink($p);
        }
        @rmdir($d);
    };
    $rm($SANDBOX);
    foreach ($CLEANUP as $path) $rm($path);
    @unlink($ROOT . '/storage/upgrade/package.zip');
    @unlink($ROOT . '/storage/upgrade/apply_state.json');
}

function rbFail(string $msg): never
{
    global $JAR;
    rbCleanup();
    @unlink($JAR);
    fwrite(STDERR, "❌ 回滚 e2e：{$msg}\n");
    exit(1);
}

function rbAssert(bool $ok, string $what): void
{
    if (!$ok) rbFail("断言失败：{$what}");
    echo "  ✓ {$what}\n";
}

// ---- 登录 + 拿会话 CSRF ----
[$c, $loginPage] = rbReq('/admin/login.php');
if (!preg_match('/name="csrf-token"\s+content="([a-f0-9]+)"/', $loginPage, $m)
    && !preg_match('/name="_token"[^>]*value="([a-f0-9]+)"/', $loginPage, $m)) {
    fwrite(STDERR, "❌ 拿不到登录 CSRF token（HTTP {$c}）\n");
    exit(2);
}
[$c] = rbPost('/admin/login.php', ['username' => 'admin', 'password' => 'smoke@Test123', '_token' => $m[1]]);
if ($c !== 302) { fwrite(STDERR, "❌ 登录失败（HTTP {$c}）\n"); exit(2); }
[, $indexPage] = rbReq('/admin/index.php');
if (!preg_match('/name="csrf-token"\s+content="([a-f0-9]+)"/', $indexPage, $m)) {
    fwrite(STDERR, "❌ 拿不到会话 CSRF token\n");
    exit(2);
}
$CSRF = $m[1];
echo "✓ 登录成功\n";
db()->execute('CREATE TABLE ' . DB_PREFIX . 'smoke_rollback_probe (id INTEGER PRIMARY KEY, value VARCHAR(100))');
db()->execute('INSERT INTO ' . DB_PREFIX . 'smoke_rollback_probe (id, value) VALUES (?, ?)', [1, 'BEFORE']);

// ---- 0) 布景：沙箱三类文件 + 手工构造增量包 ----
@mkdir($SANDBOX, 0755, true);
file_put_contents($SANDBOX . '/overwrite.txt', 'OLD-CONTENT');
file_put_contents($SANDBOX . '/doomed.txt', 'DOOMED-CONTENT');

@mkdir($ROOT . '/storage/upgrade', 0755, true);
$pkg = $ROOT . '/storage/upgrade/package.zip';
@unlink($pkg);
$zip = new ZipArchive();
if ($zip->open($pkg, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) rbFail('无法创建测试包');
// manifest 的 from 必须等于本站当前版本，否则 prepare 会以「基线不匹配」拒绝
// —— 那正是 v1.18.6 新增的护栏（codex 审计 P2-2）。这里如实填当前版本。
$currentVersion = '';
$vf = @file_get_contents($ROOT . '/config/version.php');
if ($vf && preg_match("/CMS_VERSION'\s*,\s*'([^']+)'/", $vf, $vm)) {
    $currentVersion = $vm[1];
}
if ($currentVersion === '') {
    rbFail('读不到 config/version.php 的版本号，无法构造增量包 manifest');
}
$zip->addFromString('.delta-manifest.json', json_encode([
    'from' => $currentVersion, 'to' => $currentVersion,
    'deleted' => ['e2e-rb-sandbox/doomed.txt'],
]));
$zip->addFromString('payload/e2e-rb-sandbox/overwrite.txt', 'NEW-CONTENT');
$zip->addFromString('payload/e2e-rb-sandbox/created.txt', 'CREATED-CONTENT');
$zip->close();
file_put_contents($ROOT . '/storage/upgrade/package-meta.json', json_encode([
    'version' => $currentVersion,
    'hash' => 'test-only',
    'verified_at' => time(),
]));
echo "✓ 布景就绪（沙箱 + 增量包：覆盖1 新建1 删除1）\n";

// ---- 1) apply_prepare：建状态 + 备份目录 ----
echo "— apply_prepare\n";
$prep = rbAction('apply_prepare');
rbAssert(($prep['code'] ?? 1) === 0, 'prepare 成功');
rbAssert(($prep['mode'] ?? '') === 'delta', '识别为增量包');
rbAssert(($prep['total'] ?? 0) === 2, '条目清单 = 2 个文件');
$backup = (string) ($prep['backup'] ?? '');
rbAssert($backup !== '' && str_starts_with($backup, 'pre-upgrade-'), "备份目录已建（{$backup}）");
$bakDir = $ROOT . '/storage/backups/' . $backup;
$CLEANUP[] = $bakDir;
rbAssert(is_file($bakDir . '/config.php'), 'config.php 已在改动文件前完成备份');
rbAssert(is_file($bakDir . '/database.sql'), '数据库快照已在升级前完成');

// ---- 2) apply_batch：覆盖 + 覆盖前快照 ----
echo "— apply_batch\n";
$batch = rbAction('apply_batch');
rbAssert(($batch['code'] ?? 1) === 0 && ($batch['copied'] ?? 0) === 2, '批次覆盖 2 个文件');
rbAssert(file_get_contents($SANDBOX . '/overwrite.txt') === 'NEW-CONTENT', '既有文件已被新版覆盖');
rbAssert(file_get_contents($SANDBOX . '/created.txt') === 'CREATED-CONTENT', '新文件已写入');
rbAssert(
    @file_get_contents($bakDir . '/files/e2e-rb-sandbox/overwrite.txt') === 'OLD-CONTENT',
    '覆盖前旧版已快照到 backups/<backup>/files/'
);

// ---- 3) apply_finalize：删除清单 + rollback.json + 健康自检 ----
echo "— apply_finalize\n";
$fin = rbAction('apply_finalize');
rbAssert(in_array($fin['code'] ?? 1, [0], true), 'finalize 成功且零失败');
rbAssert(($fin['deleted'] ?? 0) === 1 && !is_file($SANDBOX . '/doomed.txt'), '删除清单已执行');
rbAssert(
    @file_get_contents($bakDir . '/files/e2e-rb-sandbox/doomed.txt') === 'DOOMED-CONTENT',
    '被删文件删除前也进了快照'
);
rbAssert(($fin['health']['ok'] ?? false) === true, '升级后健康自检通过');
$rb = json_decode((string) @file_get_contents($bakDir . '/rollback.json'), true);
rbAssert(is_array($rb), 'rollback.json 已落盘');
rbAssert(($rb['created'] ?? []) === ['e2e-rb-sandbox/created.txt'], '回滚清单记录了新建文件');
rbAssert(($rb['deleted'] ?? []) === ['e2e-rb-sandbox/doomed.txt'], '回滚清单记录了被删文件');

// ---- 4) apply_rollback：三类文件全部还原 ----
db()->execute('UPDATE ' . DB_PREFIX . 'smoke_rollback_probe SET value = ? WHERE id = ?', ['AFTER', 1]);
db()->execute('ALTER TABLE ' . DB_PREFIX . 'smoke_rollback_probe ADD COLUMN migration_marker INTEGER');
rename($bakDir . '/database.sql', $bakDir . '/database.sql.held');
try {
    $missing = rbAction('apply_rollback', ['backup' => $backup]);
    rbAssert(($missing['code'] ?? 0) === 2, '数据库快照缺失时拒绝回滚');
    rbAssert(file_get_contents($SANDBOX . '/overwrite.txt') === 'NEW-CONTENT', '数据库恢复失败不回滚文件');
    rbAssert(is_file($bakDir . '/rollback.json'), '失败后保留恢复清单');
} finally {
    rename($bakDir . '/database.sql.held', $bakDir . '/database.sql');
}
echo "— apply_rollback\n";
$roll = rbAction('apply_rollback', ['backup' => $backup]);
rbAssert(($roll['code'] ?? 1) === 0, '回滚成功且零失败');
rbAssert(($roll['database']['statements'] ?? 0) > 0 && ($roll['database']['errors'] ?? null) === [], '数据库 SQL 恢复成功');
rbAssert(db()->fetchColumn('SELECT value FROM ' . DB_PREFIX . 'smoke_rollback_probe WHERE id = ?', [1]) === 'BEFORE', '数据库数据恢复到升级前');
$columns = array_column(db()->fetchAll('PRAGMA table_info(' . DB_PREFIX . 'smoke_rollback_probe)'), 'name');
rbAssert(!in_array('migration_marker', $columns, true), '数据库结构恢复到升级前');
rbAssert(file_get_contents($SANDBOX . '/overwrite.txt') === 'OLD-CONTENT', '被覆盖文件已还原为旧版');
rbAssert(!is_file($SANDBOX . '/created.txt'), '升级新建的文件已移除');
rbAssert(file_get_contents($SANDBOX . '/doomed.txt') === 'DOOMED-CONTENT', '被删除的文件已恢复');
rbAssert(($roll['health']['ok'] ?? false) === true, '回滚后健康自检通过');
rbAssert(!is_file($ROOT . '/storage/upgrade/apply_state.json'), '升级中间态已清理');

// ---- 5) 防呆：增量包基线不匹配被拒（v1.18.6 护栏）----
echo "— 基线校验\n";
$badZip = $ROOT . '/storage/upgrade/package.zip';
@unlink($badZip);
$z2 = new ZipArchive();
if ($z2->open($badZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) rbFail('无法创建基线不符的测试包');
$z2->addFromString('.delta-manifest.json', json_encode(['from' => '0.0.0', 'to' => '9.9.9', 'deleted' => []]));
$z2->addFromString('payload/e2e-rb-sandbox/x.txt', 'X');
$z2->close();
file_put_contents($ROOT . '/storage/upgrade/package-meta.json', json_encode([
    'version' => '9.9.9', 'hash' => 'test-only', 'verified_at' => time(),
]));
$bad2 = rbAction('apply_prepare');
rbAssert(($bad2['code'] ?? 0) === 1, '基线不匹配的增量包被拒绝');
rbAssert(str_contains((string) ($bad2['msg'] ?? ''), '基线不匹配'), '拒绝原因明确指出基线不匹配');
@unlink($badZip);

// ---- 5b) 防呆：目标版本必须等于下载阶段已验签 version ----
$z3 = new ZipArchive();
if ($z3->open($badZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) rbFail('无法创建目标不符的测试包');
$z3->addFromString('.delta-manifest.json', json_encode([
    'from' => $currentVersion, 'to' => '9.9.9-wrong-target', 'deleted' => [],
]));
$z3->addFromString('payload/e2e-rb-sandbox/x.txt', 'X');
$z3->close();
file_put_contents($ROOT . '/storage/upgrade/package-meta.json', json_encode([
    'version' => $currentVersion, 'hash' => 'test-only', 'verified_at' => time(),
]));
$badTarget = rbAction('apply_prepare');
rbAssert(($badTarget['code'] ?? 0) === 1, '目标版本与已验签 version 不同的增量包被拒绝');
rbAssert(str_contains((string) ($badTarget['msg'] ?? ''), '目标不匹配'), '拒绝原因明确指出目标不匹配');
@unlink($badZip);
@unlink($ROOT . '/storage/upgrade/package-meta.json');

// ---- 6) 防呆：非法备份名被拒 ----
$bad = rbAction('apply_rollback', ['backup' => '../../config']);
rbAssert(($bad['code'] ?? 0) === 1, '非法备份目录名被拒绝');

rbCleanup();
@unlink($JAR);
echo "\n✅ 升级回滚 e2e 通过：快照 → rollback.json → 三类文件还原 全链路 OK\n";
