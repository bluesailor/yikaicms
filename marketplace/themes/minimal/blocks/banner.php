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
                    <?php if (!empty($banner['title']) || !empty($banner['subtitle']) || !empty($banner['btn1_text']) || !empty($banner['btn2_text'])): ?>
                    <div class="absolute inset-0 flex items-center bg-white/70 pointer-events-none" data-blox-banner-content<?php echo class_exists('BannerContentLayout') ? BannerContentLayout::attributes($banner ?? [], $block ?? []) : ''; ?>>
                        <div class="w-full max-w-7xl mx-auto px-6 lg:px-10" data-blox-banner-shell>
                            <div class="max-w-2xl text-left" data-blox-banner-box>
                                <?php if (!empty($banner['title'])): ?>
                                <h1 class="text-3xl md:text-5xl font-light text-gray-900 leading-tight mb-4" data-blox-layer style="--blox-layer-order:0"><?php echo e($banner['title']); ?></h1>
                                <?php endif; ?>
                                <?php if (!empty($banner['subtitle'])): ?>
                                <p class="text-base md:text-xl text-gray-700 leading-relaxed max-w-2xl" data-blox-layer style="--blox-layer-order:1"><?php echo e($banner['subtitle']); ?></p>
                                <?php endif; ?>
                                <?php if (!empty($banner['btn1_text']) || !empty($banner['btn2_text'])): ?>
                                <div class="flex flex-wrap gap-3 mt-8 pointer-events-auto" data-blox-banner-buttons data-blox-layer style="--blox-layer-order:2">
                                    <?php if (!empty($banner['btn1_text'])): ?>
                                    <a href="<?php echo e(safeUrl((string) ($banner['btn1_url'] ?? '')) ?: '#'); ?>" class="inline-flex items-center border border-gray-900 bg-gray-900 text-white px-6 py-3 text-sm font-medium transition hover:bg-transparent hover:text-gray-900"><?php echo e($banner['btn1_text']); ?></a>
                                    <?php endif; ?>
                                    <?php if (!empty($banner['btn2_text'])): ?>
                                    <a href="<?php echo e(safeUrl((string) ($banner['btn2_url'] ?? '')) ?: '#'); ?>" class="inline-flex items-center border border-gray-900 text-gray-900 px-6 py-3 text-sm font-medium transition hover:bg-gray-900 hover:text-white"><?php echo e($banner['btn2_text']); ?></a>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
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
