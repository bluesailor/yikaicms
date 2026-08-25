<?php
/**
 * Case/portfolio card partial
 *
 * Expected variables:
 * @var array $item - Content data with title, cover
 */
?>
<a href="<?php echo contentUrl($item); ?>" class="group bg-white rounded-lg overflow-hidden shadow hover:shadow-lg transition">
    <div class="aspect-[4/3] overflow-hidden">
        <?php if ($item['cover']): ?>
        <img loading="lazy" decoding="async" <?php echo responsiveImageAttributes($item['cover'], 'medium', '(min-width: 1280px) 25vw, (min-width: 768px) 33vw, 100vw'); ?> alt="<?php echo e($item['title']); ?>"
             class="w-full h-full object-cover group-hover:scale-110 transition duration-500">
        <?php else: ?>
        <div class="w-full h-full bg-gray-200 flex items-center justify-center text-gray-400">
            <?php echo __('admin_no_image'); ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="p-4">
        <h3 class="font-bold text-dark group-hover:text-primary transition line-clamp-2">
            <?php echo e($item['title']); ?>
        </h3>
    </div>
</a>
