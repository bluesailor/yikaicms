<?php
/**
 * Yikai CMS - 后台控制台
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();

// 统计数据
$stats = [
    'contents' => contentModel()->count(),
    'channels' => channelModel()->count(),
    'forms' => formModel()->count(['status' => 0]),
    'media' => mediaModel()->count(),
];

// 最新内容
$latestContents = contentModel()->where([], 'id DESC', 10);

// 最新表单
$latestForms = formModel()->where([], 'id DESC', 10);

$pageTitle = '控制台';
$currentMenu = 'dashboard';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- 统计卡片 -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
            </div>
            <div class="ml-4">
                <p class="text-gray-500 text-sm">内容总数</p>
                <p class="text-2xl font-bold text-gray-800"><?php echo number_format($stats['contents']); ?></p>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
                </svg>
            </div>
            <div class="ml-4">
                <p class="text-gray-500 text-sm">栏目数量</p>
                <p class="text-2xl font-bold text-gray-800"><?php echo number_format($stats['channels']); ?></p>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path>
                </svg>
            </div>
            <div class="ml-4">
                <p class="text-gray-500 text-sm">待处理表单</p>
                <p class="text-2xl font-bold text-gray-800"><?php echo number_format($stats['forms']); ?></p>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
            </div>
            <div class="ml-4">
                <p class="text-gray-500 text-sm">媒体文件</p>
                <p class="text-2xl font-bold text-gray-800"><?php echo number_format($stats['media']); ?></p>
            </div>
        </div>
    </div>
</div>

<!-- 内容列表 -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- 最新内容 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h2 class="font-bold text-gray-800">最新内容</h2>
            <a href="/admin/content.php" class="text-primary text-sm hover:underline">查看全部</a>
        </div>
        <div class="p-6">
            <?php if (empty($latestContents)): ?>
            <p class="text-gray-500 text-center py-4">暂无内容</p>
            <?php else: ?>
            <ul class="space-y-3">
                <?php foreach ($latestContents as $item): ?>
                <li class="flex justify-between items-center">
                    <div class="flex items-center">
                        <span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded mr-2">
                            <?php echo e($item['type']); ?>
                        </span>
                        <a href="/admin/content_edit.php?id=<?php echo $item['id']; ?>" class="text-gray-700 hover:text-primary truncate max-w-xs">
                            <?php echo e($item['title']); ?>
                        </a>
                    </div>
                    <span class="text-gray-400 text-sm"><?php echo date('m-d H:i', (int)$item['created_at']); ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>

    <!-- 最新表单 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h2 class="font-bold text-gray-800">最新表单</h2>
            <a href="/admin/form.php" class="text-primary text-sm hover:underline">查看全部</a>
        </div>
        <div class="p-6">
            <?php if (empty($latestForms)): ?>
            <p class="text-gray-500 text-center py-4">暂无表单</p>
            <?php else: ?>
            <ul class="space-y-3">
                <?php foreach ($latestForms as $item): ?>
                <li>
                    <a href="/admin/form.php?view=<?php echo $item['id']; ?>" class="flex justify-between items-center hover:bg-gray-50 -mx-2 px-2 py-1 rounded transition">
                        <div class="flex items-center">
                            <span class="text-xs bg-blue-100 text-blue-600 px-2 py-1 rounded mr-2">
                                <?php echo e($item['type']); ?>
                            </span>
                            <span class="text-gray-700"><?php echo e($item['name']); ?></span>
                            <span class="text-gray-400 ml-2"><?php echo e($item['phone']); ?></span>
                        </div>
                        <span class="text-gray-400 text-sm"><?php echo date('m-d H:i', (int)$item['created_at']); ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
