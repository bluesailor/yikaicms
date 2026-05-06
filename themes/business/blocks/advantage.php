<?php
/**
 * Business 主题 - 优势区块
 */
$advIcons = getAdvantageIcons();
$advDefaults = [
    ['icon' => 'check-circle', 'title' => __('home_adv_1_title'), 'desc' => __('home_adv_1_desc')],
    ['icon' => 'academic-cap', 'title' => __('home_adv_2_title'), 'desc' => __('home_adv_2_desc')],
    ['icon' => 'briefcase',    'title' => __('home_adv_3_title'), 'desc' => __('home_adv_3_desc')],
    ['icon' => 'users',        'title' => __('home_adv_4_title'), 'desc' => __('home_adv_4_desc')],
];
?>
<section class="py-20 section-dark">
    <div class="container mx-auto px-4">
        <div class="text-center mb-12" data-animate="fade-up">
            <h2 class="text-3xl font-bold text-white mb-4"><?php echo __('home_our_advantage'); ?></h2>
            <img src="/themes/business/images/divide.png" alt="" class="mx-auto mb-4">
            <p class="text-gray-400"><?php echo e(config('home_advantage_desc', '') ?: __('home_advantage_desc')); ?></p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8" data-stagger>
            <?php for ($i = 0; $i < 4; $i++):
                $n = $i + 1;
                $iconKey = config("home_adv_{$n}_icon", $advDefaults[$i]['icon']);
                $iconSvg = $advIcons[$iconKey]['svg'] ?? $advIcons['check-circle']['svg'];
            ?>
            <div class="text-center p-6 bg-slate-800 rounded-xl hover:bg-slate-700 transition">
                <div class="w-14 h-14 bg-primary rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-7 h-7 text-white" fill="currentColor" viewBox="0 0 20 20"><?php echo $iconSvg; ?></svg>
                </div>
                <h3 class="text-lg font-bold text-white mb-2"><?php echo e(config("home_adv_{$n}_title", $advDefaults[$i]['title'])); ?></h3>
                <p class="text-gray-400 text-sm"><?php echo e(config("home_adv_{$n}_desc", $advDefaults[$i]['desc'])); ?></p>
            </div>
            <?php endfor; ?>
        </div>
    </div>
</section>
