<?php
/**
 * 首页区块：数据统计横栏
 */
$bg = getBlockBg($block ?? [], '');
if (!$bg['style'] && !$bg['overlay']) {
    $statBgUrl = config('home_stat_bg', 'https://images.unsplash.com/photo-1497215842964-222b430dc094?w=1920&q=80');
    $bg = [
        'class'     => 'bg-cover bg-center bg-fixed relative',
        'style'     => 'style="background-image:url(\'' . e($statBgUrl) . '\');"',
        'overlay'   => '<div class="absolute inset-0 bg-black/70"></div>',
        'content'   => 'relative',
        'container' => $bg['container'],
    ];
}
?>
<section class="py-12 <?php echo $bg['class']; ?>" <?php echo $bg['style']; ?>>
    <?php echo $bg['overlay']; ?>
    <div class="<?php echo $bg['container']; ?> <?php echo $bg['content']; ?>">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-8 text-center">
            <div>
                <div class="text-4xl font-bold text-white mb-2"><?php echo e(config('home_stat_1_num', '10+')); ?></div>
                <div class="text-gray-300"><?php echo e(config('home_stat_1_text', '年行业经验')); ?></div>
            </div>
            <div>
                <div class="text-4xl font-bold text-white mb-2"><?php echo e(config('home_stat_2_num', '1000+')); ?></div>
                <div class="text-gray-300"><?php echo e(config('home_stat_2_text', '服务客户')); ?></div>
            </div>
            <div>
                <div class="text-4xl font-bold text-white mb-2"><?php echo e(config('home_stat_3_num', '50+')); ?></div>
                <div class="text-gray-300"><?php echo e(config('home_stat_3_text', '专业团队')); ?></div>
            </div>
            <div>
                <div class="text-4xl font-bold text-white mb-2"><?php echo e(config('home_stat_4_num', '100%')); ?></div>
                <div class="text-gray-300"><?php echo e(config('home_stat_4_text', '客户满意')); ?></div>
            </div>
        </div>
    </div>
</section>
