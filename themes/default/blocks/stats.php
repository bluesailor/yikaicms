<?php
/**
 * 首页区块：数据统计横栏
 */
$bg = getBlockBg($block ?? [], '@auto');
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
        <?php $statIconDefaults = ['award', 'users', 'briefcase', 'thumb-up']; ?>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-8 text-center" data-stagger>
            <?php for ($i = 1; $i <= 4; $i++):
                $statIcon = config('home_stat_' . $i . '_icon', $statIconDefaults[$i - 1]);
                $statNum  = config('home_stat_' . $i . '_num', ['10+', '1000+', '50+', '100%'][$i - 1]);
            ?>
            <div>
                <?php if ($statIcon !== '' && $statIcon !== 'none'): ?>
                <i class="ti ti-<?php echo e($statIcon); ?> text-5xl md:text-6xl text-white/90 mb-3 inline-block leading-none"></i>
                <?php endif; ?>
                <div class="text-4xl font-bold text-white mb-2 stat-number" data-count="<?php echo e($statNum); ?>"><?php echo e($statNum); ?></div>
                <div class="text-gray-300"><?php echo e(configLang('home_stat_' . $i . '_text', 'home_stat_' . $i)); ?></div>
            </div>
            <?php endfor; ?>
        </div>
    </div>
</section>
