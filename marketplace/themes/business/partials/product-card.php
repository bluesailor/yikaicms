<?php
/**
 * Business 主题 — 产品卡片（白底蓝边 + 信息密集）
 *
 *   @var array $item            ['title','cover','model','price','is_new','is_hot','is_recommend']
 *   @var bool  $isProductType   是否当前 channel 类型为 product（决定走 productUrl 还是 contentUrl）
 */
?>
<a href="<?php echo $isProductType ? productUrl($item) : contentUrl($item); ?>"
   class="group block bg-white border border-slate-200 hover:border-primary
          hover:shadow-[0_10px_30px_-10px_rgba(59,108,245,0.4)]
          transition relative overflow-hidden">
    <div class="aspect-[4/3] overflow-hidden relative bg-slate-50">
        <?php if (!empty($item['cover'])): ?>
        <img loading="lazy" src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>"
             alt="<?php echo e($item['title']); ?>"
             class="w-full h-full object-cover group-hover:scale-110 transition duration-500">
        <?php else: ?>
        <div class="w-full h-full bg-slate-100 flex items-center justify-center text-slate-400 text-sm">
            <?php echo __('admin_no_image'); ?>
        </div>
        <?php endif; ?>

        <?php if ($isProductType && (!empty($item['is_new']) || !empty($item['is_hot']) || !empty($item['is_recommend']))): ?>
        <div class="absolute top-2 left-2 flex flex-col gap-1">
            <?php if (!empty($item['is_new'])): ?>
            <span class="bg-emerald-600 text-white text-[10px] font-bold px-2 py-0.5 uppercase tracking-wider">NEW</span>
            <?php endif; ?>
            <?php if (!empty($item['is_hot'])): ?>
            <span class="bg-rose-600 text-white text-[10px] font-bold px-2 py-0.5 uppercase tracking-wider">HOT</span>
            <?php endif; ?>
            <?php if (!empty($item['is_recommend'])): ?>
            <span class="bg-primary text-white text-[10px] font-bold px-2 py-0.5 uppercase tracking-wider"><?php echo 'TOP'; ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- hover 时底部蓝色滑入条 -->
        <div aria-hidden
             class="absolute inset-x-0 bottom-0 h-0.5 bg-primary
                    scale-x-0 group-hover:scale-x-100 origin-left transition duration-500"></div>
    </div>

    <div class="px-5 py-4 border-t border-slate-100">
        <h3 class="font-bold text-slate-900 group-hover:text-primary transition line-clamp-2 leading-snug min-h-[3rem]">
            <?php echo e($item['title']); ?>
        </h3>

        <?php if ($isProductType && !empty($item['model'])): ?>
        <p class="mt-1 text-xs text-slate-400 font-mono tracking-wide">
            <?php echo e(__('detail_model')); ?>:
            <span class="text-slate-600"><?php echo e($item['model']); ?></span>
        </p>
        <?php endif; ?>

        <?php if (config('show_price', '0') === '1' && $isProductType && !empty($item['price']) && (float)$item['price'] > 0): ?>
        <div class="mt-3 pt-3 border-t border-dashed border-slate-200 flex items-baseline gap-1">
            <span class="text-xs text-slate-400"><?php echo e(__('currency_symbol')); ?></span>
            <span class="text-primary font-bold text-lg tracking-tight">
                <?php echo number_format((float)$item['price'], max(0, min(4, (int) __('currency_decimals')))); ?>
            </span>
        </div>
        <?php endif; ?>
    </div>
</a>
