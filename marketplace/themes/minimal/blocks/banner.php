<?php
/**
 * Minimal Theme - Banner Block
 * Variables: $banners
 */
HomeBannerItemElement::registerRuntimeAssets();
?>
<section class="relative">
    <div class="swiper banner-swiper"<?php echo HomeBloxBlockSchema::bannerRuntimeAttributes($block ?? []); ?>>
        <div class="swiper-wrapper">
            <?php if (!empty($banners)): ?>
                <?php foreach ($banners as $banner): ?>
                <div class="swiper-slide"<?php echo HomeBannerItemElement::motionAttributes($banner); ?><?php echo !empty($banner['_blox_path']) ? ' data-yk-el="' . e($banner['_blox_path']) . '" data-yk-el-type="home-banner-item"' : ''; ?>>
                    <?php if (!empty($banner['image']) || (($banner['media_type'] ?? '') === 'video' && !empty($banner['video']))): ?>
                        <?php echo HomeBannerItemElement::responsiveLinkedMediaHtml($banner); ?>
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
