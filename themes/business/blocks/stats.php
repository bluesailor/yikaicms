<?php
/**
 * Business 主题 - 统计栏（暗色 + 数字动画）
 */
$_homeFieldAttr = isset($ykHomeFieldAttr) && is_callable($ykHomeFieldAttr)
    ? $ykHomeFieldAttr
    : static fn (string $field): string => '';
?>
<section class="py-12 section-dark">
    <div class="container mx-auto px-4">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-8 text-center" data-stagger>
            <div>
                <div<?php echo $_homeFieldAttr('stats_items.0.number'); ?> class="text-4xl font-bold text-white mb-2 stat-number" data-count="<?php echo e(config('home_stat_1_num', '10+')); ?>"><?php echo e(config('home_stat_1_num', '10+')); ?></div>
                <div<?php echo $_homeFieldAttr('stats_items.0.label'); ?> class="text-gray-400"><?php echo e(config('home_stat_1_text', '') ?: __('home_stat_1')); ?></div>
            </div>
            <div>
                <div<?php echo $_homeFieldAttr('stats_items.1.number'); ?> class="text-4xl font-bold text-white mb-2 stat-number" data-count="<?php echo e(config('home_stat_2_num', '1000+')); ?>"><?php echo e(config('home_stat_2_num', '1000+')); ?></div>
                <div<?php echo $_homeFieldAttr('stats_items.1.label'); ?> class="text-gray-400"><?php echo e(config('home_stat_2_text', '') ?: __('home_stat_2')); ?></div>
            </div>
            <div>
                <div<?php echo $_homeFieldAttr('stats_items.2.number'); ?> class="text-4xl font-bold text-white mb-2 stat-number" data-count="<?php echo e(config('home_stat_3_num', '50+')); ?>"><?php echo e(config('home_stat_3_num', '50+')); ?></div>
                <div<?php echo $_homeFieldAttr('stats_items.2.label'); ?> class="text-gray-400"><?php echo e(config('home_stat_3_text', '') ?: __('home_stat_3')); ?></div>
            </div>
            <div>
                <div<?php echo $_homeFieldAttr('stats_items.3.number'); ?> class="text-4xl font-bold text-white mb-2 stat-number" data-count="<?php echo e(config('home_stat_4_num', '100%')); ?>"><?php echo e(config('home_stat_4_num', '100%')); ?></div>
                <div<?php echo $_homeFieldAttr('stats_items.3.label'); ?> class="text-gray-400"><?php echo e(config('home_stat_4_text', '') ?: __('home_stat_4')); ?></div>
            </div>
        </div>
    </div>
</section>
