<?php
/**
 * Business 主题 - 栏目区块（新闻深色背景、案例/产品白色背景）
 */
if (empty($currentChannel)) return;
$hChannel = $currentChannel;
$channelType = $hChannel['type'];
$contents = $hChannel['contents'];
$bgMap = ['product' => 'bg-white', 'case' => 'bg-gray-50', 'list' => 'section-dark', 'article' => 'section-dark'];
$sectionBg = $bgMap[$channelType] ?? 'bg-white';
$isDark = str_contains($sectionBg, 'dark');
?>
<section class="py-20 <?php echo $sectionBg; ?>">
    <div class="container mx-auto px-4">
        <!-- 标题 -->
        <div class="flex items-end justify-between mb-12" data-animate="fade-up">
            <div>
                <h2 class="text-3xl font-bold <?php echo $isDark ? 'text-white' : 'text-dark'; ?> mb-3"><?php echo e($hChannel['name']); ?></h2>
                <img src="/themes/business/images/divide.png" alt="" class="mb-2">
                <?php if ($hChannel['description']): ?>
                <p class="mt-2 <?php echo $isDark ? 'text-gray-400' : 'text-gray-500'; ?>"><?php echo e($hChannel['description']); ?></p>
                <?php endif; ?>
            </div>
            <a href="<?php echo channelUrl($hChannel); ?>" class="hidden md:inline-flex items-center gap-1 bg-primary hover:bg-secondary text-white px-5 py-2 rounded-full text-sm font-medium transition">
                <?php echo e($hChannel['name']); ?>
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </a>
        </div>

        <?php if (!empty($contents)): ?>

        <?php if ($hChannel['is_product'] ?? false): ?>
        <!-- 产品网格 -->
        <div class="grid grid-cols-2 md:grid-cols-3 gap-6" data-stagger>
            <?php foreach (array_slice($contents, 0, 6) as $item): ?>
            <a href="<?php echo productUrl($item); ?>" class="block group">
                <div class="aspect-square bg-gray-100 rounded-lg overflow-hidden mb-3">
                    <?php if ($item['cover']): ?>
                    <img loading="lazy" src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>" class="w-full h-full object-cover group-hover:scale-105 transition duration-500">
                    <?php else: ?>
                    <div class="w-full h-full flex items-center justify-center text-gray-300">
                        <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    </div>
                    <?php endif; ?>
                </div>
                <h3 class="text-center text-sm font-medium text-dark group-hover:text-primary transition"><?php echo e($item['title']); ?></h3>
            </a>
            <?php endforeach; ?>
        </div>

        <?php elseif ($channelType === 'case'): ?>
        <!-- 案例网格 -->
        <div class="grid grid-cols-2 md:grid-cols-3 gap-6" data-stagger>
            <?php foreach (array_slice($contents, 0, 6) as $item): ?>
            <a href="<?php echo contentUrl($item); ?>" class="block group">
                <div class="aspect-[4/3] bg-gray-200 rounded-lg overflow-hidden mb-3">
                    <?php if ($item['cover']): ?>
                    <img loading="lazy" src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>" class="w-full h-full object-cover group-hover:scale-105 transition duration-500">
                    <?php endif; ?>
                </div>
                <h3 class="text-center text-sm font-medium <?php echo $isDark ? 'text-gray-200 group-hover:text-white' : 'text-dark group-hover:text-primary'; ?> transition"><?php echo e($item['title']); ?></h3>
            </a>
            <?php endforeach; ?>
        </div>

        <?php else: ?>
        <!-- 文章网格（深色背景，只显示有封面图的） -->
        <?php $withCover = array_filter($contents, fn($i) => !empty($i['cover'])); ?>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-6" data-stagger>
            <?php foreach (array_slice($withCover, 0, 4) as $item): ?>
            <a href="<?php echo contentUrl($item); ?>" class="block group">
                <div class="aspect-[16/9] bg-gray-700 rounded-lg overflow-hidden mb-3">
                    <img loading="lazy" src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>" class="w-full h-full object-cover group-hover:scale-105 transition duration-500">
                </div>
                <h3 class="font-bold <?php echo $isDark ? 'text-white group-hover:text-blue-300' : 'text-dark group-hover:text-primary'; ?> transition"><?php echo e($item['title']); ?></h3>
                <p class="text-sm text-gray-500 mt-1"><?php echo date('Y-m-d', (int)(($item['publish_time'] ?? 0) ?: ($item['created_at'] ?? 0))); ?></p>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- 移动端查看更多 -->
        <div class="mt-8 text-center md:hidden">
            <a href="<?php echo channelUrl($hChannel); ?>" class="inline-block bg-primary hover:bg-secondary text-white px-6 py-2 rounded-full text-sm transition">
                <?php echo __('home_view_all'); ?> &raquo;
            </a>
        </div>

        <?php endif; ?>
    </div>
</section>
