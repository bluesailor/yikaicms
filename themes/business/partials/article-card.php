<?php
/**
 * Business 主题 — 文章卡片（横向布局，左侧蓝色强调条）
 *
 * 与 includes/partials/article-card.php 保持同样的变量契约：
 *   @var array $item  ['title','cover','summary','content','author','publish_time','views','is_top']
 *
 * 视觉语言：方角为主、贴边强调条、密集信息密度。
 */
?>
<a href="<?php echo e($item['url'] ?? contentUrl($item)); ?>"
   class="group flex gap-0 bg-white border border-slate-200
          hover:border-primary hover:shadow-[0_8px_24px_-12px_rgba(59,108,245,0.45)]
          transition relative overflow-hidden">
    <!-- 左侧蓝色 accent，hover 时拉伸 -->
    <span aria-hidden
          class="absolute left-0 top-0 bottom-0 w-1 bg-primary
                 scale-y-0 group-hover:scale-y-100 origin-top transition duration-300"></span>

    <?php if (!empty($item['cover'])): ?>
    <div class="flex-shrink-0 w-48 md:w-56 overflow-hidden bg-slate-900">
        <img loading="lazy" src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>"
             alt="<?php echo e($item['title']); ?>"
             class="w-full h-full object-cover group-hover:scale-105 transition duration-500">
    </div>
    <?php endif; ?>

    <div class="flex-1 py-5 px-6 flex flex-col">
        <div class="flex items-center gap-2 text-xs text-slate-400 font-medium uppercase tracking-wider mb-2">
            <span class="block w-6 h-px bg-primary"></span>
            <?php echo date('Y.m.d', (int)(($item['publish_time'] ?? 0) ?: ($item['created_at'] ?? 0))); ?>
        </div>

        <h3 class="text-lg font-bold text-slate-900 group-hover:text-primary transition line-clamp-2 leading-snug">
            <?php if (!empty($item['is_top'])): ?>
            <span class="inline-block text-[10px] bg-primary text-white px-1.5 py-0.5 mr-2 align-middle uppercase tracking-wide">置顶</span>
            <?php endif; ?>
            <?php echo e($item['title']); ?>
        </h3>

        <p class="mt-3 text-slate-500 text-sm line-clamp-2 leading-relaxed">
            <?php echo e($item['summary'] ?: cutStr(strip_tags($item['content'] ?? ''), 120)); ?>
        </p>

        <div class="mt-auto pt-3 flex items-center gap-4 text-xs text-slate-400">
            <?php if (!empty($item['author'])): ?>
            <span class="flex items-center gap-1">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                <?php echo e($item['author']); ?>
            </span>
            <?php endif; ?>
            <span class="flex items-center gap-1">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                <?php echo number_format((int)($item['views'] ?? 0)); ?>
            </span>
            <span class="ml-auto text-primary opacity-0 group-hover:opacity-100 translate-x-0 group-hover:translate-x-1 transition duration-300 flex items-center gap-1 font-medium">
                <?php echo __('learn_more'); ?>
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
            </span>
        </div>
    </div>
</a>
