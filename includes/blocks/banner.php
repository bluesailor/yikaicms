<?php
/**
 * 首页区块：Banner轮播图
 * 变量：$banners
 */
HomeBannerItemElement::registerRuntimeAssets();
?>
<section class="relative">
    <div class="swiper banner-swiper"<?php echo HomeBloxBlockSchema::bannerRuntimeAttributes($block ?? []); ?>>
        <div class="swiper-wrapper">
            <?php if (!empty($banners)): ?>
                <?php foreach ($banners as $banner): ?>
                <div class="swiper-slide"<?php echo HomeBannerItemElement::motionAttributes($banner); ?>>
                    <?php if (!empty($banner['image']) || (($banner['media_type'] ?? '') === 'video' && !empty($banner['video']))): ?>
                        <?php echo HomeBannerItemElement::responsiveLinkedMediaHtml($banner); ?>
                    <?php else: ?>
                        <div class="w-full h-full bg-gradient-to-r from-gray-800 via-gray-700 to-gray-900"></div>
                    <?php endif; ?>
                    <?php if ($banner['title']): ?>
                    <div class="absolute inset-0 flex items-center justify-center bg-black/30 pointer-events-none">
                        <div class="text-center text-white px-4 w-full max-w-4xl">
                            <h2 class="text-3xl md:text-5xl font-bold mb-4" data-blox-layer style="--blox-layer-order:0"><?php echo e($banner['title']); ?></h2>
                            <?php if ($banner['subtitle']): ?>
                            <p class="text-lg md:text-2xl" data-blox-layer style="--blox-layer-order:1"><?php echo e($banner['subtitle']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($banner['btn1_text']) || !empty($banner['btn2_text'])): ?>
                            <div class="flex flex-wrap justify-center gap-4 mt-6 pointer-events-auto" data-blox-layer style="--blox-layer-order:2">
                                <?php if (!empty($banner['btn1_text'])): ?>
                                <a href="<?php echo e(safeUrl((string) $banner['btn1_url']) ?: '#'); ?>" class="bg-white text-gray-800 hover:bg-gray-100 px-8 py-3 rounded-full text-lg font-semibold transition"><?php echo e($banner['btn1_text']); ?></a>
                                <?php endif; ?>
                                <?php if (!empty($banner['btn2_text'])): ?>
                                <a href="<?php echo e(safeUrl((string) $banner['btn2_url']) ?: '#'); ?>" class="border-2 border-white text-white hover:bg-white/20 px-8 py-3 rounded-full text-lg font-semibold transition"><?php echo e($banner['btn2_text']); ?></a>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="swiper-slide">
                    <img src="/assets/images/demo/banner-1.svg" alt="Banner 1" class="w-full h-full object-cover" data-blox-banner-bg>
                    <div class="absolute inset-0 flex items-center justify-center bg-black/30 pointer-events-none">
                        <div class="text-center text-white px-4 w-full max-w-4xl">
                            <h2 class="text-3xl md:text-5xl font-bold mb-4" data-blox-layer style="--blox-layer-order:0"><?php echo e(configRawLang('site_name', 'Yikai CMS')); ?></h2>
                            <p class="text-lg md:text-2xl mb-6" data-blox-layer style="--blox-layer-order:1"><?php echo e(configLang('site_description', 'quality_service_desc')); ?></p>
                            <a href="/contact.html" class="inline-block bg-white text-gray-800 hover:bg-gray-100 px-8 py-3 rounded-full text-lg font-semibold transition pointer-events-auto" data-blox-layer style="--blox-layer-order:2"><?php echo __('nav_contact'); ?></a>
                        </div>
                    </div>
                </div>
                <div class="swiper-slide">
                    <img src="/assets/images/demo/banner-2.svg" alt="Banner 2" class="w-full h-full object-cover" data-blox-banner-bg>
                    <div class="absolute inset-0 flex items-center justify-center bg-black/30 pointer-events-none">
                        <div class="text-center text-white px-4 w-full max-w-4xl">
                            <h2 class="text-3xl md:text-5xl font-bold mb-4" data-blox-layer style="--blox-layer-order:0"><?php echo __('quality_service'); ?></h2>
                            <p class="text-lg md:text-2xl mb-6" data-blox-layer style="--blox-layer-order:1"><?php echo __('quality_service_desc'); ?></p>
                        </div>
                    </div>
                </div>
                <div class="swiper-slide">
                    <img src="/assets/images/demo/banner-3.svg" alt="Banner 3" class="w-full h-full object-cover" data-blox-banner-bg>
                    <div class="absolute inset-0 flex items-center justify-center bg-black/30 pointer-events-none">
                        <div class="text-center text-white px-4 w-full max-w-4xl">
                            <h2 class="text-3xl md:text-5xl font-bold mb-4" data-blox-layer style="--blox-layer-order:0"><?php echo __('about_us'); ?></h2>
                            <p class="text-lg md:text-2xl mb-6" data-blox-layer style="--blox-layer-order:1"><?php echo __('about_us_desc'); ?></p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <div class="swiper-pagination"></div>
        <div class="swiper-button-prev"></div>
        <div class="swiper-button-next"></div>
    </div>
</section>
