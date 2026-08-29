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
// 横幅开关：show_hero=0 时整条横幅（含面包屑与标题）不渲染——适合 Blox 排版自带首屏的页面。
if (isset($channel['show_hero']) && (int) $channel['show_hero'] === 0) {
    return;
}
// 样式来源可选本页、继承父栏目或全局；标题、简介和面包屑始终来自当前页面。
$heroStyle = PageHeroStyleResolver::resolve($channel);
$heroBg = UrlPolicy::image($heroStyle['background']);
$heroBgCss = UrlPolicy::cssImageLiteral($heroBg);
$heroOptions = $heroStyle['options'];
$heroBgColor = $heroOptions['background_color'];
$heroHeightClass = PageHeroStyleResolver::heightClasses($heroOptions);
$heroCentered = $heroOptions['alignment'] === 'center';
$heroTone = PageHeroStyleResolver::textTone($heroOptions, $heroBg);
$heroSectionClasses = ['relative', 'overflow-hidden', $heroHeightClass];
$heroStyles = [];
if ($heroBg !== '') {
    $heroSectionClasses[] = 'bg-cover';
    $heroStyles[] = 'background-image: ' . $heroBgCss;
    $heroStyles[] = 'background-position: ' . PageHeroStyleResolver::backgroundPosition($heroOptions);
}
if ($heroBgColor !== '') {
    $heroStyles[] = 'background-color: ' . $heroBgColor;
}
$heroLegacyGradient = $heroBg === '' && $heroBgColor === '';
if ($heroLegacyGradient) {
    $heroSectionClasses[] = 'bg-gradient-to-r';
    $heroSectionClasses[] = 'from-gray-900';
    $heroSectionClasses[] = 'via-gray-800';
    $heroSectionClasses[] = 'to-gray-900';
}
?>
<section class="<?php echo e(implode(' ', $heroSectionClasses)); ?>"<?php if ($heroStyles !== []): ?> style="<?php echo e(implode('; ', $heroStyles)); ?>"<?php endif; ?>>
    <?php if ($heroBg !== '' && $heroOptions['overlay_opacity'] > 0): ?>
    <div class="absolute inset-0" style="background-color: rgba(0, 0, 0, <?php echo e((string) ($heroOptions['overlay_opacity'] / 100)); ?>)"></div>
    <?php endif; ?>
    <?php if ($heroLegacyGradient): ?>
    <div class="absolute inset-0 opacity-20">
        <div class="absolute top-0 left-1/4 w-96 h-96 bg-primary rounded-full blur-3xl"></div>
        <div class="absolute bottom-0 right-1/4 w-96 h-96 bg-secondary rounded-full blur-3xl"></div>
    </div>
    <?php endif; ?>
    <div class="container mx-auto px-4 relative">
        <!-- breadcrumb navigation -->
        <?php $style = $heroTone === 'light' ? 'light' : 'default'; require theme_path('partials/breadcrumb.php'); ?>
        <div class="<?php echo $heroCentered ? 'text-center' : 'text-left'; ?>">
            <h1 class="text-4xl md:text-5xl font-bold mb-4 <?php echo $heroTone === 'light' ? 'text-white' : 'text-gray-900'; ?>"><?php echo e($channel['name']); ?></h1>
            <?php if ($channel['description']): ?>
            <p class="text-lg max-w-2xl <?php echo $heroCentered ? 'mx-auto' : ''; ?> <?php echo $heroTone === 'light' ? ($heroBg !== '' ? 'text-gray-200' : 'text-gray-300') : 'text-gray-600'; ?>"><?php echo e($channel['description']); ?></p>
            <?php endif; ?>
        </div>
    </div>
</section>
