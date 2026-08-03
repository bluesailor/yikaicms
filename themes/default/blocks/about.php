<?php
/**
 * 首页区块：关于我们简介
 * 变量：$aboutChannel
 */
$aboutLayout = config('home_about_layout', 'text_left');
$aboutImage = config('home_about_image', 'https://images.unsplash.com/photo-1497366216548-37526070297c?w=800&q=80');
$aboutTagTitle = configJsonLang('home_about_tag_title') ?: config('home_about_tag_title', '');
$aboutTagDesc = configJsonLang('home_about_tag_desc') ?: config('home_about_tag_desc', '');
// 版块标题：后台可自定义（home_about_title）；留空回退到「关于」+ 站点名称
$aboutTitle = trim((string) (configJsonLang('home_about_title') ?: config('home_about_title', '')));
if ($aboutTitle === '') {
    $aboutTitle = __('home_about_title') . configRawLang('site_name', '');
}
$bg = getBlockBg($block ?? [], '@auto');
?>
<section class="blk <?php echo $bg['class']; ?>" <?php echo $bg['style']; ?>>
    <?php echo $bg['overlay']; ?>
    <div class="<?php echo $bg['container']; ?> <?php echo $bg['content']; ?>">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
            <?php if ($aboutLayout === 'image_left'): ?>
            <!-- Left image, right text. -->
            <div class="relative" data-animate="fade-right">
                <img loading="lazy" src="<?php echo e($aboutImage); ?>" alt="<?php echo __('home_about_title'); ?>" class="u-img w-full">
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
            <?php else: ?>
            <!-- Text on the left, image on the right (default) -->
            <div data-animate="fade-right">
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
            <div class="relative" data-animate="fade-left">
                <img loading="lazy" src="<?php echo e($aboutImage); ?>" alt="<?php echo __('home_about_title'); ?>" class="u-img w-full">
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
