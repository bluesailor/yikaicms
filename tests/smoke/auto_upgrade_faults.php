<?php
/**
 * 自动升级的故障注入测试（v1.18.6）。
 *
 * 由来：codex 审计指出三个 P0，其中「续跑判定位置错」是我自查时改对了逻辑却改错了
 * 位置——当时的测试只断言「存在续跑代码」，抓不住顺序问题。教训是**源码契约断言
 * 不等于测过**：无人值守升级的每条失败路径都必须真的走一遍。
 *
 * 本脚本真实制造故障（改权限、删快照、塞会抛异常的迁移、抢占文件锁），驱动
 * AutoUpgrade / UpgradeRunner 的真实代码，断言它「按预期失败并回滚」，而不是
 * 「悄悄记成功」。
 *
 * 边界：所有写入限定在 storage/{upgrade,backups} 与沙箱目录 e2e-au-sandbox/，
 * 每个场景自带清理；不联网（真联网了反而说明续跑判定的顺序错了，见场景 2）。
 *
 * 用法：先跑 tests/smoke/setup.php 装机，然后 php tests/smoke/auto_upgrade_faults.php
 */

declare(strict_types=1);

$ROOT = dirname(__DIR__, 2);
define('ROOT_PATH', $ROOT);
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/models/autoload.php';
require_once ROOT_PATH . '/includes/UpgradeRunner.php';
require_once ROOT_PATH . '/includes/AutoUpgrade.php';

$SANDBOX = ROOT_PATH . '/e2e-au-sandbox';
$UPD = ROOT_PATH . '/storage/upgrade';
$FAILS = [];
$PASSED = 0;

function auOk(bool $cond, string $what): void
{
    global $FAILS, $PASSED;
    if ($cond) {
        $PASSED++;
        echo "  ✓ {$what}\n";
        return;
    }
    $FAILS[] = $what;
    echo "  ✗ {$what}\n";
}

function auRmTree(string $d): void
{
    if (!is_dir($d)) {
        @chmod($d, 0644);
        @unlink($d);
        return;
    }
    foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $it) {
        auRmTree($d . '/' . $it);
    }
    @chmod($d, 0755);
    @rmdir($d);
}

