<?php
/**
 * Minimal Theme - Product Card
 *
 * Expected variables:
 * @var array $item - Product data with title, cover, is_new, is_hot, is_recommend, model, price
 * @var bool $isProductType - Whether the current channel is a product type
 */
?>
<a href="<?php echo $isProductType ? productUrl($item) : contentUrl($item); ?>" class="group block border border-gray-200 hover:border-gray-400 transition">
    <div class="aspect-[4/3] overflow-hidden">
        <?php if ($item['cover']): ?>
        <img loading="lazy" src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>"
             class="w-full h-full object-cover">
        <?php else: ?>
        <div class="w-full h-full bg-gray-50 flex items-center justify-center text-gray-300">
            <?php echo __('admin_no_image'); ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="p-4">
        <h3 class="text-sm text-gray-700 group-hover:text-gray-900 transition line-clamp-2">
            <?php echo e($item['title']); ?>
        </h3>
        <?php if ($isProductType && !empty($item['model'])): ?>
        <p class="text-xs text-gray-300 mt-1"><?php echo e($item['model']); ?></p>
        <?php endif; ?>
        <?php if (config('show_price', '0') === '1' && $isProductType && !empty($item['price']) && $item['price'] > 0): ?>
        <div class="mt-2 text-gray-900 text-sm">&yen;<?php echo number_format((float)$item['price'], 2); ?></div>
        <?php endif; ?>
    </div>
</a>
