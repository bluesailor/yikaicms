<?php
/**
 * 首页区块：关于我们简介
 * 变量：$aboutChannel
 */
$aboutLayout = config('home_about_layout', 'text_left');
$aboutImage = config('home_about_image', 'https://images.unsplash.com/photo-1497366216548-37526070297c?w=800&q=80');
$aboutTagTitle = config('home_about_tag_title', '');
$aboutTagDesc = config('home_about_tag_desc', '');
$bg = getBlockBg($block ?? [], 'bg-white');
// 标题「关于 + 站名」：拼法由语言决定（见 homeAboutDefaultTitle()），
// 但这里要保留「关于」主题色 + 站名本色的配色，所以按 :site 占位切开分别包 span。
$aboutSite = trim((string) configRawLang('site_name', ''));
if ($aboutSite === '') {
    $aboutTitleHtml = '<span class="text-primary">' . e(__('home_about_title')) . '</span>';
} else {
    $aboutParts = array_pad(explode(':site', __('home_about_title_site'), 2), 2, '');
    $aboutTitleHtml = implode(e($aboutSite), array_map(
        static fn (string $seg): string => trim($seg) === ''
            ? $seg                                                        // 纯空白照原样，别把分隔丢了
            : '<span class="text-primary">' . e($seg) . '</span>',
        $aboutParts
    ));
}
?>
<section class="py-16 <?php echo $bg['class']; ?>" <?php echo $bg['style']; ?>>
    <?php echo $bg['overlay']; ?>
    <div class="<?php echo $bg['container']; ?> <?php echo $bg['content']; ?>">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
            <?php if ($aboutLayout === 'image_left'): ?>
            <!-- 左图右文 -->
            <div class="relative" data-animate="fade-right">
                <img loading="lazy" src="<?php echo e($aboutImage); ?>" alt="<?php echo __('home_about_title'); ?>" class="rounded-lg shadow-lg w-full">
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
            <div data-animate="fade-left">
                <h2 class="text-3xl font-bold text-dark mb-2">
                    <?php echo $aboutTitleHtml; ?>
                </h2>
                <?php echo homeTitleDeco(false, 'st-left'); ?>
                <p class="text-gray-600 leading-relaxed mb-6 mt-6">
                    <?php echo e(configLang('home_about_content', 'home_about_default')); ?>
                </p>
                <?php if ($aboutChannel): ?>
                <a href="<?php echo channelUrl($aboutChannel); ?>" class="inline-block bg-primary hover:bg-secondary text-white px-6 py-3 rounded-full transition">
                    <?php echo __('home_learn_more'); ?> &raquo;
                </a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <!-- 左文右图（默认） -->
            <div data-animate="fade-right">
                <h2 class="text-3xl font-bold text-dark mb-2">
                    <?php echo $aboutTitleHtml; ?>
                </h2>
                <?php echo homeTitleDeco(false, 'st-left'); ?>
                <p class="text-gray-600 leading-relaxed mb-6 mt-6">
                    <?php echo e(configLang('home_about_content', 'home_about_default')); ?>
                </p>
                <?php if ($aboutChannel): ?>
                <a href="<?php echo channelUrl($aboutChannel); ?>" class="inline-block bg-primary hover:bg-secondary text-white px-6 py-3 rounded-full transition">
                    <?php echo __('home_learn_more'); ?> &raquo;
                </a>
                <?php endif; ?>
            </div>
            <div class="relative" data-animate="fade-left">
                <img loading="lazy" src="<?php echo e($aboutImage); ?>" alt="<?php echo __('home_about_title'); ?>" class="rounded-lg shadow-lg w-full">
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
            <?php endif; ?>
        </div>
    </div>
</section>
