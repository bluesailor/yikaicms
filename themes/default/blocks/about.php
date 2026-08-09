<?php
/**
 * 首页区块：关于我们简介
 * 变量：$aboutChannel
 */
$aboutLayout = config('home_about_layout', 'text_left');
$aboutRatio = (string) config('home_about_ratio', '1_1');
$aboutBreakpoint = (string) config('home_about_breakpoint', 'lg') === 'md' ? 'md' : 'lg';
$aboutGridClasses = [
    'md' => [
        '5_7' => ['grid grid-cols-1 md:grid-cols-12 gap-10 md:gap-14 items-center', 'md:col-span-5', 'md:col-span-7'],
        '7_5' => ['grid grid-cols-1 md:grid-cols-12 gap-10 md:gap-14 items-center', 'md:col-span-7', 'md:col-span-5'],
        '1_2' => ['grid grid-cols-1 md:grid-cols-12 gap-10 md:gap-14 items-center', 'md:col-span-4', 'md:col-span-8'],
        '2_1' => ['grid grid-cols-1 md:grid-cols-12 gap-10 md:gap-14 items-center', 'md:col-span-8', 'md:col-span-4'],
        '1_1' => ['grid grid-cols-1 md:grid-cols-2 gap-10 md:gap-14 items-center', '', ''],
    ],
    'lg' => [
        '5_7' => ['grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-14 items-center', 'lg:col-span-5', 'lg:col-span-7'],
        '7_5' => ['grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-14 items-center', 'lg:col-span-7', 'lg:col-span-5'],
        '1_2' => ['grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-14 items-center', 'lg:col-span-4', 'lg:col-span-8'],
        '2_1' => ['grid grid-cols-1 lg:grid-cols-12 gap-10 lg:gap-14 items-center', 'lg:col-span-8', 'lg:col-span-4'],
        '1_1' => ['grid grid-cols-1 lg:grid-cols-2 gap-10 lg:gap-14 items-center', '', ''],
    ],
];
[$aboutGridClass, $aboutTextSpanClass, $aboutImageSpanClass] =
    $aboutGridClasses[$aboutBreakpoint][$aboutRatio] ?? $aboutGridClasses[$aboutBreakpoint]['1_1'];
$aboutRatioSpans = match ($aboutRatio) {
    '5_7' => ['5/12', '7/12'],
    '7_5' => ['7/12', '5/12'],
    '1_2' => ['4/12', '8/12'],
    '2_1' => ['8/12', '4/12'],
    default => ['1/2', '1/2'],
};
$aboutRatioLabel = match ($aboutRatio) {
    '5_7' => '5:7',
    '7_5' => '7:5',
    '1_2' => '1:2',
    '2_1' => '2:1',
    default => '1:1',
};
$aboutIsImageLeft = $aboutLayout === 'image_left';
$aboutTextOrder = $aboutIsImageLeft ? 2 : 1;
$aboutImageOrder = $aboutIsImageLeft ? 1 : 2;
$aboutTextResponsiveClass = $aboutBreakpoint === 'md'
    ? ($aboutIsImageLeft ? 'order-2 md:border-l md:border-gray-200 md:pl-10' : 'order-1 md:pr-4')
    : ($aboutIsImageLeft ? 'order-2 lg:border-l lg:border-gray-200 lg:pl-10' : 'order-1 lg:pr-4');
$aboutImageResponsiveClass = $aboutBreakpoint === 'md'
    ? ($aboutIsImageLeft ? 'order-1 md:pr-4' : 'order-2 md:border-l md:border-gray-200 md:pl-10')
    : ($aboutIsImageLeft ? 'order-1 lg:pr-4' : 'order-2 lg:border-l lg:border-gray-200 lg:pl-10');
$aboutTextClass = trim(implode(' ', array_filter([
    $aboutTextSpanClass,
    $aboutTextResponsiveClass,
    $aboutBreakpoint === 'md' ? 'py-4 md:py-8' : 'py-4 lg:py-8',
])));
$aboutImageClass = trim(implode(' ', array_filter([
    $aboutImageSpanClass,
    $aboutImageResponsiveClass,
])));
$aboutEditMode = !empty($ykHomeEdit);
$aboutEditPath = $aboutEditMode ? trim((string) ($ykHomePath ?? '')) : '';
$aboutColumnPathAttr = $aboutEditPath !== ''
    ? ' data-yk-home-path="' . e($aboutEditPath) . '"'
    : '';
