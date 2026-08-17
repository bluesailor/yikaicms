<?php
/**
 * Aurora Theme - Article Card
 *
 * @var array $item - title, cover, summary, published_at, channel_name
 * @var ?array $listOpts - 栏目「列表显示元素」配置（channelListOptions()），null = 全显示
 */
$__lo = $listOpts ?? null;
?>
<a href="<?php echo e($item['url'] ?? contentUrl($item)); ?>"
   class="group relative block rounded-xl overflow-hidden aurora-glass aurora-glass-hover transition">
    <div class="absolute -inset-px rounded-xl bg-gradient-to-r from-indigo-500/0 via-purple-500/0 to-pink-500/0 group-hover:from-indigo-500/30 group-hover:to-purple-500/30 transition-all duration-500 opacity-0 group-hover:opacity-100 -z-10 blur-md"></div>

    <?php if (listShowEl($__lo, 'cover') && $item['cover']): ?>
    <div class="aspect-[16/9] overflow-hidden bg-slate-900">
        <img loading="lazy" src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>"
             class="w-full h-full object-cover transition duration-500 group-hover:scale-105 opacity-90 group-hover:opacity-100">
    </div>
    <?php endif; ?>

    <div class="p-6">
        <?php if (listShowEl($__lo, 'channel') && !empty($item['channel_name'])): ?>
        <div class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-medium uppercase tracking-wider text-indigo-300 bg-indigo-500/10 border border-indigo-500/20 mb-3">
            <?php echo e($item['channel_name']); ?>
        </div>
        <?php endif; ?>
        <h3 class="text-lg font-semibold text-slate-100 group-hover:text-white transition line-clamp-2 leading-snug">
            <?php echo e($item['title']); ?>
        </h3>
        <?php if (listShowEl($__lo, 'summary') && !empty($item['summary'])): ?>
        <p class="mt-3 text-sm text-slate-400 leading-relaxed line-clamp-3">
            <?php echo e($item['summary']); ?>
        </p>
        <?php endif; ?>
        <div class="mt-5 flex items-center justify-between pt-4 border-t border-slate-800/60">
            <?php if (listShowEl($__lo, 'date') && !empty($item['published_at'])): ?>
            <time class="text-xs text-slate-500"><?php echo date('Y.m.d', is_numeric($item['published_at']) ? (int)$item['published_at'] : strtotime((string)$item['published_at'])); ?></time>
            <?php else: ?>
            <span></span>
            <?php endif; ?>
            <span class="inline-flex items-center gap-1 text-xs text-slate-500 group-hover:text-indigo-300 transition">
                <?php echo __('read_more'); ?>
                <svg class="w-3.5 h-3.5 transition-transform group-hover:translate-x-1" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
            </span>
        </div>
    </div>
</a>
