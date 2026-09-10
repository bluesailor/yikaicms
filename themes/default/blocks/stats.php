<?php
/**
 * 首页区块：数据统计横栏
 */
$bg = getBlockBg($block ?? [], '');
$statLightDefault = false;
if (!$bg['style'] && !$bg['overlay']) {
    // Only the unconfigured appearance changes; explicit backgrounds retain their contrast.
    $statBgUrl = trim((string) config('home_stat_bg', ''));
    $statBgLiteral = $statBgUrl === '' ? '' : UrlPolicy::cssImageLiteral($statBgUrl);
    $statLightDefault = $statBgLiteral === '';
    $bg = $statBgLiteral === ''
        ? [
            'class'     => 'bg-white relative',
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
$statPalette = $statLightDefault
    ? ['number' => '#17212b', 'icon' => '#647f9f', 'label' => '#56616e', 'divider' => '#e2e7ec']
    : ['number' => '#ffffff', 'icon' => 'rgba(255,255,255,0.9)', 'label' => '#d1d5db', 'divider' => 'rgba(255,255,255,0.25)'];
$statVariables = '';
foreach ($statPalette as $part => $fallback) {
    $color = AbstractElement::cssColor(config('home_stat_' . $part . '_color', '')) ?: $fallback;
    $statVariables .= '--stat-' . $part . ':' . $color . ';';
}
$statDivider = (string) config('home_stat_divider', 'inherit');
$statDividerVisible = $statDivider === 'show' || ($statDivider !== 'hide' && $statLightDefault);
$statVariables .= '--stat-divider-width:' . ($statDividerVisible ? '1px' : '0') . ';';
?>
<style>
.yk-stats .stat-icon { color: var(--stat-icon); }
.yk-stats .stat-number { color: var(--stat-number); }
.yk-stats .stat-label { color: var(--stat-label); }
.yk-stats-light .stat-item { min-width: 0; padding: 8px 12px; }
.yk-stats-light .stat-icon { display: block; font-size: 30px; margin: 0 0 12px; }
.yk-stats-light .stat-number { line-height: 1.2; }
.yk-stats-light .stat-label { margin-top: 8px; }
@media (min-width: 1024px) {
    .yk-stats .stat-item + .stat-item { border-left: var(--stat-divider-width) solid var(--stat-divider); }
}
</style>
<section class="yk-stats py-12 <?php echo $statLightDefault ? 'yk-stats-light ' : ''; ?><?php echo $bg['class']; ?>" <?php echo $bg['style']; ?>>
    <?php echo $bg['overlay']; ?>
    <div class="<?php echo $bg['container']; ?> <?php echo $bg['content']; ?>" style="<?php echo e($statVariables); ?>">
        <?php $statIconDefaults = ['award', 'users', 'briefcase', 'thumb-up']; ?>
        <div class="<?php echo e($statGridClass); ?>" data-stagger<?php echo $statGridEditAttr . $statCounterAttr; ?>>
            <?php for ($i = 1; $i <= 4; $i++):
                $statIcon = config('home_stat_' . $i . '_icon', $statIconDefaults[$i - 1]);
                $statNum  = config('home_stat_' . $i . '_num', ['10+', '1000+', '50+', '100%'][$i - 1]);
                $statCountAttr = $statCounterEnabled
                    ? ' data-count="' . e((string) $statNum) . '"'
                    : '';
            ?>
            <div class="stat-item">
                <?php if ($statIcon !== '' && $statIcon !== 'none'): ?>
                <i<?php echo $_homeFieldAttr('stats_items.' . ($i - 1) . '.icon'); ?> class="<?php echo e(BloxIcon::classes($statIcon, 'award')); ?> stat-icon text-5xl md:text-6xl text-white/90 mb-3 inline-block leading-none"></i>
                <?php endif; ?>
                <div<?php echo $_homeFieldAttr('stats_items.' . ($i - 1) . '.number'); ?> class="text-4xl font-bold text-white mb-2 stat-number"<?php echo $statCountAttr; ?>><?php echo e($statNum); ?></div>
                <div<?php echo $_homeFieldAttr('stats_items.' . ($i - 1) . '.label'); ?> class="stat-label text-gray-300"><?php echo e(configLang('home_stat_' . $i . '_text', 'home_stat_' . $i . '_text')); ?></div>
            </div>
            <?php endfor; ?>
        </div>
    </div>
</section>
