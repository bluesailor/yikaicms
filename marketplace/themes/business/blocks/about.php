<?php
declare(strict_types=1);
/**
 * Business 主题 - 关于我们（交替配色，左图右文）
 */
$aboutImage = config('home_about_image', 'https://images.unsplash.com/photo-1497366216548-37526070297c?w=800&q=80');
$aboutContent = configLang('home_about_content', 'home_about_default');
$aboutTitle = trim((string) (configJsonLang('home_about_title') ?: config('home_about_title', '')));
if ($aboutTitle === '') { $aboutTitle = homeAboutDefaultTitle(); }
require_once dirname(__DIR__) . '/partials/home-surface.php';
$surface = businessHomeSurface($block ?? []);
?>
<section class="py-20 business-surface" <?= $surface['attributes'] ?> <?= $surface['style'] ?>>
    <?= $surface['overlay'] ?>
    <div class="<?= e($surface['container'] . ' ' . $surface['content']) ?>">
        <div class="text-center mb-12" data-animate="fade-up">
            <h2 class="text-3xl font-bold business-title mb-4"><?php echo e($aboutTitle); ?></h2>
            <?php echo homeTitleDeco(false, '', '<img src="/themes/business/images/divide.png" alt="" class="mx-auto">'); ?>
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
            <div data-animate="fade-right">
                <img loading="lazy" src="<?php echo e($aboutImage); ?>" alt="<?php echo __('home_about_title'); ?>" class="rounded-lg shadow-lg w-full">
            </div>
            <div data-animate="fade-left">
                <p class="business-copy leading-relaxed mb-8 text-base"><?php echo e($aboutContent); ?></p>
                <?php if ($aboutChannel): ?>
                <a href="<?php echo e(config('home_about_link', '') ?: channelUrl($aboutChannel)); ?>" class="inline-block bg-primary hover:bg-secondary text-white px-6 py-3 rounded-full transition font-medium">
                    <?php echo e(config('home_about_button', '') ?: __('home_learn_more')); ?> &raquo;
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
