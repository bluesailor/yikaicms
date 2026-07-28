<?php
/**
 * YikaiCMS - 系统信息 & 操作日志
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

$tab = $_GET['tab'] ?? 'info';

// ── 操作日志 Tab ──
if ($tab === 'log') {
    // 处理 AJAX
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action');
        if ($action === 'clear_old') {
            $before = time() - 30 * 86400;
            adminLogModel()->clearBefore($before);
            adminLog('log', 'clear', '清除30天前的日志');
            success();
        }
        exit;
    }

    // 查询参数
    $logModule = get('module', '');
    $logAdminName = get('admin_name', '');
    $logDateFrom = get('date_from', '');
    $logDateTo = get('date_to', '');
    $logPage = max(1, getInt('page', 1));
    $logPerPage = 30;

    $logOffset = ($logPage - 1) * $logPerPage;
    $filters = array_filter([
        'module' => $logModule,
        'admin_name' => $logAdminName,
        'date_from' => $logDateFrom,
        'date_to' => $logDateTo,
    ]);
    $logResult = adminLogModel()->search($filters, $logPerPage, $logOffset);
    $logTotal = $logResult['total'];
    $logs = $logResult['items'];
    $logModules = adminLogModel()->getModules();
    $moduleNames = [
        'login' => '登录', 'content' => '内容', 'channel' => '栏目',
        'banner' => '轮播图', 'link' => '友链', 'media' => '媒体',
        'form' => '表单', 'setting' => '设置', 'user' => '用户',
        'profile' => '个人', 'log' => '日志', 'upgrade' => '升级',
    ];
}

// ── 错误日志 Tab ──
if ($tab === 'errorlog') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = post('action');
        if ($action === 'clear_errorlog') {
            $f = (string) post('file');
            if (preg_match('/^error-\d{6}\.log$/', $f)) {
                @unlink(ROOT_PATH . '/storage/logs/' . $f);
                adminLog('log', 'clear_errorlog', '清空错误日志：' . $f);
            }
            success();
        }
        exit;
    }

    $errFiles = ErrorHandler::listFiles();
    $errFile = (string) get('file', $errFiles[0] ?? '');
    if (!in_array($errFile, $errFiles, true)) {
        $errFile = $errFiles[0] ?? '';
    }
    $errEntries = [];
    $errSize = 0;
    if ($errFile !== '') {
        $p = ROOT_PATH . '/storage/logs/' . $errFile;
        $errSize = (int) @filesize($p);
        $fp = @fopen($p, 'rb');
        if ($fp) {
            // 只读末尾 512KB，超大日志不至于拖垮页面
            if ($errSize > 524288) {
                fseek($fp, -524288, SEEK_END);
            }
            $raw = (string) stream_get_contents($fp);
            fclose($fp);
            // 每条以 [YYYY-mm-dd ...] 开头，续行（堆栈）以缩进开头
            preg_match_all('/^\[\d{4}-\d{2}-\d{2} [^\]]+\].*(?:\n(?!\[).+)*/m', $raw, $m);
            $errEntries = array_slice(array_reverse($m[0]), 0, 200);
        }
    }
}

// ── 系统信息 Tab ──
if ($tab === 'info') {
    $mysqlVersion = '';
    if (DB_DRIVER === 'mysql') {
        try {
            $row = db()->fetchOne('SELECT VERSION() AS ver');
            $mysqlVersion = $row['ver'] ?? '';
        } catch (\Throwable $e) {
            $mysqlVersion = '-';
        }
    }

    $diskFree = function_exists('disk_free_space') ? @disk_free_space(ROOT_PATH) : false;
    $diskTotal = function_exists('disk_total_space') ? @disk_total_space(ROOT_PATH) : false;

    if (!function_exists('dirSize')) {
        function dirSize(string $dir): int {
            $size = 0;
            if (!is_dir($dir)) return 0;
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile()) $size += $file->getSize();
            }
            return $size;
        }
    }

    if (!function_exists('formatBytes')) {
        function formatBytes($bytes): string {
            if ($bytes <= 0) return '0 B';
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $i = floor(log($bytes, 1024));
            return round($bytes / pow(1024, $i), 2) . ' ' . $units[(int)$i];
        }
    }

    $uploadsSize = dirSize(ROOT_PATH . '/uploads');

    $tableStats = [];
    $tables = ['channels', 'contents', 'products', 'cases', 'albums', 'forms', 'admin_users', 'settings', 'admin_logs'];
    foreach ($tables as $t) {
        try {
            $count = (int)db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . $t);
            $tableStats[$t] = $count;
        } catch (\Throwable $e) {
            $tableStats[$t] = '-';
        }
    }

    $siteUrl = config('site_url') ?: (defined('SITE_URL') ? SITE_URL : '');
    $siteDomain = $siteUrl ? parse_url($siteUrl, PHP_URL_HOST) : ($_SERVER['HTTP_HOST'] ?? '-');
}