$aboutBreakpointLabel = $aboutBreakpoint === 'md' ? '平板两栏' : '平板单列';
$aboutGridEditAttr = $aboutEditMode
    ? ' data-yk-home-columns="2" data-yk-home-breakpoint="' . e($aboutBreakpoint) . '" data-yk-home-layout-label="' . e(__('blox_home_about_two_columns') . ' · ' . $aboutRatioLabel . ' · ' . $aboutBreakpointLabel) . '"'
    : '';
$aboutTextEditAttr = $aboutEditMode
    ? ' data-yk-home-column="text" data-yk-home-column-label="' . e($aboutTextOrder . ' · ' . __('blox_home_about_text_column') . ' · ' . $aboutRatioSpans[0]) . '"' . $aboutColumnPathAttr
    : '';
$aboutImageEditAttr = $aboutEditMode
    ? ' data-yk-home-column="image" data-yk-home-column-label="' . e($aboutImageOrder . ' · ' . __('blox_home_about_image_column') . ' · ' . $aboutRatioSpans[1]) . '"' . $aboutColumnPathAttr
    : '';
$aboutImage = config('home_about_image', 'https://images.unsplash.com/photo-1497366216548-37526070297c?w=800&q=80');
$aboutTagTitle = configJsonLang('home_about_tag_title') ?: config('home_about_tag_title', '');
$aboutTagDesc = configJsonLang('home_about_tag_desc') ?: config('home_about_tag_desc', '');
// 版块标题：后台可自定义（home_about_title）；留空回退到「关于」+ 站点名称
$aboutTitle = trim((string) (configJsonLang('home_about_title') ?: config('home_about_title', '')));
if ($aboutTitle === '') {
    $aboutTitle = homeAboutDefaultTitle();
}
$bg = getBlockBg($block ?? [], '@auto');
?>
<section class="blk <?php echo $bg['class']; ?>" <?php echo $bg['style']; ?>>
    <?php echo $bg['overlay']; ?>
    <div class="<?php echo $bg['container']; ?> <?php echo $bg['content']; ?>">
        <div class="<?php echo e($aboutGridClass); ?>"<?php echo $aboutGridEditAttr; ?>>
            <div class="<?php echo e($aboutTextClass); ?>"<?php echo $aboutTextEditAttr; ?> data-animate="<?php echo $aboutIsImageLeft ? 'fade-left' : 'fade-right'; ?>">
                <h2 class="blk-title mb-2"><?php echo homeTitleInner($aboutTitle); ?></h2>
                <?php echo homeTitleDeco(false, 'st-left'); ?>
                <p class="text-gray-600 text-lg leading-relaxed mb-6 mt-6">
                    <?php echo e(configLang('home_about_content', 'home_about_default')); ?>
                </p>
                <?php if ($aboutChannel): ?>
                <a href="<?php echo e(config('home_about_link', '') ?: channelUrl($aboutChannel)); ?>" class="u-btn-primary inline-block bg-primary hover:bg-secondary text-white px-6 py-3 rounded-full transition">
                    <?php echo e(config('home_about_button', '') ?: __('home_learn_more')); ?> &raquo;
                </a>
                <?php endif; ?>
            </div>
            <div class="<?php echo e($aboutImageClass); ?>"<?php echo $aboutImageEditAttr; ?> data-animate="<?php echo $aboutIsImageLeft ? 'fade-right' : 'fade-left'; ?>">
                <div class="relative overflow-hidden rounded-lg bg-gray-100 shadow-sm aspect-[4/3]">
                    <img loading="lazy" src="<?php echo e($aboutImage); ?>" alt="<?php echo __('home_about_title'); ?>" class="u-img w-full h-full object-cover">
                    <?php if ($aboutTagTitle || $aboutTagDesc): ?>
                    <div class="absolute bottom-4 left-4 bg-primary text-white px-4 py-3 rounded-lg shadow-lg">
                        <?php if ($aboutTagTitle): ?>
                        <div class="font-bold text-lg"><?php echo e($aboutTagTitle); ?></div>
                        <?php endif; ?>
                        <?php if ($aboutTagDesc): ?>
                        <div class="text-sm opacity-90"><?php echo e($aboutTagDesc); ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>
