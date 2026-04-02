<?php
/**
 * Minimal Theme - Article Card (vertical layout)
 *
 * Expected variables:
 * @var array $item - Content data with title, cover, summary, content, author, publish_time, views, is_top
 */
?>
<a href="<?php echo contentUrl($item); ?>" class="block border border-gray-200 hover:border-gray-400 transition group">
    <?php if ($item['cover']): ?>
    <div class="aspect-[16/10] overflow-hidden">
        <img loading="lazy" src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>"
             class="w-full h-full object-cover">
    </div>
    <?php endif; ?>
    <div class="p-5">
        <div class="text-xs text-gray-300 mb-3">
            <?php echo date('Y.m.d', (int)$item['publish_time']); ?>
        </div>
        <h3 class="text-sm text-gray-700 group-hover:text-gray-900 transition line-clamp-2">
            <?php if ($item['is_top']): ?>
            <span class="text-xs text-gray-400 mr-1">TOP</span>
            <?php endif; ?>
            <?php echo e($item['title']); ?>
        </h3>
        <p class="mt-3 text-gray-400 text-xs line-clamp-2 leading-relaxed">
            <?php echo e($item['summary'] ?: cutStr(strip_tags($item['content']), 120)); ?>
        </p>
    </div>
</a>
