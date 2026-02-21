<?php
/**
 * Yikai CMS - 系统信息 & 操作日志
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

    $diskFree = @disk_free_space(ROOT_PATH);
    $diskTotal = @disk_total_space(ROOT_PATH);

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
        function formatBytes(int|float $bytes): string {
            if ($bytes <= 0) return '0 B';
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $i = floor(log($bytes, 1024));
            return round($bytes / pow(1024, $i), 2) . ' ' . $units[(int)$i];
        }
    }

    $uploadsSize = dirSize(ROOT_PATH . '/uploads');

    $tableStats = [];
    $tables = ['channels', 'contents', 'articles', 'products', 'cases', 'albums', 'forms', 'admin_users', 'settings', 'admin_logs'];
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
$currentMenu = $tab === 'log' ? 'system_log' : 'system';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/system.php" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'info' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">系统信息</a>
        <a href="/admin/system.php?tab=log" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'log' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">操作日志</a>
    </div>
</div>

<?php if ($tab === 'info'): ?>
<div class="space-y-6">
    <!-- CMS 信息 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800">CMS 信息</h2>
        </div>
        <div class="p-6">
            <table class="w-full text-sm">
                <tbody class="divide-y">
                    <tr>
                        <td class="py-3 text-gray-500 w-48">系统名称</td>
                        <td class="py-3 text-gray-800">Yikai CMS</td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500">系统版本</td>
                        <td class="py-3 text-gray-800">
                            <span class="inline-flex items-center gap-2">
                                v<?php echo defined('CMS_VERSION') ? CMS_VERSION : '1.0.0'; ?>
                                <span class="px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-700">正式版</span>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500">站点域名</td>
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
                        <td class="py-3 text-gray-500">站点URL</td>
                        <td class="py-3 text-gray-800 font-mono text-xs"><?php echo e($siteUrl ?: '未设置'); ?></td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500">当前访问</td>
                        <td class="py-3 text-gray-800 font-mono text-xs"><?php echo e(($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '')); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 服务器环境 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800">服务器环境</h2>
        </div>
        <div class="p-6">
            <table class="w-full text-sm">
                <tbody class="divide-y">
                    <tr>
                        <td class="py-3 text-gray-500 w-48">操作系统</td>
                        <td class="py-3 text-gray-800"><?php echo php_uname('s') . ' ' . php_uname('r'); ?></td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500">Web 服务器</td>
                        <td class="py-3 text-gray-800"><?php echo $_SERVER['SERVER_SOFTWARE'] ?? '-'; ?></td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500">PHP 版本</td>
                        <td class="py-3 text-gray-800"><?php echo PHP_VERSION; ?> (<?php echo PHP_SAPI; ?>)</td>
                    </tr>
                    <?php if ($mysqlVersion): ?>
                    <tr>
                        <td class="py-3 text-gray-500">MySQL 版本</td>
                        <td class="py-3 text-gray-800"><?php echo e($mysqlVersion); ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="py-3 text-gray-500">数据库类型</td>
                        <td class="py-3 text-gray-800"><?php echo DB_DRIVER === 'mysql' ? 'MySQL' : 'SQLite'; ?> (<?php echo DB_NAME; ?>)</td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500">服务器时间</td>
                        <td class="py-3 text-gray-800"><?php echo date('Y-m-d H:i:s'); ?> (<?php echo date_default_timezone_get(); ?>)</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- PHP 配置 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800">PHP 配置</h2>
        </div>
        <div class="p-6">
            <table class="w-full text-sm">
                <tbody class="divide-y">
                    <tr>
                        <td class="py-3 text-gray-500 w-48">上传限制</td>
                        <td class="py-3 text-gray-800"><?php echo ini_get('upload_max_filesize'); ?></td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500">POST 限制</td>
                        <td class="py-3 text-gray-800"><?php echo ini_get('post_max_size'); ?></td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500">内存限制</td>
                        <td class="py-3 text-gray-800"><?php echo ini_get('memory_limit'); ?></td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500">最大执行时间</td>
                        <td class="py-3 text-gray-800"><?php echo ini_get('max_execution_time'); ?> 秒</td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500">GD 库</td>
                        <td class="py-3 text-gray-800">
                            <?php if (extension_loaded('gd')): ?>
                            <span class="text-green-600">已安装</span>
                            <?php else: ?>
                            <span class="text-red-600">未安装</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500">cURL</td>
                        <td class="py-3 text-gray-800">
                            <?php if (extension_loaded('curl')): ?>
                            <span class="text-green-600">已安装</span>
                            <?php else: ?>
                            <span class="text-red-600">未安装</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500">mbstring</td>
                        <td class="py-3 text-gray-800">
                            <?php if (extension_loaded('mbstring')): ?>
                            <span class="text-green-600">已安装</span>
                            <?php else: ?>
                            <span class="text-red-600">未安装</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500">PDO</td>
                        <td class="py-3 text-gray-800">
                            <?php if (extension_loaded('pdo')): ?>
                            <span class="text-green-600">已安装</span> (<?php echo implode(', ', PDO::getAvailableDrivers()); ?>)
                            <?php else: ?>
                            <span class="text-red-600">未安装</span>
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
            <h2 class="font-bold text-gray-800">存储信息</h2>
        </div>
        <div class="p-6">
            <table class="w-full text-sm">
                <tbody class="divide-y">
                    <?php if ($diskTotal): ?>
                    <tr>
                        <td class="py-3 text-gray-500 w-48">磁盘空间</td>
                        <td class="py-3 text-gray-800"><?php echo formatBytes($diskFree); ?> 可用 / <?php echo formatBytes($diskTotal); ?> 总计</td>
                    </tr>
                    <?php endif; ?>
                    <tr>
                        <td class="py-3 text-gray-500">上传目录</td>
                        <td class="py-3 text-gray-800"><?php echo formatBytes($uploadsSize); ?></td>
                    </tr>
                    <tr>
                        <td class="py-3 text-gray-500">站点根目录</td>
                        <td class="py-3 text-gray-800 font-mono text-xs"><?php echo ROOT_PATH; ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 数据统计 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800">数据统计</h2>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-2 md:grid-cols-5 gap-4 text-sm">
                <?php
                $tableNames = [
                    'channels' => '栏目', 'contents' => '单页内容', 'articles' => '文章',
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
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                筛选
            </button>

            <button type="button" onclick="clearOldLogs()" class="border px-4 py-2 rounded hover:bg-gray-100 ml-auto inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
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
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">模块</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">动作</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">描述</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">IP</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">时间</th>
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
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                上一页</a>
            <?php endif; ?>
            <span class="text-sm">第 <?php echo $logPage; ?>/<?php echo $totalPages; ?> 页</span>
            <?php if ($logPage < $totalPages): ?>
            <a href="<?php echo $baseUrl; ?>page=<?php echo $logPage + 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-100 inline-flex items-center gap-1">
                下一页
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
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

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
