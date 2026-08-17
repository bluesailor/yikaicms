<?php
/**
 * Minimal 主题 — 案例卡片
 *
 * 大图 + 极简标注，hover 时图片轻微淡出，无叠层无标签。
 *
 *   @var array $item  ['title','cover','summary']
 */
?>
<a href="<?php echo contentUrl($item); ?>" class="group block">
    <div class="aspect-[4/3] overflow-hidden bg-gray-50">
        <?php if (!empty($item['cover'])): ?>
        <img loading="lazy" src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>"
             alt="<?php echo e($item['title']); ?>"
             class="w-full h-full object-cover transition duration-700
                    group-hover:opacity-80 group-hover:scale-[1.02]">
        <?php else: ?>
        <div class="w-full h-full flex items-center justify-center text-gray-300 text-xs tracking-widest uppercase">
            <?php echo __('admin_no_image'); ?>
        </div>
        <?php endif; ?>
    </div>
    <div class="mt-4 flex items-baseline gap-3">
        <span class="text-xs text-gray-300 font-mono tracking-widest flex-shrink-0">
            <?php echo 'Case'; ?>
        </span>
        <h3 class="text-sm text-gray-700 group-hover:text-gray-900 transition line-clamp-1 flex-1">
            <?php echo e($item['title']); ?>
        </h3>
    </div>
</a>
