<?php
/**
 * Product card partial
 *
 * Expected variables:
 * @var array $item - Product data with title, cover, is_new, is_hot, is_recommend, model, price
 * @var bool $isProductType - Whether the current channel is a product type
 */
?>
<a href="<?php echo $isProductType ? productUrl($item) : contentUrl($item); ?>" class="group bg-white rounded-lg overflow-hidden shadow hover:shadow-lg transition relative">
    <div class="aspect-[4/3] overflow-hidden relative">
        <?php if ($item['cover']): ?>
        <img loading="lazy" src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>"
             class="w-full h-full object-cover group-hover:scale-110 transition duration-500">
        <?php else: ?>
        <div class="w-full h-full bg-gray-200 flex items-center justify-center text-gray-400">
            <?php echo __('admin_no_image'); ?>
        </div>
        <?php endif; ?>
        <?php if ($isProductType && (!empty($item['is_new']) || !empty($item['is_hot']) || !empty($item['is_recommend']))): ?>
        <div class="absolute top-2 left-2 flex flex-col gap-1">
            <?php if (!empty($item['is_new'])): ?>
            <span class="bg-green-500 text-white text-xs px-2 py-0.5 rounded">NEW</span>
            <?php endif; ?>
            <?php if (!empty($item['is_hot'])): ?>
            <span class="bg-red-500 text-white text-xs px-2 py-0.5 rounded">HOT</span>
            <?php endif; ?>
            <?php if (!empty($item['is_recommend'])): ?>
            <span class="bg-primary text-white text-xs px-2 py-0.5 rounded">推荐</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="p-4">
        <h3 class="font-bold text-dark group-hover:text-primary transition line-clamp-2">
            <?php echo e($item['title']); ?>
        </h3>
        <?php if ($isProductType && !empty($item['model'])): ?>
        <p class="text-xs text-gray-400 mt-1"><?php echo e($item['model']); ?></p>
        <?php endif; ?>
        <?php if (config('show_price', '0') === '1' && $isProductType && !empty($item['price']) && $item['price'] > 0): ?>
        <div class="mt-2 text-primary font-bold">&yen;<?php echo number_format((float)$item['price'], 2); ?></div>
        <?php endif; ?>
    </div>
</a>
