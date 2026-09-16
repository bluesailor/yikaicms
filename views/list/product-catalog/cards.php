<?php
/**
 * 产品目录——单个产品卡片（结构树「产品列表」区域内的卡片）。
 *
 * 三种样式：card（通用）/ media（左图右文）/ featured（大图重点）。
 * 主题可通过 partials/product-catalog/card-<style>.php 覆盖；缺失时用这里的默认实现。
 * 图片/标题/摘要为空、无详情链接时均有稳定降级；所有输出转义。
 */

declare(strict_types=1);

/** @var array<string,mixed> $catalog */
/** @var array<string,mixed> $context */
/** @var array<string,mixed> $item */
extract($context, EXTR_SKIP);

$pcCard = in_array($catalog['card'] ?? 'card', ProductCatalogLayout::CARDS, true) ? (string) $catalog['card'] : 'card';
$pcCardOverride = theme_path_optional('partials/product-catalog/card-' . $pcCard . '.php');
if ($pcCardOverride !== null) {
    require $pcCardOverride;
    return;
}

$pcUrl = $isProductType ? productUrl($item) : contentUrl($item);
$pcTitle = (string) ($item['title'] ?? '');
$pcSummary = trim((string) ($item['summary'] ?? ''));
$pcModel = trim((string) ($item['model'] ?? ''));
$pcCover = (string) ($item['cover'] ?? '');
$pcPrice = (float) ($item['price'] ?? 0);
$pcShowPrice = $isProductType && config('show_price', '0') === '1' && $pcPrice > 0;
$pcAlignCls = ($catalog['align'] ?? 'left') === 'center' ? 'text-center items-center' : '';
$pcHasBadges = $isProductType && (!empty($item['is_new']) || !empty($item['is_hot']) || !empty($item['is_recommend']));

$pcBadges = static function () use ($item, $pcHasBadges): void {
    if (!$pcHasBadges) {
        return;
    }
    ?>
    <div class="absolute top-2 left-2 flex flex-col gap-1">
        <?php if (!empty($item['is_new'])): ?><span class="bg-green-500 text-white text-xs px-2 py-0.5 rounded">NEW</span><?php endif; ?>
        <?php if (!empty($item['is_hot'])): ?><span class="bg-red-500 text-white text-xs px-2 py-0.5 rounded">HOT</span><?php endif; ?>
        <?php if (!empty($item['is_recommend'])): ?><span class="bg-primary text-white text-xs px-2 py-0.5 rounded"><?php echo __('article_recommend'); ?></span><?php endif; ?>
    </div>
    <?php
};

$pcCoverBlock = static function (string $extraClass = '') use ($pcCover, $pcTitle, $pcImageRatio, $pcBadges, $catalog): void {
    if (empty($catalog['show_image'])) {
        return;
    }
    ?>
    <div class="<?php echo e(trim($pcImageRatio . ' overflow-hidden relative ' . $extraClass)); ?>">
        <?php if ($pcCover !== ''): ?>
        <img loading="lazy" decoding="async" <?php echo responsiveImageAttributes($pcCover, 'medium', '(min-width: 1280px) 25vw, (min-width: 768px) 33vw, 100vw'); ?>
             alt="<?php echo e($pcTitle); ?>" class="w-full h-full object-cover group-hover:scale-110 transition duration-500">
        <?php else: ?>
        <div class="w-full h-full bg-gray-200 flex items-center justify-center text-gray-400"><?php echo __('admin_no_image'); ?></div>
        <?php endif; ?>
        <?php $pcBadges(); ?>
    </div>
    <?php
};

$pcTextBlock = static function (bool $featured) use ($pcTitle, $pcModel, $pcShowPrice, $pcPrice, $pcSummary, $pcAlignCls, $catalog): void {
    ?>
    <div class="p-<?php echo $featured ? '5' : '4'; ?> flex-1 flex flex-col <?php echo e($pcAlignCls); ?>">
        <?php if (!empty($catalog['show_title']) && $pcTitle !== ''): ?>
        <h3 class="<?php echo $featured ? 'text-xl' : ''; ?> font-bold text-dark group-hover:text-primary transition line-clamp-2"><?php echo e($pcTitle); ?></h3>
        <?php endif; ?>
        <?php if ($pcModel !== ''): ?>
        <p class="text-xs text-gray-400 mt-1"><?php echo e($pcModel); ?></p>
        <?php endif; ?>
        <?php if ($pcShowPrice): ?>
        <div class="mt-2 text-primary font-bold"><?php echo formatPrice($pcPrice); ?></div>
        <?php endif; ?>
        <?php if (!empty($catalog['show_summary']) && $pcSummary !== ''): ?>
        <p class="mt-2 text-sm text-gray-500 <?php echo $featured ? 'line-clamp-3' : 'line-clamp-2'; ?>"><?php echo e($pcSummary); ?></p>
        <?php endif; ?>
        <?php if (!empty($catalog['show_button'])): ?>
        <span class="<?php echo $featured ? 'mt-4 inline-flex items-center rounded-lg bg-primary px-4 py-2 text-sm font-medium text-white' : 'mt-3 inline-flex items-center text-sm font-medium text-primary'; ?>">
            <?php echo e((string) $catalog['button_text']); ?>
            <i class="ti ti-arrow-right ml-1"></i>
        </span>
        <?php endif; ?>
    </div>
    <?php
};

$pcLinkClasses = 'group bg-white rounded-lg overflow-hidden shadow hover:shadow-lg transition relative flex';
if ($pcCard === 'media') {
    $pcLinkClasses .= ' flex-col sm:flex-row';
} else {
    $pcLinkClasses .= ' flex-col';
}
?>
<a href="<?php echo e($pcUrl); ?>" class="<?php echo $pcLinkClasses; ?>">
    <?php if ($pcCard === 'media'): ?>
    <?php $pcCoverBlock('sm:w-2/5 shrink-0'); ?>
    <?php $pcTextBlock(false); ?>
    <?php elseif ($pcCard === 'featured'): ?>
    <?php $pcCoverBlock(); ?>
    <?php $pcTextBlock(true); ?>
    <?php else: ?>
    <?php $pcCoverBlock(); ?>
    <?php $pcTextBlock(false); ?>
    <?php endif; ?>
</a>
