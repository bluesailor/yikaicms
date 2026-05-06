<?php
/**
 * Yikai CMS - 数据库管理
 * 功能：备份导出 / 导入恢复 / 日志清理 / 表信息
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

$tab = get('tab', 'backup');

// ============ 获取表信息 ============
$allTables = [];
if (db()->isSqlite()) {
    $rows = db()->fetchAll("SELECT name FROM sqlite_master WHERE type='table' AND name LIKE ? ORDER BY name", [DB_PREFIX . '%']);
} else {
    $rows = db()->fetchAll("SHOW TABLE STATUS LIKE '" . DB_PREFIX . "%'");
}
foreach ($rows as $row) {
    if (db()->isSqlite()) {
        $tableName = $row['name'];
        $cnt = (int)db()->fetchColumn("SELECT COUNT(*) FROM `{$tableName}`");
        $allTables[] = ['name' => $tableName, 'rows' => $cnt, 'size' => 0, 'engine' => 'SQLite'];
    } else {
        $allTables[] = [
            'name'   => $row['Name'],
            'rows'   => (int)($row['Rows'] ?? 0),
            'size'   => ($row['Data_length'] ?? 0) + ($row['Index_length'] ?? 0),
            'engine' => $row['Engine'] ?? '',
        ];
    }
}
$totalRows = array_sum(array_column($allTables, 'rows'));
$totalSize = array_sum(array_column($allTables, 'size'));

// 备份目录
$backupDir = ROOT_PATH . '/storage/backups';
if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);

// 生成备份SQL内容
function generateBackupSql(array $tables, bool $structure = true, bool $data = true): string {
    $isSqlite = db()->isSqlite();
    $sql = "-- Yikai CMS 数据库备份\n-- 时间: " . date('Y-m-d H:i:s') . "\n";
    if ($isSqlite) {
        $sql .= "-- 数据库: SQLite\n\n";
    } else {
        $sql .= "-- 数据库: " . DB_NAME . "\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
    }
    foreach ($tables as $table) {
        if ($structure) {
            if ($isSqlite) {
                $row = db()->fetchOne("SELECT sql FROM sqlite_master WHERE type='table' AND name=?", [$table]);
                $sql .= "DROP TABLE IF EXISTS {$table};\n" . ($row['sql'] ?? '') . ";\n\n";
            } else {
                $create = db()->fetchOne("SHOW CREATE TABLE `{$table}`");
                $sql .= "DROP TABLE IF EXISTS `{$table}`;\n" . ($create['Create Table'] ?? '') . ";\n\n";
            }
        }
        if ($data) {
            $dataRows = db()->fetchAll("SELECT * FROM `{$table}`");
            if (!empty($dataRows)) {
                $q = $isSqlite ? '"' : '`';
                $cols = $q . implode("{$q}, {$q}", array_keys($dataRows[0])) . $q;
                foreach (array_chunk($dataRows, 100) as $chunk) {
                    $sql .= "INSERT INTO {$q}{$table}{$q} ({$cols}) VALUES\n";
                    $vals = [];
                    foreach ($chunk as $dr) {
                        $escaped = array_map(fn($v) => $v === null ? 'NULL' : "'" . addslashes((string)$v) . "'", array_values($dr));
                        $vals[] = '(' . implode(', ', $escaped) . ')';
                    }
                    $sql .= implode(",\n", $vals) . ";\n\n";
                }
            }
        }
    }
    if (!$isSqlite) {
        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    }
    return $sql;
}

// ============ POST 处理 ============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    // --- 备份到服务器 ---
    if ($action === 'backup' || $action === 'export') {
        set_time_limit(300);
        $selectedTables = $_POST['tables'] ?? array_column($allTables, 'name');
        $includeStructure = !empty($_POST['structure'] ?? 1);
        $includeData = !empty($_POST['data'] ?? 1);

        $validNames = array_column($allTables, 'name');
        $selectedTables = array_filter($selectedTables, fn($t) => in_array($t, $validNames));
        if (empty($selectedTables)) { error('没有有效的表'); }

        $sql = generateBackupSql($selectedTables, $includeStructure, $includeData);
        $filename = 'backup_' . date('Ymd_His') . '.sql';
        file_put_contents($backupDir . '/' . $filename, $sql);

        adminLog('database', 'backup', '备份: ' . count($selectedTables) . '个表, ' . round(strlen($sql)/1024) . 'KB');

        if ($action === 'export') {
            // 自定义导出：直接下载
            header('Content-Type: application/sql; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo $sql;
            exit;
        }
        // 快速备份：返回页面
        header('Location: /admin/database.php?tab=backup&saved=' . urlencode($filename));
        exit;
    }

    // --- 下载备份 ---
    if ($action === 'download') {
        $file = basename(post('file'));
        $path = $backupDir . '/' . $file;
        if (!$file || !file_exists($path) || pathinfo($file, PATHINFO_EXTENSION) !== 'sql') {
            error('文件不存在');
        }
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    // --- 恢复备份 ---
    if ($action === 'restore') {
        $file = basename(post('file'));
        $path = $backupDir . '/' . $file;
        if (!$file || !file_exists($path) || pathinfo($file, PATHINFO_EXTENSION) !== 'sql') {
            error('备份文件不存在');
        }
        set_time_limit(600);
        ini_set('memory_limit', '256M');
        $sqlContent = file_get_contents($path);
        if (!$sqlContent) { error('文件读取失败'); }

        db()->execute('SET NAMES utf8mb4');
        db()->execute('SET autocommit = 0');
        db()->execute('SET unique_checks = 0');
        db()->execute('SET foreign_key_checks = 0');

        $stmts = 0; $errors = 0; $buffer = '';
        foreach (explode("\n", $sqlContent) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '--') === 0 || strpos($trimmed, '/*') === 0) continue;
            $buffer .= $line . "\n";
            if (substr($trimmed, -1) === ';') {
                try { db()->execute($buffer); $stmts++; if ($stmts % 200 === 0) { db()->execute('COMMIT'); db()->execute('SET autocommit = 0'); } } catch (\Throwable $e) { $errors++; }
                $buffer = '';
            }
        }
        db()->execute('COMMIT');
        db()->execute('SET unique_checks = 1');
        db()->execute('SET foreign_key_checks = 1');
        db()->execute('SET autocommit = 1');

        adminLog('database', 'restore', "恢复备份: {$file}, {$stmts}条成功, {$errors}条失败");
        header('Location: /admin/database.php?tab=backup&restored=' . urlencode("恢复完成：{$stmts} 条语句" . ($errors ? "，{$errors} 条出错" : '')));
        exit;
    }

    // --- 删除备份 ---
    if ($action === 'delete_backup') {
        $file = basename(post('file'));
        $path = $backupDir . '/' . $file;
        if ($file && file_exists($path) && pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
            @unlink($path);
            adminLog('database', 'delete_backup', '删除备份: ' . $file);
        }
        success(['msg' => '已删除']);
    }

    // --- 导入恢复 ---
    if ($action === 'import') {
        if (empty($_FILES['sqlfile']['tmp_name']) || $_FILES['sqlfile']['error'] !== UPLOAD_ERR_OK) {
            error('请选择 SQL 文件');
        }
        $ext = strtolower(pathinfo($_FILES['sqlfile']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['sql', 'gz'])) { error('支持 .sql 和 .sql.gz 文件'); }

        set_time_limit(600);
        ini_set('memory_limit', '256M');

        // 支持 .gz 压缩文件
        if ($ext === 'gz') {
            $sqlContent = gzdecode(file_get_contents($_FILES['sqlfile']['tmp_name']));
        } else {
            $sqlContent = file_get_contents($_FILES['sqlfile']['tmp_name']);
        }
        if (!$sqlContent) { error('文件读取失败'); }

        $fileSize = strlen($sqlContent);

        // 借鉴 BigDump：预优化 SQL 加速导入
        db()->execute('SET NAMES utf8mb4');
        db()->execute('SET autocommit = 0');
        db()->execute('SET unique_checks = 0');
        db()->execute('SET foreign_key_checks = 0');

        $stmts = 0;
        $errors = 0;
        $errorMsgs = [];
        $buffer = '';
        $lines = explode("\n", $sqlContent);
        $totalLines = count($lines);
        unset($sqlContent); // 释放内存

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '--') === 0 || strpos($trimmed, '/*') === 0) continue;
            $buffer .= $line . "\n";
            if (substr($trimmed, -1) === ';') {
                try {
                    db()->execute($buffer);
                    $stmts++;
                    // 每 200 条提交一次（借鉴 BigDump batch commit）
                    if ($stmts % 200 === 0) {
                        db()->execute('COMMIT');
                        db()->execute('SET autocommit = 0');
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    if ($errors <= 10) $errorMsgs[] = mb_substr($e->getMessage(), 0, 200);
                }
                $buffer = '';
            }
        }

        // 最终提交
        db()->execute('COMMIT');
        db()->execute('SET unique_checks = 1');
        db()->execute('SET foreign_key_checks = 1');
        db()->execute('SET autocommit = 1');

        adminLog('database', 'import', "导入SQL: {$stmts}条成功, {$errors}条失败, 文件大小:" . round($fileSize/1024) . "KB");
        $msg = "导入完成：执行 {$stmts} 条语句，文件 " . round($fileSize/1024) . " KB";
        if ($errors) $msg .= "，{$errors} 条出错";
        success(['msg' => $msg, 'errors' => $errorMsgs]);
    }

    // --- 清空日志 ---
    if ($action === 'clear_logs') {
        $logType = post('log_type');
        $cleared = 0;
        $logTables = [
            'admin_logs' => DB_PREFIX . 'admin_logs',
            'ai_logs'    => DB_PREFIX . 'ai_logs',
            'forms'      => DB_PREFIX . 'forms',
        ];
        if ($logType === 'all') {
            foreach ($logTables as $t) {
                $cleared += (int)db()->fetchColumn("SELECT COUNT(*) FROM `{$t}`");
                db()->execute("TRUNCATE TABLE `{$t}`");
            }
        } elseif (isset($logTables[$logType])) {
            $t = $logTables[$logType];
            $cleared = (int)db()->fetchColumn("SELECT COUNT(*) FROM `{$t}`");
            db()->execute("TRUNCATE TABLE `{$t}`");
        } else {
            error('无效的日志类型');
        }
        adminLog('database', 'clear_logs', "清空日志: {$logType}, {$cleared}条");
        success(['msg' => "已清空 {$cleared} 条记录"]);
    }

    // --- 优化表 ---
    if ($action === 'optimize') {
        $tables = array_column($allTables, 'name');
        foreach ($tables as $t) {
            db()->execute("OPTIMIZE TABLE `{$t}`");
        }
        adminLog('database', 'optimize', '优化所有表');
        success(['msg' => '已优化 ' . count($tables) . ' 张表']);
    }

}

// 日志统计
$logCounts = [
    'admin_logs' => (int)db()->fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "admin_logs"),
    'ai_logs'    => (int)db()->fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "ai_logs"),
    'forms'      => (int)db()->fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "forms"),
];

// 备份文件列表
$backupFiles = [];
$files = glob($backupDir . '/*.sql') ?: [];
rsort($files);
foreach ($files as $f) {
    $backupFiles[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
}

$savedFile = get('saved', '');
$restoredMsg = get('restored', '');

$pageTitle = '数据库管理';
$currentMenu = 'database';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="?tab=backup" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'backup' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>"><?php echo __('db_backup'); ?></a>
        <a href="?tab=export" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'export' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>"><?php echo __('db_export_tables'); ?></a>
        <a href="?tab=import" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'import' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>"><?php echo __('db_import'); ?></a>
        <a href="?tab=logs" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'logs' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>"><?php echo __('db_log_cleanup'); ?></a>
        <a href="?tab=tables" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'tables' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>"><?php echo __('db_tables'); ?></a>
    </div>
</div>

<!-- 顶部：快速备份 + 数据库概览 -->
<div class="bg-white rounded-lg shadow mb-6 p-5 flex flex-wrap items-center justify-between gap-4">
    <div class="flex items-center gap-6">
        <form method="post" class="inline">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="backup">
            <input type="hidden" name="structure" value="1">
            <input type="hidden" name="data" value="1">
            <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2.5 rounded-lg transition inline-flex items-center gap-2 text-sm font-medium" onclick="this.innerHTML='<svg class=\'w-4 h-4 animate-spin\' viewBox=\'0 0 24 24\' fill=\'none\'><circle cx=\'12\' cy=\'12\' r=\'10\' stroke=\'currentColor\' stroke-width=\'4\' class=\'opacity-25\'></circle><path fill=\'currentColor\' d=\'M4 12a8 8 0 018-8v4l3-3-3-3v4a8 8 0 100 16v-4l-3 3 3 3v-4a8 8 0 010-16z\' class=\'opacity-75\'></path></svg> 备份中...'">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/></svg>
                一键备份
            </button>
        </form>
        <span class="text-sm text-gray-500"><?php echo count($allTables); ?> 张表 · <?php echo number_format($totalRows); ?> 条 · <?php echo round($totalSize / 1024 / 1024, 2); ?> MB · <?php echo e(DB_NAME); ?></span>
    </div>
    <div class="text-xs text-gray-400">
        已有 <?php echo count($backupFiles); ?> 个备份
    </div>
</div>

<?php if ($restoredMsg): ?>
<div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6 flex items-center gap-2 text-sm text-blue-700">
    <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
    <?php echo e($restoredMsg); ?>
</div>
<?php endif; ?>

<?php if ($savedFile): ?>
<div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6 flex items-center justify-between">
    <div class="flex items-center gap-2 text-sm text-green-700">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        备份成功：<?php echo e($savedFile); ?>
    </div>
    <form method="post" class="inline">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="download">
        <input type="hidden" name="file" value="<?php echo e($savedFile); ?>">
        <button type="submit" class="text-sm text-primary hover:underline"><?php echo __('db_download_now'); ?></button>
    </form>
</div>
<?php endif; ?>

<?php if ($tab === 'backup'): ?>

<!-- 备份记录 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b">
        <h2 class="font-bold text-gray-800"><?php echo __('db_backup_history'); ?></h2>
    </div>
<?php if (!empty($backupFiles)): ?>
    <div class="divide-y">
        <?php foreach ($backupFiles as $bf): ?>
        <div class="px-6 py-3 flex items-center justify-between hover:bg-gray-50">
            <div class="flex items-center gap-3">
                <svg class="w-5 h-5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                <div>
                    <span class="text-sm font-mono"><?php echo e($bf['name']); ?></span>
                    <span class="text-xs text-gray-400 ml-3"><?php echo round($bf['size']/1024); ?> KB</span>
                    <span class="text-xs text-gray-400 ml-2"><?php echo date('Y-m-d H:i:s', $bf['time']); ?></span>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <form method="post" class="inline">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="download">
                    <input type="hidden" name="file" value="<?php echo e($bf['name']); ?>">
                    <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-primary hover:bg-secondary text-white text-xs rounded transition">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        下载
                    </button>
                </form>
                <form method="post" class="inline" onsubmit="return confirm('确定从此备份恢复？当前数据将被覆盖！')">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="restore">
                    <input type="hidden" name="file" value="<?php echo e($bf['name']); ?>">
                    <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-yellow-500 text-yellow-600 hover:bg-yellow-50 text-xs rounded transition">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        恢复
                    </button>
                </form>
                <button onclick="deleteBackup('<?php echo e($bf['name']); ?>')" class="p-1.5 text-gray-400 hover:text-red-500 transition" title="删除">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="px-6 py-8 text-center text-gray-400 text-sm">暂无备份记录，点击上方「一键备份」开始</div>
<?php endif; ?>
</div>

<script>
async function deleteBackup(file) {
    if (!confirm('确定删除备份 ' + file + '？')) return;
    var fd = new FormData();
    fd.append('action', 'delete_backup');
    fd.append('file', file);
    fd.append('<?php echo CSRF_TOKEN_NAME; ?>', '<?php echo csrfToken(); ?>');
    var resp = await fetch('', { method: 'POST', body: fd });
    var data = await safeJson(resp);
    if (data.code === 0) { showMessage('已删除'); setTimeout(() => location.reload(), 500); }
    else { showMessage(data.msg, 'error'); }
}
</script>

<?php elseif ($tab === 'export'): ?>
<!-- 按表导出 -->
<form method="post" class="max-w-3xl">
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="export">
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h2 class="font-bold text-gray-800"><?php echo __('db_custom_export'); ?></h2>
            <div class="flex items-center gap-4">
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="structure" value="1" checked> 表结构</label>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="data" value="1" checked> 表数据</label>
                <button type="button" onclick="document.querySelectorAll('.tbl-check').forEach(c=>c.checked=true)" class="text-xs text-primary hover:underline cursor-pointer"><?php echo __('btn_select_all'); ?></button>
                <button type="button" onclick="document.querySelectorAll('.tbl-check').forEach(c=>c.checked=false)" class="text-xs text-gray-500 hover:underline cursor-pointer">全不选</button>
            </div>
        </div>
        <div class="p-6 space-y-1 max-h-80 overflow-y-auto">
            <?php foreach ($allTables as $t): ?>
            <label class="flex items-center gap-3 p-2 rounded hover:bg-gray-50 cursor-pointer">
                <input type="checkbox" name="tables[]" value="<?php echo e($t['name']); ?>" checked class="tbl-check">
                <span class="font-mono text-sm flex-1"><?php echo e($t['name']); ?></span>
                <span class="text-xs text-gray-400"><?php echo number_format($t['rows']); ?> 行</span>
                <?php if ($t['size']): ?><span class="text-xs text-gray-400"><?php echo round($t['size']/1024); ?> KB</span><?php endif; ?>
            </label>
            <?php endforeach; ?>
        </div>
        <div class="px-6 py-4 border-t flex items-center gap-3">
            <button type="submit" class="bg-gray-800 hover:bg-gray-700 text-white px-5 py-2 rounded transition inline-flex items-center gap-2 text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                直接下载
            </button>
            <span class="text-xs text-gray-400">选择表后直接下载 SQL 文件（不保存到服务器）</span>
        </div>
    </div>
</form>

<?php elseif ($tab === 'import'): ?>
<!-- 导入恢复 -->
<div class="max-w-2xl">
    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="font-bold text-gray-800 mb-4">导入 SQL 文件</h2>
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 text-sm text-yellow-800 mb-4">
            <strong>注意：</strong>导入操作可能覆盖现有数据。请确保已备份当前数据库。
        </div>
        <form id="importForm" enctype="multipart/form-data">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="import">
            <label class="border-2 border-dashed border-gray-300 rounded-lg p-8 text-center mb-4 block cursor-pointer hover:border-primary hover:bg-blue-50/30 transition">
                <svg class="w-12 h-12 mx-auto text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                <p class="text-sm text-gray-600 mb-2">点击选择 .sql 或 .sql.gz 文件</p>
                <input type="file" name="sqlfile" accept=".sql,.gz" required class="text-sm" onchange="showFileInfo(this)">
                <div id="fileInfo" class="mt-2 text-xs text-gray-500 hidden"></div>
            </label>
            <button type="submit" id="importBtn" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition">
                开始导入
            </button>
            <div id="importResult" class="mt-4 hidden"></div>
        </form>
    </div>
</div>
<script>
function showFileInfo(input) {
    var info = document.getElementById('fileInfo');
    if (input.files.length) {
        var f = input.files[0];
        var size = f.size > 1048576 ? (f.size/1048576).toFixed(1) + ' MB' : (f.size/1024).toFixed(0) + ' KB';
        var isGz = f.name.endsWith('.gz');
        info.textContent = f.name + ' (' + size + ')' + (isGz ? ' — 压缩文件，导入时自动解压' : '');
        info.classList.remove('hidden');
    } else {
        info.classList.add('hidden');
    }
}
document.getElementById('importForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    var btn = document.getElementById('importBtn');
    var result = document.getElementById('importResult');
    btn.disabled = true; btn.textContent = '导入中...';
    result.className = 'mt-4 hidden';
    try {
        var fd = new FormData(this);
        var resp = await fetch('', { method: 'POST', body: fd });
        var data = await safeJson(resp);
        result.className = 'mt-4 p-3 rounded text-sm ' + (data.code === 0 ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700');
        var msg = data.data?.msg || data.msg || '操作完成';
        if (data.data?.errors?.length) msg += '<br>' + data.data.errors.map(e => '<span class="text-xs">' + e + '</span>').join('<br>');
        result.innerHTML = msg;
    } catch(e) { result.className = 'mt-4 p-3 rounded text-sm bg-red-50 text-red-700'; result.textContent = '请求失败'; }
    btn.disabled = false; btn.textContent = '开始导入';
});
</script>

<?php elseif ($tab === 'logs'): ?>
<!-- 日志清理 -->
<div class="max-w-2xl space-y-4">
    <?php
    $logItems = [
        ['key' => 'admin_logs', 'name' => '操作日志', 'desc' => '管理员后台操作记录', 'icon' => '📋'],
        ['key' => 'ai_logs',    'name' => 'AI调用日志', 'desc' => 'AI API 调用记录', 'icon' => '🤖'],
        ['key' => 'forms',      'name' => '表单数据', 'desc' => '前台表单提交记录（联系/询盘）', 'icon' => '📝'],
    ];
    foreach ($logItems as $li):
        $cnt = $logCounts[$li['key']];
    ?>
    <div class="bg-white rounded-lg shadow p-5 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <span class="text-2xl"><?php echo $li['icon']; ?></span>
            <div>
                <h3 class="font-medium text-gray-800"><?php echo $li['name']; ?></h3>
                <p class="text-xs text-gray-500"><?php echo $li['desc']; ?></p>
            </div>
        </div>
        <div class="flex items-center gap-4">
            <span class="text-sm font-mono <?php echo $cnt > 0 ? 'text-primary' : 'text-gray-400'; ?>"><?php echo number_format($cnt); ?> 条</span>
            <?php if ($cnt > 0): ?>
            <button onclick="clearLog('<?php echo $li['key']; ?>', '<?php echo $li['name']; ?>')" class="text-xs text-red-600 hover:underline cursor-pointer">清空</button>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="bg-white rounded-lg shadow p-5 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <span class="text-2xl">🗑️</span>
            <div>
                <h3 class="font-medium text-gray-800">清空全部日志</h3>
                <p class="text-xs text-gray-500">一键清空以上所有日志数据</p>
            </div>
        </div>
        <button onclick="clearLog('all', '全部日志')" class="text-xs bg-red-500 hover:bg-red-600 text-white px-4 py-1.5 rounded cursor-pointer">全部清空</button>
    </div>

    <div class="bg-white rounded-lg shadow p-5 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <span class="text-2xl">⚡</span>
            <div>
                <h3 class="font-medium text-gray-800">优化数据表</h3>
                <p class="text-xs text-gray-500">清理碎片，释放空间，提升查询性能</p>
            </div>
        </div>
        <button onclick="optimizeTables()" class="text-xs bg-primary hover:bg-secondary text-white px-4 py-1.5 rounded cursor-pointer">执行优化</button>
    </div>
</div>
<script>
async function clearLog(type, name) {
    if (!confirm('确定清空「' + name + '」？此操作不可恢复！')) return;
    var fd = new FormData();
    fd.append('action', 'clear_logs');
    fd.append('log_type', type);
    fd.append('<?php echo CSRF_TOKEN_NAME; ?>', '<?php echo csrfToken(); ?>');
    var resp = await fetch('', { method: 'POST', body: fd });
    var data = await safeJson(resp);
    showMessage(data.data?.msg || data.msg || '完成');
    if (data.code === 0) setTimeout(() => location.reload(), 800);
}
async function optimizeTables() {
    var fd = new FormData();
    fd.append('action', 'optimize');
    fd.append('<?php echo CSRF_TOKEN_NAME; ?>', '<?php echo csrfToken(); ?>');
    var resp = await fetch('', { method: 'POST', body: fd });
    var data = await safeJson(resp);
    showMessage(data.data?.msg || data.msg || '完成');
}
</script>

<?php elseif ($tab === 'tables'): ?>
<!-- 数据表列表 -->
<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-500">表名</th>
                    <th class="px-4 py-3 text-center font-medium text-gray-500">引擎</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-500">记录数</th>
                    <th class="px-4 py-3 text-right font-medium text-gray-500">大小</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($allTables as $t): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-mono"><?php echo e($t['name']); ?></td>
                    <td class="px-4 py-3 text-center text-gray-500"><?php echo e($t['engine']); ?></td>
                    <td class="px-4 py-3 text-right"><?php echo number_format($t['rows']); ?></td>
                    <td class="px-4 py-3 text-right text-gray-500"><?php echo $t['size'] ? round($t['size']/1024) . ' KB' : '-'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
