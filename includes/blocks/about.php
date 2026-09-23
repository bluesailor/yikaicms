<?php
/**
 * 首页区块：关于我们简介
 * 变量：$aboutChannel
 */
$aboutLayout = config('home_about_layout', 'text_left');
$aboutImage = (string) config('home_about_image', '/assets/images/demo/yikaicms-industrial-600.webp');
$aboutTagTitle = config('home_about_tag_title', '');
$aboutTagDesc = config('home_about_tag_desc', '');
$bg = getBlockBg($block ?? [], 'bg-white');
// 标题顺序由语言决定，整段文字沿用标题颜色。
$aboutSite = trim((string) configRawLang('site_name', ''));
if ($aboutSite === '') {
    $aboutTitleHtml = e(__('home_about_title'));
} else {
    $aboutTitleHtml = e(str_replace(':site', $aboutSite, __('home_about_title_site')));
}
?>
<section class="py-16 <?php echo $bg['class']; ?>" <?php echo $bg['style']; ?>>
    <?php echo $bg['overlay']; ?>
    <div class="<?php echo $bg['container']; ?> <?php echo $bg['content']; ?>">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
            <?php if ($aboutLayout === 'image_left'): ?>
            <?php /* 左图右文 */ ?>
            <div class="relative" data-animate="fade-right">
                <img loading="lazy" decoding="async" <?php echo responsiveImageAttributes($aboutImage, 'medium', '(min-width: 1024px) 50vw, 100vw'); ?> alt="<?php echo __('home_about_title'); ?>" class="rounded-lg shadow-lg w-full">
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
            <?php /* 左文右图（默认） */ ?>
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
                <img loading="lazy" decoding="async" <?php echo responsiveImageAttributes($aboutImage, 'medium', '(min-width: 1024px) 50vw, 100vw'); ?> alt="<?php echo __('home_about_title'); ?>" class="rounded-lg shadow-lg w-full">
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
