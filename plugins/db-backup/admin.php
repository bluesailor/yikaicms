<?php
/**
 * 数据库备份插件 - 后台管理页面
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
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
    $dbLabel = $isSqlite ? 'SQLite' : (defined('DB_NAME') ? DB_NAME : 'database');

    $sql = "-- ============================================================\n";
    $sql .= "-- Yikai CMS 数据库备份\n";
    $sql .= "-- 导出时间: " . date('Y-m-d H:i:s') . "\n";
    $sql .= "-- 数据库: " . $dbLabel . "\n";
    $sql .= "-- ============================================================\n\n";

    if ($isSqlite) {
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
                    $sql .= $createResult['sql'] . ";\n\n";
                }
            } else {
                $createResult = db()->fetchOne("SHOW CREATE TABLE `{$table}`");
                if ($createResult) {
                    $createSql = $createResult['Create Table'] ?? '';
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

    if ($isSqlite) {
        $sql .= "PRAGMA foreign_keys = ON;\n";
    } else {
        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    }

    // 输出下载
    $filename = 'backup_' . $dbLabel . '_' . date('Ymd_His') . '.sql';

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
                    <p class="text-sm text-gray-500 mt-1">选择要导出的表，下载为SQL文件。</p>
                </div>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-5 py-2 rounded transition inline-flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    导出备份
                </button>
            </div>

            <div class="p-6">
                <!-- 导出选项 -->
                <div class="flex items-center gap-6 mb-6 pb-4 border-b">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="structure" value="1" checked class="w-4 h-4 rounded">
                        <span>导出表结构</span>
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="data" value="1" checked class="w-4 h-4 rounded">
                        <span>导出表数据</span>
                    </label>
                    <div class="flex-1"></div>
                    <button type="button" onclick="toggleAll(true)" class="text-xs text-primary hover:underline">全选</button>
                    <button type="button" onclick="toggleAll(false)" class="text-xs text-gray-500 hover:underline">全不选</button>
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
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
