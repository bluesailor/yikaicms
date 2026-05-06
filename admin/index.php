<?php
/**
 * ikaiCMS - 后台控制台
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

// 最新内容（关联栏目类型）
$latestContents = contentModel()->query(
    'SELECT c.*, ch.type AS channel_type FROM ' . contentModel()->tableName() . ' c LEFT JOIN ' . channelModel()->tableName() . ' ch ON c.channel_id = ch.id ORDER BY c.id DESC LIMIT 10'
);

// 最新表单
$latestForms = formModel()->where([], 'id DESC', 10);

$pageTitle = __('admin_dashboard');
$currentMenu = 'dashboard';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- クイックアクセス -->
<div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4 mb-8">
    <a href="/admin/setting.php" class="bg-white rounded-lg shadow p-4 hover:shadow-md transition flex flex-col items-center gap-2 group">
        <div class="w-10 h-10 bg-blue-50 rounded-lg flex items-center justify-center group-hover:bg-blue-100 transition">
            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        </div>
        <span class="text-sm text-gray-600 font-medium"><?php echo __('dashboard_quick_setting'); ?></span>
    </a>
    <a href="/admin/setting_home.php" class="bg-white rounded-lg shadow p-4 hover:shadow-md transition flex flex-col items-center gap-2 group">
        <div class="w-10 h-10 bg-green-50 rounded-lg flex items-center justify-center group-hover:bg-green-100 transition">
            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
        </div>
        <span class="text-sm text-gray-600 font-medium"><?php echo __('dashboard_quick_home'); ?></span>
    </a>
    <a href="/admin/setting_contact.php" class="bg-white rounded-lg shadow p-4 hover:shadow-md transition flex flex-col items-center gap-2 group">
        <div class="w-10 h-10 bg-cyan-50 rounded-lg flex items-center justify-center group-hover:bg-cyan-100 transition">
            <svg class="w-5 h-5 text-cyan-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
        </div>
        <span class="text-sm text-gray-600 font-medium"><?php echo __('dashboard_quick_contact'); ?></span>
    </a>
    <a href="/admin/theme.php" class="bg-white rounded-lg shadow p-4 hover:shadow-md transition flex flex-col items-center gap-2 group">
        <div class="w-10 h-10 bg-purple-50 rounded-lg flex items-center justify-center group-hover:bg-purple-100 transition">
            <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"/></svg>
        </div>
        <span class="text-sm text-gray-600 font-medium"><?php echo __('dashboard_quick_theme'); ?></span>
    </a>
    <a href="/admin/banner.php" class="bg-white rounded-lg shadow p-4 hover:shadow-md transition flex flex-col items-center gap-2 group">
        <div class="w-10 h-10 bg-amber-50 rounded-lg flex items-center justify-center group-hover:bg-amber-100 transition">
            <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        </div>
        <span class="text-sm text-gray-600 font-medium"><?php echo __('dashboard_quick_banner'); ?></span>
    </a>
    <a href="/admin/channel.php" class="bg-white rounded-lg shadow p-4 hover:shadow-md transition flex flex-col items-center gap-2 group">
        <div class="w-10 h-10 bg-rose-50 rounded-lg flex items-center justify-center group-hover:bg-rose-100 transition">
            <svg class="w-5 h-5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
        </div>
        <span class="text-sm text-gray-600 font-medium"><?php echo __('dashboard_quick_channel'); ?></span>
    </a>
</div>

<!-- 統計カード -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center">
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
            </div>
            <div class="ml-4">
                <p class="text-gray-500 text-sm"><?php echo __('dashboard_total_contents'); ?></p>
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
                <p class="text-gray-500 text-sm"><?php echo __('dashboard_category_count'); ?></p>
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
                <p class="text-gray-500 text-sm"><?php echo __('dashboard_pending_forms'); ?></p>
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
                <p class="text-gray-500 text-sm"><?php echo __('dashboard_media_files'); ?></p>
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
            <h2 class="font-bold text-gray-800"><?php echo __('dashboard_latest_contents'); ?></h2>
            <a href="/admin/content.php" class="text-primary text-sm hover:underline"><?php echo __('dashboard_see_all'); ?></a>
        </div>
        <div class="p-6">
            <?php if (empty($latestContents)): ?>
            <p class="text-gray-500 text-center py-4"><?php echo __('dashboard_no_contents'); ?></p>
            <?php else: ?>
            <ul class="space-y-3">
                <?php foreach ($latestContents as $item): ?>
                <li class="flex justify-between items-center">
                    <div class="flex items-center">
                        <span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded mr-2">
                            <?php echo e($item['type']); ?>
                        </span>
                        <?php
                        $editUrl = ($item['channel_type'] ?? '') === 'page'
                            ? '/admin/page_edit_advance.php?id=' . $item['channel_id']
                            : '/admin/article_edit.php?id=' . $item['id'];
                        ?>
                        <a href="<?php echo $editUrl; ?>" class="text-gray-700 hover:text-primary truncate max-w-xs">
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
            <h2 class="font-bold text-gray-800"><?php echo __('dashboard_latest_forms'); ?></h2>
            <a href="/admin/form.php" class="text-primary text-sm hover:underline"><?php echo __('dashboard_see_all'); ?></a>
        </div>
        <div class="p-6">
            <?php if (empty($latestForms)): ?>
            <p class="text-gray-500 text-center py-4"><?php echo __('dashboard_no_forms'); ?></p>
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
