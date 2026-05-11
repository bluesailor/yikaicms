<?php
/**
 * Business 主题 — 案例卡片（深色叠层 hover 揭示）
 *
 *   @var array $item  ['title','cover','summary']
 */
?>
<a href="<?php echo contentUrl($item); ?>"
   class="group block relative bg-slate-900 border border-slate-200 overflow-hidden
          hover:border-primary transition">
    <div class="aspect-[4/3] overflow-hidden">
        <?php if (!empty($item['cover'])): ?>
        <img loading="lazy" src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>"
             alt="<?php echo e($item['title']); ?>"
             class="w-full h-full object-cover transition duration-700 group-hover:scale-110 group-hover:opacity-40">
        <?php else: ?>
        <div class="w-full h-full bg-slate-800 flex items-center justify-center text-slate-500 text-sm">
            <?php echo __('admin_no_image'); ?>
        </div>
        <?php endif; ?>

        <!-- 悬停叠层：底部蓝色滑入条 -->
        <div aria-hidden
             class="absolute inset-x-0 bottom-0 h-1 bg-primary
                    scale-x-0 group-hover:scale-x-100 origin-left transition duration-500"></div>

        <!-- 右上"案例 →" 标记 -->
        <div class="absolute top-0 right-0 bg-slate-900/85 text-white text-[10px] font-bold uppercase tracking-widest px-3 py-1.5
                    opacity-0 group-hover:opacity-100 transition duration-300">
            <?php echo 'CASE'; ?>
        </div>
    </div>

    <div class="px-5 py-4 bg-white">
        <h3 class="font-bold text-slate-900 group-hover:text-primary transition line-clamp-2 leading-snug">
            <?php echo e($item['title']); ?>
        </h3>
        <?php if (!empty($item['summary'])): ?>
        <p class="mt-1.5 text-xs text-slate-500 line-clamp-1">
            <?php echo e($item['summary']); ?>
        </p>
        <?php endif; ?>
    </div>
</a>
