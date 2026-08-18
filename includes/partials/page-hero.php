<?php
/**
 * Page hero/header section partial
 *
 * Expected variables:
 * @var array $channel - Channel data with name, description, image
 * @var array $breadcrumbItems - Breadcrumb items array
 */
?>
<?php
// 与主题版 partials/page-hero.php 同步的解析逻辑（此文件是主题缺失时的回退副本，勿再漂移）：
// show_hero=0 整条不渲染；背景 hero_bg → image → 全局默认 page_hero_default_bg → 渐变。
if (isset($channel['show_hero']) && (int) $channel['show_hero'] === 0) {
    return;
}
$heroBg = ($channel['hero_bg'] ?? '') ?: (($channel['image'] ?? '') ?: (string) config('page_hero_default_bg', ''));
?>
<?php if ($heroBg): ?>
<section class="relative py-16 bg-cover bg-center" style="background-image: url('<?php echo e($heroBg); ?>')">
    <div class="absolute inset-0 bg-black/60"></div>
    <div class="container mx-auto px-4 relative">
        <!-- 面包屑导航 -->
        <?php $style = 'light'; require theme_path('partials/breadcrumb.php'); ?>
        <div class="text-center">
            <h1 class="text-4xl md:text-5xl font-bold text-white mb-4"><?php echo e($channel['name']); ?></h1>
            <?php if ($channel['description']): ?>
            <p class="text-gray-200 text-lg max-w-2xl mx-auto"><?php echo e($channel['description']); ?></p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php else: ?>
<section class="bg-gradient-to-r from-gray-900 via-gray-800 to-gray-900 py-16 relative overflow-hidden">
    <div class="absolute inset-0 opacity-20">
        <div class="absolute top-0 left-1/4 w-96 h-96 bg-primary rounded-full blur-3xl"></div>
        <div class="absolute bottom-0 right-1/4 w-96 h-96 bg-secondary rounded-full blur-3xl"></div>
    </div>
    <div class="container mx-auto px-4 relative">
        <!-- 面包屑导航 -->
        <?php $style = 'default'; require theme_path('partials/breadcrumb.php'); ?>
        <div class="text-center">
            <h1 class="text-4xl md:text-5xl font-bold text-white mb-4"><?php echo e($channel['name']); ?></h1>
            <?php if ($channel['description']): ?>
            <p class="text-gray-300 text-lg max-w-2xl mx-auto"><?php echo e($channel['description']); ?></p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>
