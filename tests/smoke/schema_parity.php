<?php
/**
 * schema 双向对拍：全新安装 ≡ 老站跑完全部迁移。
 *
 * 为什么需要：install SQL 与 migrations 是两条独立演进的路径，历史上两个方向都漏过——
 *   - install SQL 漏了迁移加的列 → 全新安装缺列（v1.11.0 首页 500）
 *   - install SQL 有、迁移从未加 → 老站升级后缺列（cile.cn 1.9.2→1.13.2 报 Unknown column，共 9 列）
 * 单向检查只能抓一半，故做双向 diff。
 *
 * 做法：
 *   库 A = install/sql/sqlite.sql                       （全新安装拿到的结构）
 *   库 B = tests/fixtures/schema-baseline-sqlite.sql
 *          + Migrator 跑完全部迁移                        （老站升上来拿到的结构）
 *   比对 A、B 的「表 → 列」集合，任一方向有差异即失败。
 *
 * 基线取 v1.12.9——成规模真实用户的起点，也就是必须保证能升上来的下限。
 * 更早的站点碰上具体问题单独处理，不让 CI 背历史包袱。详见 fixture 文件头。
 *
 * 另外顺带比对 mysql.sql 与 sqlite.sql 的表/列——两份 install SQL 同样会漂移。
 *
 * 用法：php tests/smoke/schema_parity.php
 * 不读也不写 config/config.php 之外的站点数据，两个临时库都在 storage/ 下，跑完即删。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);

// ─────────────────────────────────────────────────────────────
// 已知且有意保留的差异。加条目务必写清理由，别拿它掩盖真 bug。
// ─────────────────────────────────────────────────────────────

/**
 * 老站升上来会有、但全新安装没有的表：历史遗留，迁移故意不 DROP（怕误删客户数据）。
 * 写带前缀的实际表名。
 */
const PARITY_LEGACY_TABLES = [
    'yikai_articles',            // v1.7.5 起并入 contents；老站的表留着不动
    'yikai_article_categories',  // 同上
];

/** 老站升上来会有、但全新安装没有的列。格式 '表.列' => '理由'。 */
const PARITY_LEGACY_COLUMNS = [
    // 暂无
];

// ─────────────────────────────────────────────────────────────
// 工具
// ─────────────────────────────────────────────────────────────

function pfail(string $msg): never
{
    fwrite(STDERR, "\n❌ SCHEMA PARITY FAILED\n\n" . $msg . "\n");
    exit(1);
}

/** 打开一个全新的 sqlite 库并导入 SQL 文件。 */
function pdo_from_sql(string $dbFile, string $sqlFile): PDO
{
    @unlink($dbFile);
    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec((string) file_get_contents($sqlFile));
    return $pdo;
}

/**
 * 取「表 → 列名集合」。忽略 sqlite 内部表。
 *
 * @return array<string, list<string>>
 */
function schema_of(PDO $pdo): array
{
    $out = [];
    $tables = $pdo->query(
        "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($tables as $t) {
        $cols = [];
        foreach ($pdo->query('PRAGMA table_info(' . $pdo->quote($t) . ')')->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $cols[] = (string) $c['name'];
        }
        sort($cols);
        $out[(string) $t] = $cols;
    }
    ksort($out);
    return $out;
}

/**
 * 从 MySQL DDL 里静态解析出「表 → 列名集合」。
 * 只认 `列名` 开头的行，跳过 KEY / PRIMARY KEY / UNIQUE / CONSTRAINT 等约束行。
 *
 * @return array<string, list<string>>
 */
function schema_of_mysql_ddl(string $sqlFile): array
{
    $sql = (string) file_get_contents($sqlFile);
    $out = [];

    if (!preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z0-9_]+)`?\s*\((.*?)\n\)\s*ENGINE/is', $sql, $ms, PREG_SET_ORDER)) {
        return $out;
    }

    foreach ($ms as $m) {
        $cols = [];
        foreach (explode("\n", $m[2]) as $line) {
            $line = trim($line);
            if ($line === '' || !str_starts_with($line, '`')) {
                continue;
            }
            // 约束行也可能以反引号开头？不会——KEY/UNIQUE/PRIMARY 都是关键字开头。
            if (preg_match('/^`([a-z0-9_]+)`/i', $line, $cm)) {
                $cols[] = $cm[1];
            }
        }
        sort($cols);
        $out[$m[1]] = $cols;
    }
    ksort($out);
    return $out;
}

/**
 * 比对两份 schema，返回人类可读的差异行。
 *
 * @param array<string, list<string>> $a
 * @param array<string, list<string>> $b
 * @return list<string>
 */
function diff_schema(array $a, array $b, string $nameA, string $nameB, array $ignoreTables = [], array $ignoreColumns = []): array
{
    $lines = [];

    foreach (array_diff(array_keys($b), array_keys($a)) as $t) {
        if (in_array($t, $ignoreTables, true)) continue;
        $lines[] = "  表 `$t`：$nameB 有，$nameA 没有";
    }
    foreach (array_diff(array_keys($a), array_keys($b)) as $t) {
        if (in_array($t, $ignoreTables, true)) continue;
        $lines[] = "  表 `$t`：$nameA 有，$nameB 没有";
    }

    foreach ($a as $t => $colsA) {
        if (!isset($b[$t]) || in_array($t, $ignoreTables, true)) continue;
        foreach (array_diff($colsA, $b[$t]) as $c) {
            if (isset($ignoreColumns["$t.$c"])) continue;
            $lines[] = "  列 `$t`.`$c`：$nameA 有，$nameB 没有";
        }
        foreach (array_diff($b[$t], $colsA) as $c) {
            if (isset($ignoreColumns["$t.$c"])) continue;
            $lines[] = "  列 `$t`.`$c`：$nameB 有，$nameA 没有";
        }
    }

    sort($lines);
    return $lines;
}

