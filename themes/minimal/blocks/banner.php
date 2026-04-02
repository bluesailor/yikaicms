<?php
/**
 * Minimal Theme - Banner Block
 * Variables: $banners
 */
?>
<section class="relative">
    <div class="swiper banner-swiper">
        <div class="swiper-wrapper">
            <?php if (!empty($banners)): ?>
                <?php foreach ($banners as $banner): ?>
                <div class="swiper-slide">
                    <?php if (!empty($banner['image'])): ?>
                        <?php if ($banner['link_url']): ?>
                        <a href="<?php echo e($banner['link_url']); ?>" target="<?php echo e($banner['link_target']); ?>" class="block w-full h-full">
                            <img src="<?php echo e($banner['image']); ?>" alt="<?php echo e($banner['title']); ?>" class="w-full h-full object-cover">
                        </a>
                        <?php else: ?>
                        <img src="<?php echo e($banner['image']); ?>" alt="<?php echo e($banner['title']); ?>" class="w-full h-full object-cover">
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="w-full h-full bg-gray-100"></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="swiper-slide">
                    <div class="w-full h-full bg-gray-100"></div>
                </div>
            <?php endif; ?>
        </div>
        <div class="swiper-pagination"></div>
    </div>
</section>
