<?php
/**
 * Minimal Theme - About Block
 * Variables: $aboutChannel
 */
$aboutLayout = config('home_about_layout', 'text_left');
$aboutImage = config('home_about_image', 'https://images.unsplash.com/photo-1497366216548-37526070297c?w=800&q=80');
$aboutTagTitle = config('home_about_tag_title', '');
$aboutTagDesc = config('home_about_tag_desc', '');
$bg = getBlockBg($block ?? [], 'bg-white');
?>
<section class="py-20 <?php echo $bg['class']; ?>" <?php echo $bg['style']; ?>>
    <?php echo $bg['overlay']; ?>
    <div class="<?php echo $bg['container']; ?> <?php echo $bg['content']; ?> px-6 lg:px-8">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-16 items-center">
            <?php if ($aboutLayout === 'image_left'): ?>
            <!-- Image Left -->
            <div>
                <img loading="lazy" src="<?php echo e($aboutImage); ?>" alt="<?php echo __('home_about_title'); ?>" class="w-full">
            </div>
            <div>
                <h2 class="text-2xl font-light text-gray-900 tracking-wide"><?php echo __('home_about_title'); ?><?php echo e(config('site_name', '')); ?></h2>
                <div class="w-12 h-px bg-gray-900 mt-4"></div>
                <p class="text-gray-500 leading-relaxed mt-8 text-sm">
                    <?php echo e(config('home_about_content', config('site_description', ''))); ?>
                </p>
                <?php if ($aboutChannel): ?>
                <a href="<?php echo channelUrl($aboutChannel); ?>" class="inline-block mt-8 text-sm tracking-wide text-gray-500 border-b border-gray-300 pb-1 hover:text-gray-900 hover:border-gray-900 transition">
                    <?php echo __('home_learn_more'); ?> &rarr;
                </a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <!-- Text Left (default) -->
            <div>
                <h2 class="text-2xl font-light text-gray-900 tracking-wide"><?php echo __('home_about_title'); ?><?php echo e(config('site_name', '')); ?></h2>
                <div class="w-12 h-px bg-gray-900 mt-4"></div>
                <p class="text-gray-500 leading-relaxed mt-8 text-sm">
                    <?php echo e(config('home_about_content', config('site_description', ''))); ?>
                </p>
                <?php if ($aboutChannel): ?>
                <a href="<?php echo channelUrl($aboutChannel); ?>" class="inline-block mt-8 text-sm tracking-wide text-gray-500 border-b border-gray-300 pb-1 hover:text-gray-900 hover:border-gray-900 transition">
                    <?php echo __('home_learn_more'); ?> &rarr;
                </a>
                <?php endif; ?>
            </div>
            <div>
                <img loading="lazy" src="<?php echo e($aboutImage); ?>" alt="<?php echo __('home_about_title'); ?>" class="w-full">
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>