$pageTitle = '系统管理';
$currentMenu = in_array($tab, ['log', 'errorlog'], true) ? 'system_log' : 'system';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/system.php" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'info' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('sys_info'); ?></a>
        <a href="/admin/system.php?tab=log" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'log' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('sys_stat_log'); ?></a>
        <a href="/admin/system.php?tab=errorlog" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'errorlog' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('sys_error_log'); ?></a>
    </div>
</div>

<?php if ($tab === 'info'): ?>
<div class="space-y-6">
    <!-- CMS 信息 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo __('sys_cms_info'); ?></h2>
        </div>
        <div class="p-6">
            <table class="w-full text-sm">
                <tbody class="divide-y">
                    <?php // 白标：有效授权的站点（建站公司场景）不露 CMS 品牌与版本，与 footer 隐藏 Powered by 同一惯例
                          $sysLicensed = function_exists('license_valid') && license_valid(); ?>
                    <tr>
                        <td class="py-3 text-gray-500 w-48"><?php echo __('sys_name'); ?></td>
                        <td class="py-3 text-gray-800"><?php echo $sysLicensed ? e(config('site_name', __('sys_brand_name'))) : __('sys_brand_name'); ?></td>
                    </tr>
                    <?php if (!$sysLicensed): ?>
                    <tr>
                        <td class="py-3 text-gray-500"><?php echo __('sys_version'); ?></td>
                        <td class="py-3 text-gray-800">
                            <span class="inline-flex items-center gap-2">
                                v<?php echo defined('CMS_VERSION') ? CMS_VERSION : '1.0.0'; ?>
                                <span class="px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-700"><?php echo __('sys_stable'); ?></span>
                            </span>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="py-3 text-gray-500"><?php echo __('sys_domain'); ?></td>
                        <td class="py-3 text-gray-800">
                            <?php if ($siteUrl): ?>
                            <a href="<?php echo e($siteUrl); ?>" target="_blank" class="text-primary hover:underline"><?php echo e($siteDomain); ?></a>
                            <?php else: ?>
                            <span class="text-yellow-600">未设置</span>
                            <a href="/admin/setting.php" class="text-primary text-xs ml-2 hover:underline">去设置</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500"><?php echo __('sys_site_url'); ?></td>
                        <td class="py-3 text-gray-800 font-mono text-xs"><?php echo e($siteUrl ?: '未设置'); ?></td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500"><?php echo __('sys_current_url'); ?></td>
                        <td class="py-3 text-gray-800 font-mono text-xs"><?php echo e(($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '')); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 服务器环境 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo __('sys_server_env'); ?></h2>
        </div>
        <div class="p-6">
            <table class="w-full text-sm">
                <tbody class="divide-y">
                    <tr>
                        <td class="py-3 text-gray-500 w-48"><?php echo __('sys_os'); ?></td>
                        <td class="py-3 text-gray-800"><?php echo php_uname('s') . ' ' . php_uname('r'); ?></td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500"><?php echo __('sys_web_server'); ?></td>
                        <td class="py-3 text-gray-800"><?php echo e($_SERVER['SERVER_SOFTWARE'] ?? '-'); ?></td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500"><?php echo __('sys_php_version'); ?></td>
                        <td class="py-3 text-gray-800"><?php echo PHP_VERSION; ?> (<?php echo PHP_SAPI; ?>)</td>
                    </tr>
                    <?php if ($mysqlVersion): ?>
                    <tr>
                        <td class="py-3 text-gray-500"><?php echo __('sys_mysql_version'); ?></td>
                        <td class="py-3 text-gray-800"><?php echo e($mysqlVersion); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="py-3 text-gray-500"><?php echo __('sys_db_type'); ?></td>
                        <td class="py-3 text-gray-800"><?php echo DB_DRIVER === 'mysql' ? 'MySQL' : 'SQLite'; ?> (<?php echo DB_NAME; ?>)</td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500"><?php echo __('sys_server_time'); ?></td>
                        <td class="py-3 text-gray-800"><?php echo date('Y-m-d H:i:s'); ?> (<?php echo date_default_timezone_get(); ?>)</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- PHP 配置 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo __('sys_php_config'); ?></h2>
        </div>
        <div class="p-6">
            <table class="w-full text-sm">
                <tbody class="divide-y">
                    <tr>
                        <td class="py-3 text-gray-500 w-48"><?php echo __('sys_upload_limit'); ?></td>
                        <td class="py-3 text-gray-800"><?php echo ini_get('upload_max_filesize'); ?></td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500"><?php echo __('sys_post_limit'); ?></td>
                        <td class="py-3 text-gray-800"><?php echo ini_get('post_max_size'); ?></td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500"><?php echo __('sys_memory_limit'); ?></td>
                        <td class="py-3 text-gray-800"><?php echo ini_get('memory_limit'); ?></td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500"><?php echo __('sys_max_exec'); ?></td>
                        <td class="py-3 text-gray-800"><?php echo ini_get('max_execution_time'); ?> 秒</td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500"><?php echo __('sys_gd'); ?></td>
                        <td class="py-3 text-gray-800">
                            <?php if (extension_loaded('gd')): ?>
                            <span class="text-green-600"><?php echo __('sys_installed'); ?></span>
                            <?php else: ?>
                            <span class="text-red-600"><?php echo __('sys_not_installed'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500">cURL</td>
                        <td class="py-3 text-gray-800">
                            <?php if (extension_loaded('curl')): ?>
                            <span class="text-green-600"><?php echo __('sys_installed'); ?></span>
                            <?php else: ?>
                            <span class="text-red-600"><?php echo __('sys_not_installed'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500">mbstring</td>
                        <td class="py-3 text-gray-800">
                            <?php if (extension_loaded('mbstring')): ?>
                            <span class="text-green-600"><?php echo __('sys_installed'); ?></span>
                            <?php else: ?>
                            <span class="text-red-600"><?php echo __('sys_not_installed'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500">PDO</td>
                        <td class="py-3 text-gray-800">
                            <?php if (extension_loaded('pdo')): ?>
                            <span class="text-green-600"><?php echo __('sys_installed'); ?></span> (<?php echo implode(', ', PDO::getAvailableDrivers()); ?>)
                            <?php else: ?>
                            <span class="text-red-600"><?php echo __('sys_not_installed'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 存储信息 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo __('sys_storage'); ?></h2>
        </div>
        <div class="p-6">
            <table class="w-full text-sm">
                <tbody class="divide-y">
                    <?php if ($diskTotal): ?>
                    <tr>
                        <td class="py-3 text-gray-500 w-48"><?php echo __('sys_disk_space'); ?></td>
                        <td class="py-3 text-gray-800"><?php echo formatBytes($diskFree); ?> 可用 / <?php echo formatBytes($diskTotal); ?> 总计</td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="py-3 text-gray-500"><?php echo __('sys_upload_dir'); ?></td>
                        <td class="py-3 text-gray-800"><?php echo formatBytes($uploadsSize); ?></td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500"><?php echo __('sys_root_dir'); ?></td>
                        <td class="py-3 text-gray-800 font-mono text-xs"><?php echo ROOT_PATH; ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 数据统计 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo __('sys_data_stats'); ?></h2>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4 text-sm">
                <?php
                $tableNames = [
                    'channels' => '栏目', 'contents' => '单页内容',
                    'products' => '产品', 'cases' => '案例', 'albums' => '相册',
                    'forms' => '表单', 'admin_users' => '管理员', 'settings' => '设置项',
                    'admin_logs' => '操作日志',
                ];
                foreach ($tableStats as $table => $count):
                ?>
                <div class="bg-gray-50 rounded-lg p-3 text-center">
                    <p class="text-lg font-bold text-gray-800"><?php echo $count; ?></p>
                    <p class="text-gray-500 text-xs"><?php echo $tableNames[$table] ?? $table; ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($tab === 'log'): ?>
<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4">
        <form class="flex flex-wrap gap-3 items-center">
            <input type="hidden" name="tab" value="log">
            <select name="module" class="border rounded px-3 py-2">
                <option value="">全部模块</option>
                <?php foreach ($logModules as $m): ?>
                <option value="<?php echo e($m['module']); ?>" <?php echo $logModule === $m['module'] ? 'selected' : ''; ?>>
                    <?php echo $moduleNames[$m['module']] ?? $m['module']; ?>
                </option>
                <?php endforeach; ?>
            </select>

            <input type="text" name="admin_name" value="<?php echo e($logAdminName); ?>"
                   class="border rounded px-3 py-2" placeholder="操作人...">

            <input type="date" name="date_from" value="<?php echo e($logDateFrom); ?>"
                   class="border rounded px-3 py-2">
            <span class="text-gray-400">-</span>
            <input type="date" name="date_to" value="<?php echo e($logDateTo); ?>"
                   class="border rounded px-3 py-2">

            <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <i class="ti ti-search text-base"></i>
                <?php echo __('admin_filter'); ?>
            </button>

            <button type="button" onclick="clearOldLogs()" class="border px-4 py-2 rounded hover:bg-gray-100 ml-auto inline-flex items-center gap-1">
                <i class="ti ti-trash text-base"></i>
                清除30天前日志
            </button>
        </form>
    </div>
</div>

<!-- 列表 -->
<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">操作人</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('sys_module'); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('sys_action_type'); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">描述</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">IP</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_created_at'); ?></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($logs as $logItem): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500"><?php echo $logItem['id']; ?></td>
                    <td class="px-4 py-3 font-medium"><?php echo e($logItem['admin_name']); ?></td>
                    <td class="px-4 py-3">
                        <span class="text-xs bg-blue-100 text-blue-600 px-2 py-1 rounded">
                            <?php echo $moduleNames[$logItem['module']] ?? $logItem['module']; ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-sm"><?php echo e($logItem['action']); ?></td>
                    <td class="px-4 py-3 text-sm text-gray-600 max-w-xs truncate" title="<?php echo e($logItem['description']); ?>">
                        <?php echo e($logItem['description']); ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-500"><?php echo e($logItem['ip']); ?></td>
                    <td class="px-4 py-3 text-center text-sm text-gray-500">
                        <?php echo date('Y-m-d H:i:s', (int)$logItem['created_at']); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($logs)): ?>
                <tr>
                    <td colspan="7" class="px-4 py-8 text-center text-gray-500">暂无日志</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- 分页 -->
    <?php if ($logTotal > $logPerPage): ?>
    <div class="px-6 py-4 border-t flex items-center justify-between">
        <span class="text-sm text-gray-500">共 <?php echo $logTotal; ?> 条</span>
        <div class="flex items-center gap-2">
            <?php
            $totalPages = (int)ceil($logTotal / $logPerPage);
            $queryString = http_build_query(array_filter([
                'tab' => 'log',
                'module' => $logModule,
                'admin_name' => $logAdminName,
                'date_from' => $logDateFrom,
                'date_to' => $logDateTo
            ]));
            $baseUrl = '?' . ($queryString ? $queryString . '&' : '');
            ?>
            <?php if ($logPage > 1): ?>
            <a href="<?php echo $baseUrl; ?>page=<?php echo $logPage - 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-100 inline-flex items-center gap-1">
                <i class="ti ti-chevron-left text-base"></i>
                <?php echo __('list_prev_page'); ?></a>
            <?php endif; ?>
            <span class="text-sm">第 <?php echo $logPage; ?>/<?php echo $totalPages; ?> 页</span>
            <?php if ($logPage < $totalPages): ?>
            <a href="<?php echo $baseUrl; ?>page=<?php echo $logPage + 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-100 inline-flex items-center gap-1">
                <?php echo __('list_next_page'); ?>
                <i class="ti ti-chevron-right text-base"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
async function clearOldLogs() {
    if (!confirm('确定要清除30天前的日志吗？此操作不可恢复。')) return;

    const formData = new FormData();
    formData.append('action', 'clear_old');

    const response = await fetch(location.href, { method: 'POST', body: formData });
    const data = await safeJson(response);

    if (data.code === 0) {
        showMessage('清除成功');
        setTimeout(() => location.reload(), 1000);
    } else {
        showMessage(data.msg, 'error');
    }
}
</script>
<?php endif; ?>

<?php if ($tab === 'errorlog'): ?>
<div class="bg-white rounded-lg shadow">
    <div class="p-4 border-b flex flex-wrap items-center gap-3">
        <?php if (!empty($errFiles)): ?>
        <form class="flex items-center gap-2">
            <input type="hidden" name="tab" value="errorlog">
            <select name="file" class="border rounded px-3 py-2" onchange="this.form.submit()">
                <?php foreach ($errFiles as $f): ?>
                <option value="<?php echo e($f); ?>" <?php echo $f === $errFile ? 'selected' : ''; ?>><?php echo e($f); ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <span class="text-sm text-gray-500"><?php echo count($errEntries); ?> <?php echo __('sys_error_log_entries'); ?> · <?php echo $errSize > 1048576 ? round($errSize / 1048576, 1) . ' MB' : round($errSize / 1024, 1) . ' KB'; ?></span>
        <button onclick="clearErrorLog()" class="ml-auto text-red-600 hover:text-red-700 text-sm inline-flex items-center gap-1">
            <i class="ti ti-trash text-base"></i><?php echo __('sys_error_log_clear'); ?>
        </button>
        <?php endif; ?>
    </div>
    <div class="p-4 text-xs text-gray-400 border-b bg-gray-50"><?php echo __('sys_error_log_tip'); ?></div>

    <?php if (empty($errEntries)): ?>
    <div class="px-4 py-12 text-center text-gray-500"><?php echo __('sys_error_log_empty'); ?></div>
    <?php else: ?>
    <div class="divide-y">
        <?php foreach ($errEntries as $entry):
            $lvl = preg_match('/^\[[^\]]+\] \[(\w+)\]/', $entry, $lm) ? $lm[1] : 'ERROR';
            $badge = match ($lvl) {
                'FATAL', 'ERROR' => 'bg-red-100 text-red-600',
                'WARNING' => 'bg-yellow-100 text-yellow-700',
                default => 'bg-gray-100 text-gray-500',
            }; ?>
        <div class="px-4 py-3">
            <span class="text-xs px-2 py-0.5 rounded <?php echo $badge; ?>"><?php echo e($lvl); ?></span>
            <pre class="mt-2 text-xs font-mono text-gray-700 whitespace-pre-wrap break-all leading-relaxed"><?php echo e($entry); ?></pre>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
async function clearErrorLog() {
    if (!confirm('<?php echo __('sys_error_log_clear_confirm'); ?>')) return;

    const formData = new FormData();
    formData.append('action', 'clear_errorlog');
    formData.append('file', <?php echo json_encode($errFile); ?>);

    const response = await fetch(location.href, { method: 'POST', body: formData });
    const data = await safeJson(response);

    if (data.code === 0) {
        showMessage('<?php echo __('sys_error_log_cleared'); ?>');
        setTimeout(() => location.href = '/admin/system.php?tab=errorlog', 800);
    } else {
        showMessage(data.msg, 'error');
    }
}
</script>
<?php endif; ?>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
