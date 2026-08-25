<?php
/**
 * Article card partial (horizontal layout) —— 新闻/文章列表的唯一卡片模板
 *
 * 被 list.php（文章型栏目列表）与 news.php（新闻列表 /news.html）共用，改这里两处都生效。
 * ⚠️ 勿在控制器里另写内联卡片（news.php 曾内联导致改此文件对 /news.html 不生效）。
 *
 * @var array $item - title, cover, summary/content, author, publish_time/created_at, views, is_top;
 *                    可选：is_recommend, channel_name, url（自定义详情链接，缺省 contentUrl($item)）
 * @var ?array $listOpts - 栏目「列表显示元素」配置（channelListOptions()），null = 全显示
 */
$__lo = $listOpts ?? null;
?>
<a href="<?php echo e($item['url'] ?? contentUrl($item)); ?>" class="flex gap-6 bg-white rounded-lg shadow overflow-hidden hover:shadow-lg transition group">
    <?php if (listShowEl($__lo, 'cover')): ?>
    <div class="flex-shrink-0 w-48 md:w-64 aspect-[4/3] self-start overflow-hidden bg-gray-100">
        <?php if ($item['cover']): ?>
        <img loading="lazy" decoding="async" <?php echo responsiveImageAttributes($item['cover'], 'medium', '(min-width: 768px) 256px, 192px'); ?> alt="<?php echo e($item['title']); ?>"
             class="w-full h-full object-cover group-hover:scale-110 transition duration-500">
        <?php else: ?>
        <div class="w-full h-full flex items-center justify-center text-gray-300">
            <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="flex-1 py-4 <?php echo listShowEl($__lo, 'cover') ? 'pr-4' : 'px-6'; ?>">
        <h3 class="text-lg font-bold text-dark group-hover:text-primary transition line-clamp-2">
            <?php if (!empty($item['is_top'])): ?>
            <span class="text-xs bg-red-500 text-white px-1.5 py-0.5 rounded mr-2"><?php echo __('article_top'); ?></span>
            <?php endif; ?>
            <?php if (!empty($item['is_recommend'])): ?>
            <span class="text-xs bg-orange-500 text-white px-1.5 py-0.5 rounded mr-2"><?php echo __('article_recommend'); ?></span>
            <?php endif; ?>
            <?php echo e($item['title']); ?>
        </h3>
        <?php if (listShowEl($__lo, 'summary')): ?>
        <p class="mt-2 text-gray-500 text-sm line-clamp-2">
            <?php echo e($item['summary'] ?: cutStr(strip_tags($item['content']), 120)); ?>
        </p>
        <?php endif; ?>
        <div class="mt-3 flex items-center gap-4 text-xs text-gray-400">
            <?php if (listShowEl($__lo, 'channel') && !empty($item['channel_name'])): ?>
            <span class="text-primary"><?php echo e($item['channel_name']); ?></span>
            <?php endif; ?>
            <?php if (listShowEl($__lo, 'author') && !empty($item['author'])): ?>
            <span><?php echo e($item['author']); ?></span>
            <?php endif; ?>
            <?php if (listShowEl($__lo, 'date')): ?>
            <span><?php echo date('Y-m-d', (int)(($item['publish_time'] ?? 0) ?: ($item['created_at'] ?? 0))); ?></span>
            <?php endif; ?>
            <?php if (listShowEl($__lo, 'views')): ?>
            <span><?php echo __('detail_views'); ?> <?php echo number_format((int)$item['views']); ?></span>
            <?php endif; ?>
        </div>
    </div>
</a>
