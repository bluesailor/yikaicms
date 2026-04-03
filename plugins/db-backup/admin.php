<?php
/**
 * 数据库备份插件 - 后台管理页面
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

// 将 SQLite CREATE TABLE 转为 MySQL CREATE TABLE
function _sqliteToMysqlCreate(string $sql, string $collation): string
{
    // INTEGER PRIMARY KEY AUTOINCREMENT -> int(11) UNSIGNED NOT NULL AUTO_INCREMENT + PRIMARY KEY
    $sql = preg_replace(
        '/(\w+)\s+INTEGER\s+PRIMARY\s+KEY\s+AUTOINCREMENT/i',
        '`$1` int(11) UNSIGNED NOT NULL AUTO_INCREMENT',
        $sql
    );
    // TEXT NOT NULL DEFAULT '' -> varchar(255) NOT NULL DEFAULT ''
    $sql = preg_replace('/\bTEXT\s+NOT\s+NULL\s+DEFAULT\s+\'\'/i', "varchar(255) NOT NULL DEFAULT ''", $sql);
    // TEXT NOT NULL DEFAULT 'xxx' -> varchar(255) NOT NULL DEFAULT 'xxx'
    $sql = preg_replace('/\bTEXT\s+NOT\s+NULL\s+DEFAULT\s+\'([^\']+)\'/i', "varchar(255) NOT NULL DEFAULT '$1'", $sql);
    // TEXT NOT NULL UNIQUE -> varchar(255) NOT NULL UNIQUE
    $sql = preg_replace('/\bTEXT\s+NOT\s+NULL\s+UNIQUE\b/i', 'varchar(255) NOT NULL UNIQUE', $sql);
    // TEXT NOT NULL -> varchar(255) NOT NULL
    $sql = preg_replace('/\bTEXT\s+NOT\s+NULL\b/i', 'varchar(255) NOT NULL', $sql);
    // TEXT (nullable) -> text
    // (TEXT alone stays as text, which is valid MySQL)
    // INTEGER NOT NULL DEFAULT x -> int(11) NOT NULL DEFAULT x
    $sql = preg_replace('/\bINTEGER\s+NOT\s+NULL/i', 'int(11) NOT NULL', $sql);
    // INTEGER -> int(11)
    $sql = preg_replace('/\bINTEGER\b/i', 'int(11)', $sql);
    // REAL -> decimal(10,2)
    $sql = preg_replace('/\bREAL\b/i', 'decimal(10,2)', $sql);
    // 表名加反引号
    $sql = preg_replace('/CREATE\s+TABLE\s+(\w+)/i', 'CREATE TABLE `$1`', $sql);
    // 字段名加反引号（简单处理：行首4空格后的字段名）
    $sql = preg_replace('/^(\s+)(\w+)\s+(int|varchar|text|decimal|longtext|tinyint)/mi', '$1`$2` $3', $sql);
    // 追加 ENGINE 和 CHARSET
    $sql = preg_replace('/\)\s*$/m', ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE={$collation}", $sql);
    return $sql;
}

// 获取数据库版本
if (db()->isSqlite()) {
    $dbVersion = 'SQLite ' . db()->getPdo()->query('SELECT sqlite_version()')->fetchColumn();
    $dbVersionShort = 'SQLite';
} else {
    $fullVer = db()->fetchColumn("SELECT VERSION()");
    $dbVersion = 'MySQL ' . $fullVer;
    $dbVersionShort = 'MySQL ' . (version_compare($fullVer, '8.0.0', '>=') ? '8.0' : '5.7');
}

// 获取所有表
$allTables = [];
if (db()->isSqlite()) {
    $rows = db()->fetchAll("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE ? ORDER BY name", [DB_PREFIX . '%']);
} else {
    $rows = db()->fetchAll("SHOW TABLES LIKE '" . DB_PREFIX . "%'");
}
foreach ($rows as $row) {
    $tableName = db()->isSqlite() ? $row['name'] : array_values($row)[0];
    $info = db()->fetchOne("SELECT COUNT(*) as cnt FROM `{$tableName}`");
    $allTables[] = [
        'name' => $tableName,
        'rows' => (int)($info['cnt'] ?? 0),
    ];
}

// 导出操作
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['backup_action'] ?? '') === 'export') {
    $selectedTables = $_POST['tables'] ?? [];
    $includeStructure = !empty($_POST['structure']);
    $includeData = !empty($_POST['data']);
    $exportFormat = $_POST['export_format'] ?? 'auto'; // auto, mysql57, mysql80

    if (empty($selectedTables)) {
        // 选择全部
        $selectedTables = array_column($allTables, 'name');
    }

    // 验证表名
    $validNames = array_column($allTables, 'name');
    $selectedTables = array_filter($selectedTables, fn($t) => in_array($t, $validNames));

    if (empty($selectedTables)) {
        header('Content-Type: application/json');
        echo json_encode(['code' => 1, 'msg' => '没有有效的表']);
        exit;
    }

    // 生成 SQL
    $isSqlite = db()->isSqlite();
    $forceMysql = in_array($exportFormat, ['mysql57', 'mysql80']);
    $dbLabel = $isSqlite ? 'SQLite' : (defined('DB_NAME') ? DB_NAME : 'database');
    $collation = ($exportFormat === 'mysql57') ? 'utf8mb4_general_ci' : 'utf8mb4_0900_ai_ci';
    $formatLabel = match ($exportFormat) {
        'mysql57' => 'MySQL 5.7',
        'mysql80' => 'MySQL 8.0',
        default   => ($isSqlite ? 'SQLite' : 'MySQL'),
    };

    $sql = "-- ============================================================\n";
    $sql .= "-- Yikai CMS 数据库备份\n";
    $sql .= "-- 导出时间: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- 源数据库: " . $dbLabel . "\n";
    $sql .= "-- 导出格式: " . $formatLabel . "\n";
    $sql .= "-- ============================================================\n\n";

    if ($isSqlite && !$forceMysql) {
        $sql .= "PRAGMA foreign_keys = OFF;\n\n";
    } else {
        $sql .= "SET NAMES utf8mb4;\n";
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";
    }

    foreach ($selectedTables as $table) {
        $sql .= "-- -----------------------------------------------------------\n";
        $sql .= "-- 表: {$table}\n";
        $sql .= "-- -----------------------------------------------------------\n\n";

        // 表结构
        if ($includeStructure) {
            if ($isSqlite) {
                $createResult = db()->fetchOne("SELECT sql FROM sqlite_master WHERE type='table' AND name=?", [$table]);
                if ($createResult && $createResult['sql']) {
                    $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
                    if ($forceMysql) {
                        $sql .= _sqliteToMysqlCreate($createResult['sql'], $collation) . ";\n\n";
                    } else {
                        $sql .= $createResult['sql'] . ";\n\n";
                    }
                }
            } else {
                $createResult = db()->fetchOne("SHOW CREATE TABLE `{$table}`");
                if ($createResult) {
                    $createSql = $createResult['Create Table'] ?? '';
                    // 如果目标是 5.7，替换 8.0 专有的 collation
                    if ($exportFormat === 'mysql57') {
                        $createSql = str_replace('utf8mb4_0900_ai_ci', 'utf8mb4_general_ci', $createSql);
                    }
                    $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
                    $sql .= $createSql . ";\n\n";
                }
            }
        }

        // 表数据
        if ($includeData) {
            $dataRows = db()->fetchAll("SELECT * FROM `{$table}`");
            if (!empty($dataRows)) {
                $columns = array_keys($dataRows[0]);
                $colList = '`' . implode('`, `', $columns) . '`';

                // 分批插入，每100行一条INSERT
                $chunks = array_chunk($dataRows, 100);
                foreach ($chunks as $chunk) {
                    $sql .= "INSERT INTO `{$table}` ({$colList}) VALUES\n";
                    $values = [];
                    foreach ($chunk as $dataRow) {
                        $vals = [];
                        foreach ($dataRow as $val) {
                            if ($val === null) {
                                $vals[] = 'NULL';
                            } else {
                                $vals[] = "'" . addslashes((string)$val) . "'";
                            }
                        }
                        $values[] = '(' . implode(', ', $vals) . ')';
                    }
                    $sql .= implode(",\n", $values) . ";\n\n";
                }
            }
        }
    }

    if ($isSqlite && !$forceMysql) {
        $sql .= "PRAGMA foreign_keys = ON;\n";
    } else {
        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    }

    // 输出下载
    $formatSuffix = $forceMysql ? "_{$exportFormat}" : '';
    $filename = 'backup_' . $dbLabel . $formatSuffix . '_' . date('Ymd_His') . '.sql';

    adminLog('plugin', 'db-backup', '导出数据库备份: ' . count($selectedTables) . '个表');

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($sql));
    header('Cache-Control: no-cache');
    echo $sql;
    exit;
}

$pageTitle = '数据库备份';
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="max-w-3xl">
    <!-- 返回按钮 -->
    <div class="mb-4">
        <a href="/admin/plugin.php" class="text-sm text-gray-500 hover:text-primary inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
            返回插件管理
        </a>
    </div>

    <form method="post" id="backupForm">
        <?php echo csrfField(); ?>
        <input type="hidden" name="backup_action" value="export">

        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b flex items-center justify-between">
                <div>
                    <h2 class="font-bold text-gray-800">数据库备份</h2>
                    <p class="text-sm text-gray-500 mt-1">当前数据库：<?php echo $dbVersion; ?>，选择要导出的表。</p>
                </div>
                <input type="hidden" name="export_format" id="exportFormat" value="auto">
                <div class="relative" id="exportDropdown">
                    <button type="button" onclick="document.getElementById('exportMenu').classList.toggle('hidden')" class="bg-primary hover:bg-secondary text-white px-5 py-2 rounded transition inline-flex items-center gap-2 cursor-pointer">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        导出备份
                        <svg class="w-3 h-3 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>
                    <div id="exportMenu" class="hidden absolute right-0 mt-1 w-48 bg-white rounded-lg shadow-lg border z-50 py-1">
                        <button type="submit" onclick="setFormat('auto')" class="w-full text-left px-4 py-2 text-sm hover:bg-gray-50 cursor-pointer"><?php echo $dbVersionShort; ?> (当前)</button>
                        <button type="submit" onclick="setFormat('mysql57')" class="w-full text-left px-4 py-2 text-sm hover:bg-gray-50 cursor-pointer">MySQL 5.7</button>
                        <button type="submit" onclick="setFormat('mysql80')" class="w-full text-left px-4 py-2 text-sm hover:bg-gray-50 cursor-pointer">MySQL 8.0</button>
                    </div>
                </div>
            </div>

            <div class="p-6">
                <!-- 导出选项 -->
                <div class="flex flex-wrap items-center gap-6 mb-6 pb-4 border-b">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="structure" value="1" checked class="w-4 h-4 rounded">
                        <span>导出表结构</span>
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="data" value="1" checked class="w-4 h-4 rounded">
                        <span>导出表数据</span>
                    </label>
                    <div class="flex-1"></div>
                    <button type="button" onclick="toggleAll(true)" class="text-xs text-primary hover:underline cursor-pointer">全选</button>
                    <button type="button" onclick="toggleAll(false)" class="text-xs text-gray-500 hover:underline cursor-pointer">全不选</button>
                </div>

                <!-- 表列表 -->
                <div class="space-y-2">
                    <?php foreach ($allTables as $t): ?>
                    <label class="flex items-center gap-3 p-3 rounded-lg hover:bg-gray-50 cursor-pointer">
                        <input type="checkbox" name="tables[]" value="<?php echo e($t['name']); ?>" checked class="table-check w-4 h-4 rounded">
                        <span class="font-mono text-sm text-gray-700 flex-1"><?php echo e($t['name']); ?></span>
                        <span class="text-xs text-gray-400"><?php echo number_format($t['rows']); ?> 行</span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
function toggleAll(checked) {
    document.querySelectorAll('.table-check').forEach(function(cb) {
        cb.checked = checked;
    });
}
function setFormat(fmt) {
    document.getElementById('exportFormat').value = fmt;
}
// 点击外部关闭下拉
document.addEventListener('click', function(e) {
    var menu = document.getElementById('exportMenu');
    if (!document.getElementById('exportDropdown').contains(e.target)) {
        menu.classList.add('hidden');
    }
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
