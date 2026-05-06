<?php
/**
 * Article card partial (horizontal layout)
 *
 * Expected variables:
 * @var array $item - Content data with title, cover, summary, content, author, publish_time, views, is_top
 */
?>
<a href="<?php echo contentUrl($item); ?>" class="flex gap-6 bg-white rounded-lg shadow overflow-hidden hover:shadow-lg transition group">
    <?php if ($item['cover']): ?>
    <div class="flex-shrink-0 w-48 md:w-64 overflow-hidden">
        <img loading="lazy" src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>"
             class="w-full h-full object-cover group-hover:scale-110 transition duration-500">
    </div>
    <?php endif; ?>
    <div class="flex-1 py-4 pr-4 <?php echo $item['cover'] ? '' : 'pl-4'; ?>">
        <h3 class="text-lg font-bold text-dark group-hover:text-primary transition line-clamp-2">
            <?php if ($item['is_top']): ?>
            <span class="text-xs bg-red-500 text-white px-1.5 py-0.5 rounded mr-2">置顶</span>
            <?php endif; ?>
            <?php echo e($item['title']); ?>
        </h3>
        <p class="mt-2 text-gray-500 text-sm line-clamp-2">
            <?php echo e($item['summary'] ?: cutStr(strip_tags($item['content']), 120)); ?>
        </p>
        <div class="mt-3 flex items-center gap-4 text-xs text-gray-400">
            <?php if ($item['author']): ?>
            <span><?php echo e($item['author']); ?></span>
            <?php endif; ?>
            <span><?php echo date('Y-m-d', (int)(($item['publish_time'] ?? 0) ?: ($item['created_at'] ?? 0))); ?></span>
            <span><?php echo __('detail_views'); ?> <?php echo number_format((int)$item['views']); ?></span>
        </div>
    </div>
</a>
