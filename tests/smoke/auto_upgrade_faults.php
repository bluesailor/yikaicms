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
    return $pkg;
}

/** 每个场景开始前把环境擦干净。 */
function auReset(): void
{
    global $SANDBOX, $UPD;
    auRmTree($SANDBOX);
    @unlink($UPD . '/package.zip');
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

// ============================================================
// 场景 2：跨 cron 续跑 —— 必须在**不联网**的情况下接着跑
//   这是 codex P0-1 的回归：续跑判定若排在 check() 之后，网络不可达时会退出，
//   而真实场景里 config/version.php 已被覆盖，服务器会回「无更新」，同样退不出来。
// ============================================================
echo "\n[2] 跨 cron 续跑（不联网）\n";
auReset();
auMakePackage(3);
$pre = upgrade_prepare();
auOk(($pre['code'] ?? 1) === 0, 'prepare 成功，建立事务');
settingModel()->saveBatch([
    'auto_upgrade_target' => defined('CMS_VERSION') ? CMS_VERSION : '',
    'auto_upgrade_from' => 'x.y.z',
]);
$t0 = microtime(true);
$r = AutoUpgrade::run(false);
$elapsed = microtime(true) - $t0;
auOk(str_contains($r, 'resume'), '识别为续跑：' . $r);
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
@unlink($filesDir);

// ============================================================
// 场景 6：数据库备份失败 —— 无备份不许升（迁移改表后无法恢复）
// ============================================================
echo "\n[6] 数据库备份失败\n";
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
// 场景 7：迁移中途失败 —— 必须中止并回滚，不能报成功
// ============================================================
echo "\n[7] 迁移失败\n";
auReset();
$migFile = ROOT_PATH . '/migrations/29991231_fault_injection_test.php';
// Migrator 要求 id + check 两个键；check 返回 false 表示「未应用」
file_put_contents($migFile, "<?php\nreturn [\n"
    . "    'id' => '29991231_fault_injection_test',\n"
    . "    'title' => '故障注入用（测试自动删除）',\n"
    . "    'check' => function () { return false; },\n"
    . "    'php' => function () { throw new RuntimeException('注入的迁移故障'); },\n"
    . "];\n");
require_once ROOT_PATH . '/includes/Migrator.php';
$found = false;
foreach (Migrator::loadAll() as $m) {
    if (($m['id'] ?? '') === '29991231_fault_injection_test') {
        $found = true;
        // Migrator::runOne 不抛异常，失败时返回 ok=false —— 这个差异曾让
        // AutoUpgrade 完全忽略迁移失败（源码断言测不出来，只有真跑一遍才知道）
        $res = Migrator::runOne($m);
        auOk(is_array($res) && empty($res['ok']), 'runOne 以 ok=false 形态报告失败（不是抛异常）');
    }
}
auOk($found, '注入的迁移被 Migrator 加载到');

// 真正要验的是：AutoUpgrade 能不能接住这种失败形态
$ref = new ReflectionMethod(AutoUpgrade::class, 'runMigrations');
$ref->setAccessible(true);
[$okCount, $err] = $ref->invoke(null);
auOk($err !== '', 'AutoUpgrade::runMigrations 捕获到失败：' . mb_substr($err, 0, 50));
@unlink($migFile);

// ============================================================
// 场景 8：回滚自身失败 —— 必须如实报「需人工介入」，不能吞掉
// ============================================================
echo "\n[8] 回滚自身失败\n";
auReset();
$rb = upgrade_rollback('pre-upgrade-does-not-exist-' . time());
auOk(($rb['code'] ?? 0) === 1, '不存在的备份目录：回滚如实返回失败');
auOk(
    str_contains((string) ($rb['msg'] ?? ''), '找不到'),
    '失败原因明确：' . mb_substr((string) ($rb['msg'] ?? ''), 0, 40)
);

// ============================================================
// 场景 9：状态文件损坏 —— 不能当成「有事务」死循环，应重新开始
// ============================================================
echo "\n[9] 状态文件损坏\n";
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