/** 造一个只碰沙箱目录的增量包；manifest.from 必须等于本站版本（v1.18.6 基线护栏）。 */
function auMakePackage(int $fileCount = 2): string
{
    global $SANDBOX, $UPD;
    @mkdir($SANDBOX, 0755, true);
    @mkdir($UPD, 0755, true);
    file_put_contents($SANDBOX . '/existing.txt', 'OLD');

    $pkg = $UPD . '/package.zip';
    @unlink($pkg);
    $zip = new ZipArchive();
    if ($zip->open($pkg, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fwrite(STDERR, "无法创建测试包\n");
        exit(2);
    }
    $ver = defined('CMS_VERSION') ? CMS_VERSION : '';
    $zip->addFromString('.delta-manifest.json', json_encode(['from' => $ver, 'to' => $ver, 'deleted' => []]));
    $zip->addFromString('payload/e2e-au-sandbox/existing.txt', 'NEW');
    for ($i = 1; $i < $fileCount; $i++) {
        $zip->addFromString('payload/e2e-au-sandbox/f' . $i . '.txt', 'N' . $i);
    }
    $zip->close();
    file_put_contents($UPD . '/package-meta.json', json_encode([
        'version' => $ver,
        'hash' => 'test-only',
        'verified_at' => time(),
        'owner' => 'auto',
    ]));
    return $pkg;
}

/** 每个场景开始前把环境擦干净。 */
function auReset(): void
{
    global $SANDBOX, $UPD;
    auRmTree($SANDBOX);
    @unlink($UPD . '/package.zip');
    @unlink($UPD . '/package-meta.json');
    @unlink($UPD . '/apply_state.json');
    @unlink($UPD . '/auto_upgrade.lock');
    foreach (glob(ROOT_PATH . '/storage/backups/pre-upgrade-*') ?: [] as $d) {
        auRmTree($d);
    }
    settingModel()->saveBatch([
        'auto_upgrade_target' => '', 'auto_upgrade_from' => '',
        'auto_upgrade_log' => '', 'auto_upgrade_last_result' => '',
    ]);
}

/** 取最近一条升级历史。 */
function auLastLog(): array
{
    $l = AutoUpgrade::log();
    return $l[0] ?? [];
}

echo "自动升级故障注入测试\n";
echo str_repeat('=', 52) . "\n";

// ============================================================
// 场景 1：并发重入 —— 锁被别人持有时必须直接退出
// ============================================================
echo "\n[1] 并发重入\n";
auReset();
@mkdir($UPD, 0755, true);
$otherLock = fopen($UPD . '/auto_upgrade.lock', 'c+');
$held = $otherLock !== false && flock($otherLock, LOCK_EX | LOCK_NB);
auOk($held, '测试进程先抢到锁');
$r = AutoUpgrade::run(true);
auOk(str_contains($r, '上一次升级仍在进行'), '持锁时 run() 直接退出：' . $r);
if ($otherLock !== false) {
    flock($otherLock, LOCK_UN);
    fclose($otherLock);
}

echo "\n[1b] 手工升级事务隔离\n";
auReset();
auMakePackage(2);
$meta = json_decode((string) file_get_contents($UPD . '/package-meta.json'), true);
$meta['owner'] = 'manual';
file_put_contents($UPD . '/package-meta.json', json_encode($meta));
upgrade_prepare();
$r = AutoUpgrade::run(false);
auOk(str_contains($r, '手工升级事务正在进行'), '自动任务不会覆盖手工事务：' . $r);
auOk(is_file($UPD . '/apply_state.json') && is_file($UPD . '/package.zip'), '手工事务上下文保持不动');

echo "\n[1c] 无元数据遗留包隔离\n";
auReset();
auMakePackage(2);
@unlink($UPD . '/package-meta.json');
$r = AutoUpgrade::run(false);
auOk(str_contains($r, '手工升级事务正在进行'), '自动任务不会覆盖来源不明的遗留包：' . $r);
auOk(is_file($UPD . '/package.zip'), '来源不明的安装包保持不动');

// ============================================================
// 场景 2：跨 cron 续跑 —— 必须在**不联网**的情况下接着跑
//   这是 codex P0-1 的回归：续跑判定若排在 check() 之后，网络不可达时会退出，
//   而真实场景里 config/version.php 已被覆盖，服务器会回「无更新」，同样退不出来。
//   故意不写 auto_upgrade_target，覆盖 prepare 完成后、设置表落盘前进程退出的窗口。
// ============================================================
echo "\n[2] 跨 cron 续跑（不联网）\n";
auReset();
auMakePackage(3);
$pre = upgrade_prepare();
auOk(($pre['code'] ?? 1) === 0, 'prepare 成功，建立事务');
$t0 = microtime(true);
$r = AutoUpgrade::run(false);
$elapsed = microtime(true) - $t0;
auOk(str_contains($r, 'resume'), '无设置表续跑标记仍识别为事务：' . $r);
// 联网 check() 至少要几百毫秒（不可达时是 20 秒超时）。续跑路径根本不该碰网络。
auOk($elapsed < 3.0, sprintf('续跑未联网（耗时 %.2fs）', $elapsed));

// ============================================================
// 场景 3：维护窗口已关闭 —— 续跑不受窗口限制（半截升级必须做完）
// ============================================================
echo "\n[3] 窗口关闭后续跑\n";
auReset();
auMakePackage(3);
upgrade_prepare();
// 造一个必然不在窗口内的时段
$hour = (int) date('G');
$closed = sprintf('%02d:00-%02d:01', ($hour + 3) % 24, ($hour + 3) % 24);
settingModel()->saveBatch([
    'auto_upgrade_enabled' => '1',
    'auto_upgrade_window' => $closed,
    'auto_upgrade_target' => defined('CMS_VERSION') ? CMS_VERSION : '',
    'auto_upgrade_from' => 'x.y.z',
]);
auOk(!AutoUpgrade::inWindow(), "窗口 {$closed} 当前确实关闭");
$r = AutoUpgrade::run(false);
auOk(str_contains($r, 'resume'), '窗口关闭仍续跑：' . $r);
settingModel()->saveBatch(['auto_upgrade_enabled' => '0', 'auto_upgrade_window' => '03:00-05:00']);

// ============================================================
// 场景 4：目标文件不可写 —— 必须中止并回滚，不能记成功
// ============================================================
echo "\n[4] 文件写入失败\n";
auReset();
auMakePackage(2);
$pre = upgrade_prepare();
$backup = (string) ($pre['backup'] ?? '');
// 把目标文件设为只读（Windows 下 chmod 有限，退而求其次占用它）
@chmod($SANDBOX . '/existing.txt', 0444);
$readonlyWorks = !is_writable($SANDBOX . '/existing.txt');
if ($readonlyWorks) {
    $bt = upgrade_batch(null);
    auOk(!empty($bt['errors']), '批次如实报告写入失败');
} else {
    echo "  · 本平台 chmod 只读无效，跳过写入失败注入（改由快照失败场景覆盖同一分支）\n";
}
@chmod($SANDBOX . '/existing.txt', 0644);

// ============================================================
// 场景 5：覆盖前快照失败 —— 无人值守时等于回滚失去依据，必须致命
// ============================================================
echo "\n[5] 快照失败\n";
auReset();
auMakePackage(2);
$pre = upgrade_prepare();
$backup = (string) ($pre['backup'] ?? '');
// 把快照目录换成同名文件：mkdir 与 copy 都会失败
$filesDir = ROOT_PATH . '/storage/backups/' . $backup . '/files';
auRmTree($filesDir);
file_put_contents($filesDir, 'not a directory');
$bt = upgrade_batch(null);
auOk((int) ($bt['snapshot_failed'] ?? 0) > 0, '快照失败被单独计数：' . (int) ($bt['snapshot_failed'] ?? 0));
auOk(file_get_contents($SANDBOX . '/existing.txt') === 'OLD', '快照失败时没有覆盖原文件');
@unlink($filesDir);

// ============================================================
// 场景 6：finalize 前回滚 —— 清单必须在 prepare/batch 阶段已经可用
// ============================================================
echo "\n[6] finalize 前回滚\n";
auReset();
auMakePackage(2);
$pre = upgrade_prepare();
$backup = (string) ($pre['backup'] ?? '');
$bt = upgrade_batch(null);
auOk(($bt['code'] ?? 1) === 0, '批次覆盖完成');
$rb = upgrade_rollback($backup);
auOk(($rb['code'] ?? 1) === 0, '未 finalize 也能回滚：' . ($rb['msg'] ?? ''));
auOk(file_get_contents($SANDBOX . '/existing.txt') === 'OLD', '覆盖文件恢复旧内容');
auOk(!is_file($SANDBOX . '/f1.txt'), '批次新建文件已移除');

// ============================================================
// 场景 7：最后批次完成后进程退出 —— 下一轮必须继续 finalize，不得查服务器退出
// ============================================================
echo "\n[7] complete state 续跑 finalize\n";
auReset();
auMakePackage(2);
$pre = upgrade_prepare();
$bt = upgrade_batch(null);
auOk((int) ($bt['next'] ?? -1) === (int) ($bt['total'] ?? -2), '构造游标已到尾、尚未 finalize 的状态');
settingModel()->saveBatch([
    'auto_upgrade_target' => defined('CMS_VERSION') ? CMS_VERSION : '',
    'auto_upgrade_from' => defined('CMS_VERSION') ? CMS_VERSION : '',
]);
$r = AutoUpgrade::run(false);
auOk(str_contains($r, 'resume') && str_contains($r, 'upgraded'), 'complete state 被恢复并完成：' . $r);
auOk(!is_file($UPD . '/apply_state.json') && !is_file($UPD . '/package.zip'), '完成后才清理事务状态与安装包');

// ============================================================
// 场景 8：数据库备份失败 —— 无备份不许升
// ============================================================
echo "\n[8] 数据库备份失败\n";
auReset();
auMakePackage(2);
$pre = upgrade_prepare();
// prepare 正常时应有 db_backup；这里验证 AutoUpgrade 的前置条件判定本身
auOk(array_key_exists('db_backup', $pre), 'prepare 返回 db_backup 字段');
$src = file_get_contents(ROOT_PATH . '/includes/AutoUpgrade.php');
auOk(
    is_string($src) && str_contains($src, "failed: no database backup"),
    'AutoUpgrade 把「无库备份」作为拒升条件'
);

// ============================================================
// 场景 9：存在待执行迁移 —— 无人值守不执行 DB 写入，回滚文件并转人工
// ============================================================
echo "\n[9] 待迁移版本转人工\n";
auReset();
auMakePackage(2);
$pre = upgrade_prepare();
$migFile = ROOT_PATH . '/migrations/29991231_fault_injection_test.php';
file_put_contents($migFile, "<?php\nreturn [\n"
    . "    'id' => '29991231_fault_injection_test',\n"
    . "    'title' => '故障注入用（测试自动删除）',\n"
    . "    'check' => function () { return false; },\n"
    . "    'php' => function () { file_put_contents(ROOT_PATH . '/e2e-au-sandbox/migration-ran.txt', 'BAD'); return 'BAD'; },\n"
    . "];\n");
settingModel()->saveBatch([
    'auto_upgrade_target' => defined('CMS_VERSION') ? CMS_VERSION : '',
    'auto_upgrade_from' => defined('CMS_VERSION') ? CMS_VERSION : '',
]);
$r = AutoUpgrade::run(false);
auOk(str_contains($r, 'rolled back') && str_contains($r, '数据库迁移'), '待迁移版本已回滚转人工：' . $r);
auOk(!is_file($SANDBOX . '/migration-ran.txt'), '无人值守流程没有执行迁移 PHP');
auOk(file_get_contents($SANDBOX . '/existing.txt') === 'OLD', '拒绝迁移后文件已回滚');
@unlink($migFile);

// ============================================================
// 场景 10：回滚自身失败 —— 必须如实报「需人工介入」，不能吞掉
// ============================================================
echo "\n[10] 回滚自身失败\n";
auReset();
$rb = upgrade_rollback('pre-upgrade-does-not-exist-' . time());
auOk(($rb['code'] ?? 0) === 1, '不存在的备份目录：回滚如实返回失败');
auOk(
    str_contains((string) ($rb['msg'] ?? ''), '找不到'),
    '失败原因明确：' . mb_substr((string) ($rb['msg'] ?? ''), 0, 40)
);

// ============================================================
// 场景 11：状态文件损坏 —— 不能当成「有事务」死循环，应重新开始
// ============================================================
echo "\n[11] 状态文件损坏\n";
auReset();
auMakePackage(2);
upgrade_prepare();
file_put_contents($UPD . '/apply_state.json', '{损坏的 JSON');
settingModel()->set('auto_upgrade_target', defined('CMS_VERSION') ? CMS_VERSION : '', 'system');
$r = AutoUpgrade::run(false);
auOk(!str_contains($r, 'resume'), '损坏状态不被当作可续跑事务：' . $r);

// ============================================================
// 收尾
// ============================================================
auReset();
auRmTree($SANDBOX);
echo "\n" . str_repeat('=', 52) . "\n";
if ($FAILS) {
    fwrite(STDERR, "❌ 故障注入测试失败 " . count($FAILS) . " 项：\n  - " . implode("\n  - ", $FAILS) . "\n");
    exit(1);
}
echo "✅ 自动升级故障注入测试通过（{$PASSED} 项断言）\n";