// ─────────────────────────────────────────────────────────────
// 1) 库 A —— 全新安装
// ─────────────────────────────────────────────────────────────

$freshDb = $root . '/storage/parity-fresh.sqlite';
$migrDb  = $root . '/storage/parity-migrated.sqlite';
@mkdir($root . '/storage', 0777, true);

$pdoFresh = pdo_from_sql($freshDb, $root . '/install/sql/sqlite.sql');
$schemaFresh = schema_of($pdoFresh);
echo '全新安装：' . count($schemaFresh) . " 张表\n";

// ─────────────────────────────────────────────────────────────
// 2) 库 B —— 基线 + 全部迁移
//    这里要用 CMS 的 Migrator（迁移的 check/php 闭包依赖 db()/config() 等），
//    因此在 require config.php 之前先把 DB 常量 define 掉，让本脚本的值生效，
//    绝不改动站点自己的 config.php。
// ─────────────────────────────────────────────────────────────

$pdoBase = pdo_from_sql($migrDb, $root . '/tests/fixtures/schema-baseline-sqlite.sql');
$baseTables = count(schema_of($pdoBase));
unset($pdoBase);   // 交给 CMS 自己连

define('ROOT_PATH', $root);
define('IK_CLI', true);
define('DEBUG', false);
define('DB_DRIVER', 'sqlite');
define('DB_PATH', $migrDb);
define('DB_PREFIX', 'yikai_');

// 站点 config.php 里同名 define 会「已定义」告警后失效（我们的值胜出），静音掉。
// CI 上若还没装机就退回 example，两者的非 DB 常量（路径、时区等）等价。
$cfg = is_file($root . '/config/config.php') ? $root . '/config/config.php' : $root . '/config/config.php.example';
set_error_handler(static fn (int $no, string $str): bool => str_contains($str, 'already defined'));
require $cfg;
restore_error_handler();

require_once $root . '/includes/functions.php';
require_once $root . '/includes/models/autoload.php';
require_once $root . '/includes/Migrator.php';

$migrations = Migrator::loadAll();
echo '基线：' . $baseTables . ' 张表，待跑迁移 ' . count($migrations) . " 条\n";

$ran = $skipped = 0;
$failures = [];
foreach ($migrations as $m) {
    if (Migrator::isApplied($m)) {
        $skipped++;
        continue;
    }
    $r = Migrator::runOne($m);
    if (!$r['ok']) {
        $failures[] = '  ' . $m['id'] . ' —— ' . $r['message'];
        continue;
    }
    $ran++;
    // 跑完必须自证已应用，否则 check 与 sqls 不自洽（后台升级页会一直显示待执行）
    if (!Migrator::isApplied($m)) {
        $failures[] = '  ' . $m['id'] . ' —— 执行成功但 check() 仍返回 false（check 与 sqls 不一致）';
    }
}
echo "迁移：执行 $ran 条，跳过 $skipped 条（基线已含）\n";

if ($failures) {
    pfail("以下迁移在「基线 + 全部迁移」路径上失败——真实老站升级也会这样：\n\n" . implode("\n", $failures));
}

$pdoMigrated = new PDO('sqlite:' . $migrDb);
$pdoMigrated->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$schemaMigrated = schema_of($pdoMigrated);
unset($pdoMigrated);
echo '老站升级后：' . count($schemaMigrated) . " 张表\n";

// ─────────────────────────────────────────────────────────────
// 3) 双向 diff
// ─────────────────────────────────────────────────────────────

$diff = diff_schema(
    $schemaFresh,
    $schemaMigrated,
    '全新安装',
    '老站升级后',
    PARITY_LEGACY_TABLES,
    PARITY_LEGACY_COLUMNS
);

if ($diff) {
    pfail(
        "全新安装与老站升级后的结构不一致：\n\n" . implode("\n", $diff) . "\n\n"
        . "怎么修：\n"
        . "  「全新安装有、老站升级后没有」→ 缺迁移。在 migrations/ 加一条把该表/列补上（判存在，幂等）。\n"
        . "  「老站升级后有、全新安装没有」→ 缺 install SQL。把该表/列补进 install/sql/mysql.sql 与 sqlite.sql；\n"
        . "     若是有意保留的历史遗留，加进本文件的 PARITY_LEGACY_* 并写明理由。"
    );
}
echo "✓ 全新安装 ≡ 老站升级后\n";

// ─────────────────────────────────────────────────────────────
// 4) 两份 install SQL 之间也要一致
// ─────────────────────────────────────────────────────────────

$schemaMysql = schema_of_mysql_ddl($root . '/install/sql/mysql.sql');
if (!$schemaMysql) {
    pfail('解析 install/sql/mysql.sql 失败：一张 CREATE TABLE 都没认出来，解析规则可能已过时。');
}

$diff2 = diff_schema($schemaMysql, $schemaFresh, 'mysql.sql', 'sqlite.sql');
if ($diff2) {
    pfail(
        "两份 install SQL 的结构不一致（同一版本的 MySQL 站与 SQLite 站会拿到不同的表结构）：\n\n"
        . implode("\n", $diff2)
    );
}
echo '✓ mysql.sql ≡ sqlite.sql（' . count($schemaMysql) . " 张表）\n";

@unlink($freshDb);
@unlink($migrDb);
echo "\nSCHEMA PARITY OK\n";
