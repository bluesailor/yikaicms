<?php
/**
 * 首页区块：数据统计横栏
 */
$bg = getBlockBg($block ?? [], ''); // 数据统计自带深色大图底，不参与斑马交替
if (!$bg['style'] && !$bg['overlay']) {
    $statBgUrl = config('home_stat_bg', 'https://images.unsplash.com/photo-1497215842964-222b430dc094?w=1920&q=80');
    $statBgLiteral = UrlPolicy::cssImageLiteral($statBgUrl);
    $bg = [
        'class'     => 'bg-cover bg-center bg-fixed relative',
        'style'     => $statBgLiteral === '' ? '' : 'style="background-image:' . e($statBgLiteral) . ';"',
        'overlay'   => '<div class="absolute inset-0 bg-black/70"></div>',
        'content'   => 'relative',
        'container' => $bg['container'],
    ];
}
$_homeFieldAttr = isset($ykHomeFieldAttr) && is_callable($ykHomeFieldAttr)
    ? $ykHomeFieldAttr
    : static fn (string $field): string => '';
$statCounterEnabled = (string) config('home_stat_counter_enabled', '1') !== '0';
$statCounterStart = max(0, min(1000000, (int) config('home_stat_counter_start', 0)));
$statCounterDuration = max(0, min(5000, (int) config('home_stat_counter_duration', 0)));
$statCounterAttr = '';
if ($statCounterEnabled) {
    BloxAssetCollector::addScript('/assets/js/blox-counter.js');
    $statCounterAttr = ' data-blox-counter="' . e(json_encode([
        'enabled' => true,
        'start' => $statCounterStart,
        'duration' => $statCounterDuration,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) . '"';
}
$statMobileColumns = (string) config('home_stat_mobile_columns', '2') === '1' ? '1' : '2';
$statTabletColumns = (string) config('home_stat_tablet_columns', '4') === '2' ? '2' : '4';
$statGridClass = match ($statMobileColumns . '_' . $statTabletColumns) {
    '1_2' => 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8 text-center',
    '1_4' => 'grid grid-cols-1 md:grid-cols-4 gap-8 text-center',
    '2_2' => 'grid grid-cols-2 md:grid-cols-2 lg:grid-cols-4 gap-8 text-center',
    default => 'grid grid-cols-2 md:grid-cols-4 gap-8 text-center',
};
$statGridEditAttr = !empty($ykHomeEdit)
    ? ' data-yk-home-stats-columns="' . $statMobileColumns . ':' . $statTabletColumns . ':4"'
    : '';
?>
<section class="py-12 <?php echo $bg['class']; ?>" <?php echo $bg['style']; ?>>
    <?php echo $bg['overlay']; ?>
    <div class="<?php echo $bg['container']; ?> <?php echo $bg['content']; ?>">
        <?php $statIconDefaults = ['award', 'users', 'briefcase', 'thumb-up']; ?>
        <div class="<?php echo e($statGridClass); ?>" data-stagger<?php echo $statGridEditAttr . $statCounterAttr; ?>>
            <?php for ($i = 1; $i <= 4; $i++):
                $statIcon = config('home_stat_' . $i . '_icon', $statIconDefaults[$i - 1]);
                $statNum  = config('home_stat_' . $i . '_num', ['10+', '1000+', '50+', '100%'][$i - 1]);
                $statCountAttr = $statCounterEnabled
                    ? ' data-count="' . e((string) $statNum) . '"'
                    : '';
            ?>
            <div>
                <?php if ($statIcon !== '' && $statIcon !== 'none'): ?>
                <i<?php echo $_homeFieldAttr('stats_items.' . ($i - 1) . '.icon'); ?> class="<?php echo e(BloxIcon::classes($statIcon, 'award')); ?> text-5xl md:text-6xl text-white/90 mb-3 inline-block leading-none"></i>
                <?php endif; ?>
                <div<?php echo $_homeFieldAttr('stats_items.' . ($i - 1) . '.number'); ?> class="text-4xl font-bold text-white mb-2 stat-number"<?php echo $statCountAttr; ?>><?php echo e($statNum); ?></div>
                <div<?php echo $_homeFieldAttr('stats_items.' . ($i - 1) . '.label'); ?> class="text-gray-300"><?php echo e(configLang('home_stat_' . $i . '_text', 'home_stat_' . $i . '_text')); ?></div>
            </div>
            <?php endfor; ?>
        </div>
    </div>
</section>
