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
// 默认 self 仍只认显式 hero_bg，保持老站白底；只有主动选择 parent/global 才启用共享背景。
$contactHeroStyle = PageHeroStyleResolver::resolve($channel, true);
$contactHeroBg = $contactHeroStyle['background'];
$contactHeroOptions = $contactHeroStyle['options'];
$contactHeroBgColor = $contactHeroOptions['background_color'];
$contactHeroHeightClass = PageHeroStyleResolver::heightClasses($contactHeroOptions, true);
$contactHeroCentered = $contactHeroOptions['alignment'] === 'center';
$contactHeroTone = PageHeroStyleResolver::textTone($contactHeroOptions, $contactHeroBg, true);
$contactHeroLinkHoverClass = $contactHeroTone === 'light' ? 'hover:text-white' : 'hover:text-primary';
$contactHeroStyles = [];
if ($contactHeroBg !== '') {
    $contactHeroStyles[] = "background-image: url('" . $contactHeroBg . "')";
    $contactHeroStyles[] = 'background-position: ' . PageHeroStyleResolver::backgroundPosition($contactHeroOptions);
}
if ($contactHeroBgColor !== '') {
    $contactHeroStyles[] = 'background-color: ' . $contactHeroBgColor;
}
?>
<?php echo breadcrumbJsonLd($breadcrumbItems ?? []); ?>
<section class="relative <?php echo $contactHeroBg !== '' ? 'bg-cover' : ($contactHeroBgColor === '' ? 'bg-white border-y border-gray-100' : ''); ?>"<?php if ($contactHeroStyles !== []): ?> style="<?php echo e(implode('; ', $contactHeroStyles)); ?>"<?php endif; ?>>
    <?php if ($contactHeroBg !== '' && $contactHeroOptions['overlay_opacity'] > 0): ?>
    <div class="absolute inset-0" style="background-color: rgba(0, 0, 0, <?php echo e((string) ($contactHeroOptions['overlay_opacity'] / 100)); ?>)"></div>
    <?php endif; ?>
    <div class="max-w-6xl mx-auto px-4 <?php echo e($contactHeroHeightClass); ?> relative">
        <nav class="flex items-center gap-2 text-sm mb-6 <?php echo $contactHeroTone === 'light' ? 'text-gray-300' : 'text-gray-500'; ?> <?php echo $contactHeroCentered ? 'justify-center' : ''; ?>" aria-label="<?php echo e(__('breadcrumb_nav')); ?>">
            <a href="/" class="<?php echo $contactHeroLinkHoverClass; ?> transition"><?php echo __('breadcrumb_home'); ?></a>
            <?php foreach (($breadcrumbItems ?? []) as $i => $item): ?>
            <span class="<?php echo $contactHeroTone === 'light' ? 'text-gray-400' : 'text-gray-300'; ?>">/</span>
            <?php if ($i === count($breadcrumbItems) - 1): ?>
            <span class="<?php echo $contactHeroTone === 'light' ? 'text-gray-100' : 'text-gray-700'; ?>"><?php echo e((string) ($item['name'] ?? '')); ?></span>
            <?php else: ?>
            <a href="<?php echo e((string) ($item['url'] ?? '')); ?>" class="<?php echo $contactHeroLinkHoverClass; ?> transition"><?php echo e((string) ($item['name'] ?? '')); ?></a>
            <?php endif; ?>
            <?php endforeach; ?>
        </nav>
        <div class="max-w-3xl <?php echo $contactHeroCentered ? 'mx-auto text-center' : 'border-l-4 border-primary pl-5 md:pl-7'; ?>">
            <h1 class="text-3xl md:text-4xl font-bold <?php echo $contactHeroTone === 'light' ? 'text-white' : 'text-gray-900'; ?>"><?php echo e((string) ($channel['name'] ?? __('contact_title'))); ?></h1>
            <?php if (!empty($channel['description'])): ?>
            <p class="mt-3 text-base md:text-lg leading-relaxed <?php echo $contactHeroTone === 'light' ? 'text-gray-200' : 'text-gray-600'; ?>"><?php echo e((string) $channel['description']); ?></p>
            <?php endif; ?>
        </div>
    </div>
</section>
