<?php
/**
 * 首页区块：数据统计横栏
 */
$bg = getBlockBg($block ?? [], '');
if (!$bg['style'] && !$bg['overlay']) {
    // 默认走实色深底（dark-soft），与页脚/核心优势的 dark 构成同一套深色层次。
    // 旧做法是「随机外链图 + bg-black/70」，图一压就是脏灰，和另外两个深色块各不相同。
    $statBgUrl = trim((string) config('home_stat_bg', ''));
    $statBgLiteral = $statBgUrl === '' ? '' : UrlPolicy::cssImageLiteral($statBgUrl);
    $bg = $statBgLiteral === ''
        ? [
            'class'     => 'bg-dark-soft relative',
            'style'     => '',
            'overlay'   => '',
            'content'   => 'relative',
            'container' => $bg['container'],
        ]
        : [
            'class'     => 'bg-cover bg-center bg-fixed relative',
            'style'     => 'style="background-image:' . e($statBgLiteral) . ';"',
            'overlay'   => '<div class="absolute inset-0 bg-black/70"></div>',
            'content'   => 'relative',
            'container' => $bg['container'],
        ];
}
?>
<section class="py-12 <?php echo $bg['class']; ?>" <?php echo $bg['style']; ?>>
    <?php echo $bg['overlay']; ?>
    <div class="<?php echo $bg['container']; ?> <?php echo $bg['content']; ?>">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-8 text-center" data-stagger>
            <div>
                <div class="text-4xl font-bold text-white mb-2 stat-number" data-count="<?php echo e(config('home_stat_1_num', '10+')); ?>"><?php echo e(config('home_stat_1_num', '10+')); ?></div>
                <div class="text-gray-300"><?php echo e(configLang('home_stat_1_text', 'home_stat_1_text')); ?></div>
            </div>
            <div>
                <div class="text-4xl font-bold text-white mb-2 stat-number" data-count="<?php echo e(config('home_stat_2_num', '1000+')); ?>"><?php echo e(config('home_stat_2_num', '1000+')); ?></div>
                <div class="text-gray-300"><?php echo e(configLang('home_stat_2_text', 'home_stat_2_text')); ?></div>
            </div>
            <div>
                <div class="text-4xl font-bold text-white mb-2 stat-number" data-count="<?php echo e(config('home_stat_3_num', '50+')); ?>"><?php echo e(config('home_stat_3_num', '50+')); ?></div>
                <div class="text-gray-300"><?php echo e(configLang('home_stat_3_text', 'home_stat_3_text')); ?></div>
            </div>
            <div>
                <div class="text-4xl font-bold text-white mb-2 stat-number" data-count="<?php echo e(config('home_stat_4_num', '100%')); ?>"><?php echo e(config('home_stat_4_num', '100%')); ?></div>
                <div class="text-gray-300"><?php echo e(configLang('home_stat_4_text', 'home_stat_4_text')); ?></div>
            </div>
        </div>
    </div>
</section>
