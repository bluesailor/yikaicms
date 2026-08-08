<?php
/**
 * Aurora Theme - Product Card
 *
 * @var array $item - title, cover, is_new, is_hot, is_recommend, model, price
 * @var bool $isProductType
 */
?>
<a href="<?php echo $isProductType ? productUrl($item) : contentUrl($item); ?>"
   class="group relative block rounded-xl overflow-hidden aurora-glass aurora-glass-hover transition">
    <!-- Hover 渐变光边 -->
    <div class="absolute -inset-px rounded-xl bg-gradient-to-r from-indigo-500/0 via-purple-500/0 to-pink-500/0 group-hover:from-indigo-500/40 group-hover:via-purple-500/40 group-hover:to-pink-500/40 transition-all duration-500 opacity-0 group-hover:opacity-100 -z-10 blur-md"></div>

    <div class="aspect-[4/3] overflow-hidden bg-slate-900 relative">
        <?php if ($item['cover']): ?>
        <img loading="lazy" src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>"
             class="w-full h-full object-cover transition duration-500 group-hover:scale-105">
        <?php else: ?>
        <div class="w-full h-full flex items-center justify-center text-slate-600 text-xs">
            <?php echo __('admin_no_image'); ?>
        </div>
        <?php endif; ?>
        <!-- Tags 浮在图上 -->
        <div class="absolute top-3 left-3 flex flex-wrap gap-1.5">
            <?php if (!empty($item['is_new'])): ?>
            <span class="px-2 py-0.5 rounded-md text-[10px] font-medium uppercase tracking-wider bg-gradient-to-r from-green-500/20 to-emerald-500/20 text-emerald-300 border border-emerald-500/30">New</span>
            <?php endif; ?>
            <?php if (!empty($item['is_hot'])): ?>
            <span class="px-2 py-0.5 rounded-md text-[10px] font-medium uppercase tracking-wider bg-gradient-to-r from-red-500/20 to-pink-500/20 text-pink-300 border border-pink-500/30">Hot</span>
            <?php endif; ?>
            <?php if (!empty($item['is_recommend'])): ?>
            <span class="px-2 py-0.5 rounded-md text-[10px] font-medium uppercase tracking-wider bg-gradient-to-r from-indigo-500/20 to-purple-500/20 text-indigo-300 border border-indigo-500/30">★</span>
            <?php endif; ?>
        </div>
        <!-- Image gradient overlay on hover -->
        <div class="absolute inset-0 bg-gradient-to-t from-slate-950/60 to-transparent opacity-0 group-hover:opacity-100 transition"></div>
    </div>
    <div class="p-5">
        <h3 class="text-base font-medium text-slate-100 group-hover:text-white transition line-clamp-2 leading-snug">
            <?php echo e($item['title']); ?>
        </h3>
        <?php if ($isProductType && !empty($item['model'])): ?>
        <p class="text-xs text-slate-500 mt-1.5 font-mono"><?php echo e($item['model']); ?></p>
        <?php endif; ?>
        <?php if (config('show_price', '0') === '1' && $isProductType && !empty($item['price']) && $item['price'] > 0): ?>
        <div class="mt-3 pt-3 border-t border-slate-800/60 flex items-center justify-between">
            <span class="text-lg font-semibold bg-gradient-to-r from-indigo-400 to-purple-400 bg-clip-text text-transparent"><?php echo formatPrice($item['price']); ?></span>
            <svg class="w-4 h-4 text-slate-600 group-hover:text-indigo-400 group-hover:translate-x-1 transition-all" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3"/></svg>
        </div>
        <?php endif; ?>
    </div>
</a>
