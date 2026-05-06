<?php
/**
 * Business 主题 - 关于我们（白底，左图右文）
 */
$aboutImage = config('home_about_image', 'https://images.unsplash.com/photo-1497366216548-37526070297c?w=800&q=80');
$aboutContent = config('home_about_content', '') ?: __('home_about_default');
?>
<section class="py-20 bg-white">
    <div class="container mx-auto px-4">
        <div class="text-center mb-12" data-animate="fade-up">
            <h2 class="text-3xl font-bold text-dark mb-4"><?php echo __('home_about_title'); ?><?php echo e(config('site_name', '')); ?></h2>
            <img src="/themes/business/images/divide.png" alt="" class="mx-auto">
        </div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
            <div data-animate="fade-right">
                <img loading="lazy" src="<?php echo e($aboutImage); ?>" alt="<?php echo __('home_about_title'); ?>" class="rounded-lg shadow-lg w-full">
            </div>
            <div data-animate="fade-left">
                <p class="text-gray-600 leading-relaxed mb-8 text-base"><?php echo e($aboutContent); ?></p>
                <?php if ($aboutChannel): ?>
                <a href="<?php echo channelUrl($aboutChannel); ?>" class="inline-block bg-primary hover:bg-secondary text-white px-6 py-3 rounded-full transition font-medium">
                    <?php echo __('home_learn_more'); ?> &raquo;
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
