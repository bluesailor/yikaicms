<?php
/**
 * ikaiCMS - AI 用量详情
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

$currentMenu = 'setting_ai';
$pageTitle = 'AI 用量详情';
$logTable = DB_PREFIX . 'ai_logs';

// 筛选参数
$filterProvider = get('provider', '');
$filterDate = get('date', '');
$page = max(1, getInt('page', 1));
$perPage = 30;

$where = "WHERE 1=1";
$params = [];
if ($filterProvider) {
    $where .= " AND provider = ?";
    $params[] = $filterProvider;
}
if ($filterDate) {
    $where .= " AND DATE(created_at) = ?";
    $params[] = $filterDate;
}

// 统计
$stats = db()->fetchOne("SELECT COUNT(*) as total, SUM(total_tokens) as tokens, SUM(prompt_tokens) as prompt_t, SUM(completion_tokens) as comp_t, SUM(success) as ok, SUM(CASE WHEN success=0 THEN 1 ELSE 0 END) as fail FROM {$logTable} {$where}", $params);

// 按供应商分组统计
$providerStats = db()->fetchAll("SELECT provider, COUNT(*) as calls, SUM(total_tokens) as tokens FROM {$logTable} {$where} GROUP BY provider ORDER BY calls DESC", $params);

// 按日期分组统计（最近30天）
$dailyStats = db()->fetchAll("SELECT DATE(created_at) as day, COUNT(*) as calls, SUM(total_tokens) as tokens FROM {$logTable} WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) GROUP BY DATE(created_at) ORDER BY day DESC");

// 分页
$total = (int)($stats['total'] ?? 0);
$totalPages = max(1, (int)ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

$logs = db()->fetchAll("SELECT l.*, u.username as admin_name FROM {$logTable} l LEFT JOIN " . DB_PREFIX . "users u ON l.admin_id = u.id {$where} ORDER BY l.id DESC LIMIT {$perPage} OFFSET {$offset}", $params);

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="mb-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-800">AI 用量详情</h1>
            <a href="/admin/setting_ai.php" class="text-sm text-gray-400 hover:text-primary">&laquo; 返回 AI 设置</a>
        </div>
    </div>
</div>

<!-- 总览卡片 -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
    <div class="bg-white rounded-lg shadow p-4 text-center">
        <p class="text-2xl font-bold text-blue-600"><?php echo number_format($total); ?></p>
        <p class="text-xs text-gray-500 mt-1">总调用次数</p>
    </div>
    <div class="bg-white rounded-lg shadow p-4 text-center">
        <p class="text-2xl font-bold text-purple-600"><?php echo number_format((int)($stats['tokens'] ?? 0)); ?></p>
        <p class="text-xs text-gray-500 mt-1">总 Tokens</p>
    </div>
    <div class="bg-white rounded-lg shadow p-4 text-center">
        <p class="text-2xl font-bold text-cyan-600"><?php echo number_format((int)($stats['prompt_t'] ?? 0)); ?></p>
        <p class="text-xs text-gray-500 mt-1">输入 Tokens</p>
    </div>
    <div class="bg-white rounded-lg shadow p-4 text-center">
        <p class="text-2xl font-bold text-teal-600"><?php echo number_format((int)($stats['comp_t'] ?? 0)); ?></p>
        <p class="text-xs text-gray-500 mt-1">输出 Tokens</p>
    </div>
    <div class="bg-white rounded-lg shadow p-4 text-center">
        <p class="text-2xl font-bold text-green-600"><?php echo (int)($stats['ok'] ?? 0); ?> <span class="text-red-400 text-lg">/ <?php echo (int)($stats['fail'] ?? 0); ?></span></p>
        <p class="text-xs text-gray-500 mt-1">成功 / 失败</p>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <!-- 按供应商统计 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-4 py-3 border-b font-bold text-gray-800 text-sm">供应商分布</div>
        <div class="p-4 space-y-2">
            <?php if (empty($providerStats)): ?>
            <p class="text-gray-400 text-sm text-center py-4">暂无数据</p>
            <?php else: foreach ($providerStats as $ps):
                $pct = $total > 0 ? round((int)$ps['calls'] / $total * 100) : 0;
            ?>
            <div>
                <div class="flex justify-between text-sm mb-1">
                    <a href="?provider=<?php echo e($ps['provider']); ?>" class="text-gray-700 hover:text-primary"><?php echo e($ps['provider']); ?></a>
                    <span class="text-gray-400"><?php echo $ps['calls']; ?> 次 / <?php echo number_format((int)$ps['tokens']); ?> tokens</span>
                </div>
                <div class="bg-gray-100 rounded-full h-2">
                    <div class="bg-blue-500 h-2 rounded-full" style="width: <?php echo $pct; ?>%"></div>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- 每日趋势 -->
    <div class="bg-white rounded-lg shadow lg:col-span-2">
        <div class="px-4 py-3 border-b font-bold text-gray-800 text-sm">每日调用（最近30天）</div>
        <div class="p-4 overflow-x-auto">
            <?php if (empty($dailyStats)): ?>
            <p class="text-gray-400 text-sm text-center py-4">暂无数据</p>
            <?php else: ?>
            <div class="flex items-end gap-1 h-32">
                <?php
                $maxCalls = max(array_column($dailyStats, 'calls'));
                foreach (array_reverse($dailyStats) as $ds):
                    $h = $maxCalls > 0 ? max(4, round((int)$ds['calls'] / $maxCalls * 100)) : 4;
                ?>
                <a href="?date=<?php echo $ds['day']; ?>" class="flex-1 min-w-[8px] max-w-[20px] bg-blue-400 hover:bg-blue-600 rounded-t transition" style="height: <?php echo $h; ?>%" title="<?php echo $ds['day']; ?>: <?php echo $ds['calls']; ?> 次 / <?php echo number_format((int)$ds['tokens']); ?> tokens"></a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 筛选 -->
<div class="bg-white rounded-lg shadow mb-4">
    <div class="px-4 py-3 flex items-center gap-4 flex-wrap">
        <span class="text-sm text-gray-500">筛选：</span>
        <a href="/admin/ai_usage.php" class="text-sm <?php echo (!$filterProvider && !$filterDate) ? 'text-primary font-medium' : 'text-gray-500 hover:text-primary'; ?>">全部</a>
        <?php foreach ($providerStats as $ps): ?>
        <a href="?provider=<?php echo e($ps['provider']); ?>" class="text-sm <?php echo $filterProvider === $ps['provider'] ? 'text-primary font-medium' : 'text-gray-500 hover:text-primary'; ?>"><?php echo e($ps['provider']); ?></a>
        <?php endforeach; ?>
        <?php if ($filterDate): ?>
        <span class="text-sm text-gray-400">|</span>
        <span class="text-sm text-primary"><?php echo e($filterDate); ?></span>
        <a href="?<?php echo $filterProvider ? 'provider=' . e($filterProvider) : ''; ?>" class="text-xs text-red-400 hover:text-red-600">清除日期</a>
        <?php endif; ?>
    </div>
</div>

<!-- 调用记录 -->
<div class="bg-white rounded-lg shadow overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="text-left px-4 py-3 font-medium text-gray-500"><?php echo __('admin_created_at'); ?></th>
                    <th class="text-left px-4 py-3 font-medium text-gray-500">操作员</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-500">供应商</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-500">模型</th>
                    <th class="text-left px-4 py-3 font-medium text-gray-500"><?php echo __('admin_action'); ?></th>
                    <th class="text-right px-4 py-3 font-medium text-gray-500">输入</th>
                    <th class="text-right px-4 py-3 font-medium text-gray-500">输出</th>
                    <th class="text-right px-4 py-3 font-medium text-gray-500">合计</th>
                    <th class="text-center px-4 py-3 font-medium text-gray-500"><?php echo __('admin_status'); ?></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php if (empty($logs)): ?>
                <tr><td colspan="9" class="px-4 py-8 text-center text-gray-400">暂无记录</td></tr>
                <?php else: foreach ($logs as $log): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2.5 text-gray-400 text-xs whitespace-nowrap"><?php echo $log['created_at']; ?></td>
                    <td class="px-4 py-2.5 text-xs"><?php echo e($log['admin_name'] ?? '-'); ?></td>
                    <td class="px-4 py-2.5"><?php echo e($log['provider']); ?></td>
                    <td class="px-4 py-2.5 text-xs text-gray-500 max-w-[120px] truncate"><?php echo e($log['model']); ?></td>
                    <td class="px-4 py-2.5 text-xs">
                        <span class="bg-gray-100 px-2 py-0.5 rounded"><?php echo e($log['action'] ?: '-'); ?></span>
                    </td>
                    <td class="px-4 py-2.5 text-right font-mono text-xs text-gray-500"><?php echo number_format((int)$log['prompt_tokens']); ?></td>
                    <td class="px-4 py-2.5 text-right font-mono text-xs text-gray-500"><?php echo number_format((int)$log['completion_tokens']); ?></td>
                    <td class="px-4 py-2.5 text-right font-mono text-xs font-medium"><?php echo number_format((int)$log['total_tokens']); ?></td>
                    <td class="px-4 py-2.5 text-center">
                        <?php if ($log['success']): ?>
                        <span class="inline-block w-2 h-2 rounded-full bg-green-400"></span>
                        <?php else: ?>
                        <span class="inline-block w-2 h-2 rounded-full bg-red-400 cursor-help" title="<?php echo e($log['error_msg']); ?>"></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="px-4 py-3 border-t flex items-center justify-between">
        <span class="text-sm text-gray-500">共 <?php echo $total; ?> 条，第 <?php echo $page; ?>/<?php echo $totalPages; ?> 页</span>
        <div class="flex gap-1">
            <?php if ($page > 1): ?>
            <a href="?page=<?php echo $page - 1; ?>&provider=<?php echo e($filterProvider); ?>&date=<?php echo e($filterDate); ?>" class="px-3 py-1 border rounded text-sm hover:bg-gray-50"><?php echo __('list_prev_page'); ?></a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
            <a href="?page=<?php echo $page + 1; ?>&provider=<?php echo e($filterProvider); ?>&date=<?php echo e($filterDate); ?>" class="px-3 py-1 border rounded text-sm hover:bg-gray-50"><?php echo __('list_next_page'); ?></a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
