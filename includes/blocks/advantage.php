<?php
/**
 * 首页区块：我们的优势
 */
$bg = getBlockBg($block ?? [], 'bg-dark text-white');
$advIcons = getAdvantageIcons();
// 默认项存语言键——兜底文案随站点语言（对齐 themes/default 版；硬编码中文
// 在英文站会原样漏出，2026-08-09 实测）
$advDefaults = [
    ['icon' => 'check-circle', 'title' => 'home_adv_1_title', 'desc' => 'home_adv_1_desc'],
    ['icon' => 'academic-cap', 'title' => 'home_adv_2_title', 'desc' => 'home_adv_2_desc'],
    ['icon' => 'briefcase',    'title' => 'home_adv_3_title', 'desc' => 'home_adv_3_desc'],
    ['icon' => 'users',        'title' => 'home_adv_4_title', 'desc' => 'home_adv_4_desc'],
];
?>
<section class="py-16 <?php echo $bg['class']; ?>" <?php echo $bg['style']; ?>>
    <?php echo $bg['overlay']; ?>
    <div class="<?php echo $bg['container']; ?> <?php echo $bg['content']; ?>">
        <div class="text-center mb-12" data-animate="fade-up">
            <h2 class="text-3xl font-bold mb-2"><?php echo __('home_our_advantage'); ?></h2>
            <?php echo homeTitleDeco(true); ?>
            <p class="text-gray-400 mt-4"><?php echo e(configLang('home_advantage_desc', 'home_advantage_desc')); ?></p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8" data-stagger>
            <?php for ($i = 0; $i < 4; $i++):
                $n = $i + 1;
                $iconKey = config("home_adv_{$n}_icon", $advDefaults[$i]['icon']);
                $iconSvg = $advIcons[$iconKey]['svg'] ?? $advIcons['check-circle']['svg'];
            ?>
            <div class="text-center p-6">
                <div class="w-16 h-16 bg-primary rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8" fill="currentColor" viewBox="0 0 20 20"><?php echo $iconSvg; ?></svg>
                </div>
                <h3 class="text-xl font-bold mb-2"><?php echo e(configLang("home_adv_{$n}_title", $advDefaults[$i]['title'])); ?></h3>
                <p class="text-gray-400 text-sm"><?php echo e(configLang("home_adv_{$n}_desc", $advDefaults[$i]['desc'])); ?></p>
            </div>
            <?php endfor; ?>
        </div>
    </div>
</section>
