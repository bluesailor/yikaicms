<?php
/**
 * 联系页专用页头：比通用内容页页头更紧凑，给表单与联系方式保留首屏空间。
 *
 * @var array $channel
 * @var array $breadcrumbItems
 */
?>
<?php
// 横幅开关：show_hero=0 时联系页页头（含面包屑与标题）整条不渲染。
if (isset($channel['show_hero']) && (int) $channel['show_hero'] === 0) {
    return;
}
// 联系页页头默认保持紧凑白底（给表单留首屏空间），仅在显式设置 hero_bg 时切换为
// 图片横幅——不继承 image/全局默认，避免升级改变存量站的既有观感。
$contactHeroBg = (string) ($channel['hero_bg'] ?? '');
?>
<?php echo breadcrumbJsonLd($breadcrumbItems ?? []); ?>
<?php if ($contactHeroBg !== ''): ?>
<section class="relative bg-cover bg-center" style="background-image: url('<?php echo e($contactHeroBg); ?>')">
    <div class="absolute inset-0 bg-black/60"></div>
    <div class="max-w-6xl mx-auto px-4 py-10 md:py-14 relative">
        <nav class="flex items-center gap-2 text-sm text-gray-300 mb-6" aria-label="<?php echo e(__('breadcrumb_nav')); ?>">
            <a href="/" class="hover:text-white transition"><?php echo __('breadcrumb_home'); ?></a>
            <?php foreach (($breadcrumbItems ?? []) as $i => $item): ?>
            <span class="text-gray-400">/</span>
            <?php if ($i === count($breadcrumbItems) - 1): ?>
            <span class="text-gray-100"><?php echo e((string) ($item['name'] ?? '')); ?></span>
            <?php else: ?>
            <a href="<?php echo e((string) ($item['url'] ?? '')); ?>" class="hover:text-white transition"><?php echo e((string) ($item['name'] ?? '')); ?></a>
            <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <div class="max-w-3xl border-l-4 border-primary pl-5 md:pl-7">
            <h1 class="text-3xl md:text-4xl font-bold text-white"><?php echo e((string) ($channel['name'] ?? __('contact_title'))); ?></h1>
            <?php if (!empty($channel['description'])): ?>
            <p class="mt-3 text-base md:text-lg text-gray-200 leading-relaxed"><?php echo e((string) $channel['description']); ?></p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php else: ?>
<section class="bg-white border-y border-gray-100">
    <div class="max-w-6xl mx-auto px-4 py-10 md:py-14">
        <nav class="flex items-center gap-2 text-sm text-gray-500 mb-6" aria-label="<?php echo e(__('breadcrumb_nav')); ?>">
            <a href="/" class="hover:text-primary transition"><?php echo __('breadcrumb_home'); ?></a>
            <?php foreach (($breadcrumbItems ?? []) as $i => $item): ?>
            <span class="text-gray-300">/</span>
            <?php if ($i === count($breadcrumbItems) - 1): ?>
            <span class="text-gray-700"><?php echo e((string) ($item['name'] ?? '')); ?></span>
            <?php else: ?>
            <a href="<?php echo e((string) ($item['url'] ?? '')); ?>" class="hover:text-primary transition"><?php echo e((string) ($item['name'] ?? '')); ?></a>
            <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <div class="max-w-3xl border-l-4 border-primary pl-5 md:pl-7">
            <h1 class="text-3xl md:text-4xl font-bold text-gray-900"><?php echo e((string) ($channel['name'] ?? __('contact_title'))); ?></h1>
            <?php if (!empty($channel['description'])): ?>
            <p class="mt-3 text-base md:text-lg text-gray-600 leading-relaxed"><?php echo e((string) $channel['description']); ?></p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>
