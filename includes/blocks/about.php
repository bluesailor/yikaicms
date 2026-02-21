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
?>
<section class="py-16 <?php echo $bg['class']; ?>" <?php echo $bg['style']; ?>>
    <?php echo $bg['overlay']; ?>
    <div class="<?php echo $bg['container']; ?> <?php echo $bg['content']; ?>">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
            <?php if ($aboutLayout === 'image_left'): ?>
            <!-- 左图右文 -->
            <div class="relative">
                <img src="<?php echo e($aboutImage); ?>" alt="关于我们" class="rounded-lg shadow-lg w-full">
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
            <div>
                <h2 class="text-3xl font-bold text-dark mb-2">
                    <span class="text-primary"><?php echo __('home_about_title'); ?></span><?php echo e(config('site_name', '')); ?>
                </h2>
                <span class="section-title-bar" style="margin: 0.75rem 0 0;"></span>
                <p class="text-gray-600 leading-relaxed mb-6 mt-6">
                    <?php echo e(config('home_about_content', config('site_description', '我们是一家专注于企业数字化转型的科技公司，致力于为客户提供优质的产品与服务。经过多年发展，已成为行业内具有影响力的企业之一。'))); ?>
                </p>
                <?php if ($aboutChannel): ?>
                <a href="<?php echo channelUrl($aboutChannel); ?>" class="inline-block bg-primary hover:bg-secondary text-white px-6 py-3 rounded-full transition">
                    <?php echo __('home_learn_more'); ?> &raquo;
                </a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <!-- 左文右图（默认） -->
            <div>
                <h2 class="text-3xl font-bold text-dark mb-2">
                    <span class="text-primary"><?php echo __('home_about_title'); ?></span><?php echo e(config('site_name', '')); ?>
                </h2>
                <span class="section-title-bar" style="margin: 0.75rem 0 0;"></span>
                <p class="text-gray-600 leading-relaxed mb-6 mt-6">
                    <?php echo e(config('home_about_content', config('site_description', '我们是一家专注于企业数字化转型的科技公司，致力于为客户提供优质的产品与服务。经过多年发展，已成为行业内具有影响力的企业之一。'))); ?>
                </p>
                <?php if ($aboutChannel): ?>
                <a href="<?php echo channelUrl($aboutChannel); ?>" class="inline-block bg-primary hover:bg-secondary text-white px-6 py-3 rounded-full transition">
                    <?php echo __('home_learn_more'); ?> &raquo;
                </a>
                <?php endif; ?>
            </div>
            <div class="relative">
                <img src="<?php echo e($aboutImage); ?>" alt="关于我们" class="rounded-lg shadow-lg w-full">
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
