<?php
/**
 * S3：后台 AI 助手的应用/撤销（AiAssistantChanges）在真实数据库上的验证，含 MySQL 行锁路径与并发撤销。
 * 连接方式同 proposal-txn-run.php（YK_S103_* 环境变量，库/文件名受 s103 前缀约束）；核心表取自安装 SQL，
 * 变更日志取自正式迁移。能力是真实的 cms_* 能力；后台身份与 config() 用最小桩（config 每次直读设置表）。
 *
 *   YK_S103_DRIVER=sqlite YK_S103_SQLITE_PATH=<临时目录>/s103-assistant.sqlite php tests/ai-access/assistant-txn-run.php
 *
 * 退出码：0 = 通过；1 = 失败；2 = 环境错误。
 */

declare(strict_types=1);

require __DIR__ . '/proposal-fixture.php';

session_save_path(sys_get_temp_dir());
if (session_status() === PHP_SESSION_NONE) @session_start();
function hasPermission(string $permission): bool { return true; }
function isSuperAdmin(): bool { return true; }
function adminLog(...$args): void {}
function aiService(): never { throw new RuntimeException('AI must not be called on apply/undo'); }
/** 生产 config() 读设置表（请求内快照另由服务清掉）；这里每次直读，模拟“读到最新值”。 */
function config(string $key, mixed $default = ''): mixed
{
    $value = db()->fetchColumn('SELECT `value` FROM ' . DB_PREFIX . 'settings WHERE `key` = ?', [$key]);
    return $value === false || $value === null ? $default : $value;
}

$db = s103Boot();
require_once ROOT_PATH . '/includes/permissions.php';
require_once ROOT_PATH . '/includes/Abilities.php';
require_once ROOT_PATH . '/includes/abilities/cms_basics.php';
require_once ROOT_PATH . '/includes/abilities/cms_admin.php';
require_once ROOT_PATH . '/includes/AiAssistantChanges.php';
$_SESSION['admin_id'] = 1;
$_SESSION['admin_permissions'] = ['*'];

if (($argv[1] ?? '') === '--undo') {
    // 子进程：等起跑信号后撤销同一条日志，输出结果。
    [, , $logId, $go] = $argv;
    while (!is_file($go)) usleep(2000);
    echo json_encode((new AiAssistantChanges($db))->undo(1, (int) $logId), JSON_UNESCAPED_UNICODE);
    exit(0);
}

$p = S103_PREFIX;
try {
    s103ResetSchema($db);
    s103Seed($db, time());
    $db->execute("INSERT INTO {$p}settings (`group`, `key`, `value`) VALUES ('basic', 'site_icp', 'ICP-1')");
} catch (Throwable $error) {
    fwrite(STDERR, 'environment error: ' . $error->getMessage() . "\n");
    exit(2);
}

$results = [];
$check = static function (bool $ok, string $label) use (&$results): void { $results[] = [$ok, $label]; };
$svc = new AiAssistantChanges($db);
$col = static fn(string $sql, array $params = []) => $db->fetchColumn($sql, $params);
$stage = static function (array $items): string {
    $set = AiStaging::newSet();
    foreach ($items as [$ability, $input]) AiStaging::add($set, $ability, $input, ['summary' => $ability]);
    return $set;
};

// 1. 部分应用：只写勾选项，日志带应用后版本，未选项留在暂存。
$set = $stage([['cms_publish_content', ['id' => 100]], ['cms_set_content_flags', ['id' => 103, 'is_top' => 1]]]);
$r = $svc->applyStaged(1, 's103-admin', $set, ['p0']);
$check($r['success'] && array_column($r['applied'], 'proposal_id') === ['p0'] && (int) $col("SELECT status FROM {$p}contents WHERE id = 100") === 1, 'partial apply writes only the selected change');
$check(array_column(AiStaging::items($set), 'id') === ['p1'], 'the unselected item stays staged');
$logPublish = (int) $r['applied'][0]['log_id'];
$check(preg_match('/^[0-9a-f]{64}$/', (string) $col("SELECT after_revision FROM {$p}ai_change_log WHERE id = ?", [$logPublish])) === 1, 'the log row stores the after-apply revision');
$logFlags = (int) $svc->applyStaged(1, 's103-admin', $set, null)['applied'][0]['log_id'];

// 2. 日志写失败：业务改动一起回滚，条目留在暂存。
$trigger = $p . 'fail_assistant_log';
$db->execute($db->isSqlite()
    ? "CREATE TRIGGER {$trigger} BEFORE INSERT ON {$p}ai_change_log BEGIN SELECT RAISE(ABORT, 'forced'); END"
    : "CREATE TRIGGER {$trigger} BEFORE INSERT ON {$p}ai_change_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced'");
$set = $stage([['cms_auto_tag_content', ['id' => 100, 'tags' => 'x,y']]]);
$r = $svc->applyStaged(1, 's103-admin', $set, null);
$db->execute('DROP TRIGGER IF EXISTS ' . $trigger);
$check($r['applied'] === [] && (string) $col("SELECT tags FROM {$p}contents WHERE id = 100") === '' && count(AiStaging::items($set)) === 1,
    'a failed log write rolls the tag change back and keeps the item staged');
