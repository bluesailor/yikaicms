<?php
/**
 * Minimal 主题 — 优势区块
 *
 * 设计语言：编号 01-04，无图标，纤细字重，黑色细线分隔。
 */
$advLangKeys = [
    ['title' => 'home_adv_1_title', 'desc' => 'home_adv_1_desc'],
    ['title' => 'home_adv_2_title', 'desc' => 'home_adv_2_desc'],
    ['title' => 'home_adv_3_title', 'desc' => 'home_adv_3_desc'],
    ['title' => 'home_adv_4_title', 'desc' => 'home_adv_4_desc'],
];
$bg = getBlockBg($block ?? [], 'bg-white');
?>
<section class="py-24 <?php echo $bg['class']; ?>" <?php echo $bg['style']; ?>>
    <?php echo $bg['overlay']; ?>
    <div class="<?php echo $bg['container']; ?> <?php echo $bg['content']; ?> px-6 lg:px-8">
        <div class="mb-16" data-animate="fade-up">
            <h2 class="text-2xl font-light text-gray-900 tracking-wide"><?php echo e(configLang('home_advantage_title') ?: __('home_our_advantage')); ?></h2>
            <div class="w-12 h-px bg-gray-900 mt-4"></div>
            <p class="mt-6 text-gray-500 max-w-xl text-sm leading-relaxed">
                <?php echo e(config('home_advantage_desc', '') ?: __('home_advantage_desc')); ?>
            </p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-px bg-gray-200" data-stagger>
            <?php for ($i = 0; $i < 4; $i++):
                $n = $i + 1;
                $title = config("home_adv_{$n}_title", '') ?: __($advLangKeys[$i]['title']);
                $desc  = config("home_adv_{$n}_desc",  '') ?: __($advLangKeys[$i]['desc']);
            ?>
            <div class="bg-white p-8 lg:p-10 group">
                <div class="text-xs text-gray-300 font-mono mb-6 tracking-widest">
                    0<?php echo $n; ?> /04
                </div>
                <h3 class="text-base text-gray-900 mb-3 group-hover:translate-x-1 transition duration-300">
                    <?php echo e($title); ?>
                </h3>
                <div class="w-6 h-px bg-gray-900 mb-4 group-hover:w-12 transition-all duration-300"></div>
                <p class="text-gray-500 text-sm leading-relaxed">
                    <?php echo e($desc); ?>
                </p>
            </div>
            <?php endfor; ?>
        </div>
    </div>
</section>
