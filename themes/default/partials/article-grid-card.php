<?php
/** Article grid card used by the Blox content catalog. */

declare(strict_types=1);

$__gridOpts = $listOpts ?? null;
?>
<a href="<?php echo e($item['url'] ?? contentUrl($item)); ?>"
   class="group block overflow-hidden rounded-lg border border-gray-100 bg-white shadow-sm transition hover:shadow-md no-underline">
    <?php if (listShowEl($__gridOpts, 'cover')): ?>
    <div class="aspect-video overflow-hidden bg-gray-100">
        <?php if (!empty($item['cover'])): ?>
        <img loading="lazy" src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>"
             class="h-full w-full object-cover transition duration-500 group-hover:scale-105">
        <?php else: ?>
        <div class="flex h-full w-full items-center justify-center text-gray-300"><i class="ti ti-photo text-4xl"></i></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="p-5">
        <h3 class="line-clamp-2 text-lg font-bold text-dark transition group-hover:text-primary"><?php echo e($item['title']); ?></h3>
        <?php if (listShowEl($__gridOpts, 'summary')): ?>
        <p class="mt-2 line-clamp-2 text-sm text-gray-500"><?php echo e($item['summary'] ?: cutStr(strip_tags($item['content']), 100)); ?></p>
        <?php endif; ?>
        <div class="mt-4 flex flex-wrap items-center gap-3 text-xs text-gray-400">
            <?php if (listShowEl($__gridOpts, 'channel') && !empty($item['channel_name'])): ?><span class="text-primary"><?php echo e($item['channel_name']); ?></span><?php endif; ?>
            <?php if (listShowEl($__gridOpts, 'author') && !empty($item['author'])): ?><span><?php echo e($item['author']); ?></span><?php endif; ?>
            <?php if (listShowEl($__gridOpts, 'date')): ?><span><?php echo date('Y-m-d', (int) (($item['publish_time'] ?? 0) ?: ($item['created_at'] ?? 0))); ?></span><?php endif; ?>
            <?php if (listShowEl($__gridOpts, 'views')): ?><span><?php echo e(__('detail_views')); ?> <?php echo number_format((int) ($item['views'] ?? 0)); ?></span><?php endif; ?>
        </div>
    </div>
</a>