$check(!str_contains(implode(' ', $r['errors']), 'SQLSTATE') && !str_contains(implode(' ', $r['errors']), 'forced'), 'the error shown to the user carries no SQL error text');

// 3. 人工改过后撤销被拒，日志仍未撤销。
$db->execute("UPDATE {$p}contents SET is_top = 0 WHERE id = 103");
$u = $svc->undo(1, $logFlags);
$check(!$u['success'] && (int) $col("SELECT undone FROM {$p}ai_change_log WHERE id = ?", [$logFlags]) === 0 && (int) $col("SELECT is_top FROM {$p}contents WHERE id = 103") === 0,
    'undo after a human edit is refused and keeps the edit');

// 4. 两个进程同时撤销同一条：恰好一个成功，内容只回退一次。
$go = sys_get_temp_dir() . '/s103-assistant-go-' . bin2hex(random_bytes(4));
$procs = [];
foreach ([1, 2] as $n) {
    $procs[$n] = proc_open([PHP_BINARY, __FILE__, '--undo', (string) $logPublish, $go], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$n], ROOT_PATH, getenv());
}
usleep(400000);
touch($go);
$outs = [];
foreach ($procs as $n => $proc) {
    $outs[] = json_decode((string) stream_get_contents($pipes[$n][1]), true) ?? ['success' => false, 'error' => 'crash: ' . stream_get_contents($pipes[$n][2])];
    fclose($pipes[$n][1]);
    fclose($pipes[$n][2]);
    proc_close($proc);
}
@unlink($go);
$wins = count(array_filter($outs, static fn(array $o): bool => $o['success'] === true));
$check($wins === 1 && (int) $col("SELECT status FROM {$p}contents WHERE id = 100") === 0 && (int) $col("SELECT undone FROM {$p}ai_change_log WHERE id = ?", [$logPublish]) === 1,
    'two concurrent undos of one change: exactly one succeeds (' . json_encode(array_column($outs, 'error')) . ')');

// 5. 设置：撤销按最新值核对。
$set = $stage([['cms_update_setting', ['key' => 'site_icp', 'value' => 'ICP-2']]]);
$logSetting = (int) $svc->applyStaged(1, 's103-admin', $set, null)['applied'][0]['log_id'];
$db->execute("UPDATE {$p}settings SET `value` = 'ICP-3' WHERE `key` = 'site_icp'");
$refused = $svc->undo(1, $logSetting);
$db->execute("UPDATE {$p}settings SET `value` = 'ICP-2' WHERE `key` = 'site_icp'");
$ok = $svc->undo(1, $logSetting);
$check(!$refused['success'] && $ok['success'] && $col("SELECT `value` FROM {$p}settings WHERE `key` = 'site_icp'") === 'ICP-1', 'setting undo refuses after a later change and restores once it matches again');
$check(!$db->getPdo()->inTransaction(), 'no transaction left open');

$preview = Abilities::previewChange('cms_generate_seo_summary', ['id'=>100,'summary'=>'S4 fixed SEO']);
$seoBefore = $col("SELECT summary FROM {$p}contents WHERE id=100");
$set = $stage([['cms_generate_seo_summary',$preview['preview']['input']]]);
$seoApplied = $svc->applyStaged(1,'s103-admin',$set,null);
$check($seoApplied['success'] && $col("SELECT summary FROM {$p}contents WHERE id=100")==='S4 fixed SEO', 'SEO applies the pinned summary without another model call');
$seoLog = (int) ($seoApplied['applied'][0]['log_id'] ?? 0);
$check($svc->undo(1,$seoLog)['success'] && $col("SELECT summary FROM {$p}contents WHERE id=100")===$seoBefore, 'SEO undo restores the prior summary');
$set = $stage([['cms_generate_seo_summary',Abilities::previewChange('cms_generate_seo_summary',['id'=>100,'summary'=>'stale SEO'])['preview']['input']]]);
$db->execute("UPDATE {$p}contents SET content='<p>human edited source</p>' WHERE id=100");
$check(!$svc->applyStaged(1,'s103-admin',$set,null)['success'] && $col("SELECT summary FROM {$p}contents WHERE id=100")===$seoBefore, 'SEO refuses a source edited after proposal generation');

foreach (array_merge(S103_PROTOTYPE_TABLES, S103_CORE_TABLES) as $table) $db->execute('DROP TABLE IF EXISTS ' . S103_PREFIX . $table);
$failed = 0;
foreach ($results as [$okay, $label]) {
    echo ($okay ? 'ok   ' : 'FAIL ') . $label . "\n";
    $failed += $okay ? 0 : 1;
}
$server = $db->isSqlite() ? 'sqlite ' . $db->fetchColumn('SELECT sqlite_version()') : 'mysql ' . $db->fetchColumn('SELECT VERSION()');
printf("php=%s %s assertions=%d failed=%d\n", PHP_VERSION, $server, count($results), $failed);
exit($failed === 0 ? 0 : 1);
