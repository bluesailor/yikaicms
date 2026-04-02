<?php
/**
 * 首页区块：我们的优势
 */
$bg = getBlockBg($block ?? [], 'bg-dark text-white');
$advIcons = getAdvantageIcons();
$advDefaults = [
    ['icon' => 'check-circle', 'title' => '品质保证', 'desc' => '严格把控产品质量，确保每一件产品都符合标准'],
    ['icon' => 'academic-cap', 'title' => '技术领先', 'desc' => '持续研发创新，保持技术的领先优势'],
    ['icon' => 'briefcase',    'title' => '专业服务', 'desc' => '专业团队7x24小时技术支持服务'],
    ['icon' => 'users',        'title' => '合作共赢', 'desc' => '与客户建立长期合作关系，实现互利共赢'],
];
?>
<section class="py-16 <?php echo $bg['class']; ?>" <?php echo $bg['style']; ?>>
    <?php echo $bg['overlay']; ?>
    <div class="<?php echo $bg['container']; ?> <?php echo $bg['content']; ?>">
        <div class="text-center mb-12">
            <h2 class="text-3xl font-bold mb-2"><?php echo __('home_our_advantage'); ?></h2>
            <span class="section-title-bar section-title-bar-light"></span>
            <p class="text-gray-400 mt-4"><?php echo e(config('home_advantage_desc', '专业团队，优质服务，值得信赖')); ?></p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
            <?php for ($i = 0; $i < 4; $i++):
                $n = $i + 1;
                $iconKey = config("home_adv_{$n}_icon", $advDefaults[$i]['icon']);
                $iconSvg = $advIcons[$iconKey]['svg'] ?? $advIcons['check-circle']['svg'];
            ?>
            <div class="text-center p-6">
                <div class="w-16 h-16 bg-primary rounded-full flex items-center justify-center mx-auto mb-4">
                    <svg class="w-8 h-8" fill="currentColor" viewBox="0 0 20 20"><?php echo $iconSvg; ?></svg>
                </div>
                <h3 class="text-xl font-bold mb-2"><?php echo e(config("home_adv_{$n}_title", $advDefaults[$i]['title'])); ?></h3>
                <p class="text-gray-400 text-sm"><?php echo e(config("home_adv_{$n}_desc", $advDefaults[$i]['desc'])); ?></p>
            </div>
            <?php endfor; ?>
        </div>
    </div>
</section>
