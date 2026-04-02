<?php
/**
 * 右侧导航侧边栏
 *
 * 需要变量: $rightSidebarTitle, $rightSidebarChannels, $channelId
 * 可选变量: $rightSidebarActiveId (覆盖高亮ID，用于下载子分类等场景)
 */
$activeId = $rightSidebarActiveId ?? $channelId;
?>
<div class="w-full lg:w-64">
    <div class="bg-white rounded-lg shadow">
        <div class="px-4 py-3 border-b font-bold text-dark bg-primary text-white rounded-t-lg">
            <?php echo e($rightSidebarTitle); ?>
        </div>
        <div class="divide-y">
            <?php foreach ($rightSidebarChannels as $sub): ?>
            <a href="<?php echo channelUrl($sub); ?>"
               class="block px-4 py-3 hover:bg-gray-50 transition <?php echo (int)$sub['id'] === $activeId ? 'text-primary bg-blue-50 font-medium' : 'text-gray-700 hover:text-primary'; ?>">
                <?php echo e($sub['name']); ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 联系方式 -->
    <div class="bg-white rounded-lg shadow mt-6">
        <div class="px-4 py-3 border-b font-bold text-dark"><?php echo __('footer_contact'); ?></div>
        <div class="p-4 space-y-3 text-sm">
            <?php if ($phone = config('contact_phone')): ?>
            <div class="flex items-center gap-2">
                <svg class="w-4 h-4 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                </svg>
                <span><?php echo e($phone); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($email = config('contact_email')): ?>
            <div class="flex items-center gap-2">
                <svg class="w-4 h-4 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                </svg>
                <span><?php echo e($email); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($address = config('contact_address')): ?>
            <div class="flex items-start gap-2">
                <svg class="w-4 h-4 text-primary mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                </svg>
                <span><?php echo e($address); ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
