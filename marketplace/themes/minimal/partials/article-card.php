<?php
/**
 * Minimal Theme - Article Card (vertical layout)
 *
 * Expected variables:
 * @var array $item - Content data with title, cover, summary, content, author, publish_time, views, is_top
 * @var ?array $listOpts - 栏目「列表显示元素」配置（channelListOptions()），null = 全显示
 */
$__lo = $listOpts ?? null;
$showCover = listShowEl($__lo, 'cover') && !empty($item['cover']);
?>
<a href="<?php echo e($item['url'] ?? contentUrl($item)); ?>"
   class="minimal-news-card<?php echo $showCover ? ' has-media' : ''; ?> border border-gray-200 hover:border-gray-400 transition group"
   data-minimal-news-card>
    <?php if ($showCover): ?>
    <div class="minimal-news-card__media overflow-hidden" data-minimal-news-media>
        <img loading="lazy" src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>"
             class="w-full h-full object-cover">
    </div>
    <?php endif; ?>
    <div class="minimal-news-card__body p-5">
        <?php if (listShowEl($__lo, 'date')): ?>
        <div class="text-xs text-gray-300 mb-3">
            <?php echo date('Y.m.d', (int)(($item['publish_time'] ?? 0) ?: ($item['created_at'] ?? 0))); ?>
        </div>
        <?php endif; ?>
        <h3 class="text-sm text-gray-700 group-hover:text-gray-900 transition line-clamp-2">
            <?php if ($item['is_top']): ?>
            <span class="text-xs text-gray-400 mr-1">TOP</span>
            <?php endif; ?>
            <?php echo e($item['title']); ?>
        </h3>
        <?php if (listShowEl($__lo, 'summary')): ?>
        <p class="mt-3 text-gray-400 text-xs line-clamp-2 leading-relaxed">
            <?php echo e($item['summary'] ?: cutStr(strip_tags($item['content']), 120)); ?>
        </p>
        <?php endif; ?>
    </div>
</a>
