<?php
/**
 * Business 主题 - 全屏 Hero Banner
 */
HomeBannerItemElement::registerRuntimeAssets();
?>
<section class="relative">
    <div class="swiper banner-swiper h-full"<?php echo HomeBloxBlockSchema::bannerRuntimeAttributes($block ?? []); ?>>
        <div class="swiper-wrapper">
            <?php if (!empty($banners)): ?>
            <?php $bannerHeadingIndex = 0; foreach ($banners as $banner): ?>
            <div class="swiper-slide relative"<?php echo HomeBannerItemElement::motionAttributes($banner); ?><?php echo !empty($banner['_blox_path']) ? ' data-yk-el="' . e($banner['_blox_path']) . '" data-yk-el-type="home-banner-item"' : ''; ?>>
                <?php if (!empty($banner['image']) || (($banner['media_type'] ?? '') === 'video' && !empty($banner['video']))): ?>
                <?php echo HomeBannerItemElement::responsiveLinkedMediaHtml($banner); ?>
                <?php else: ?>
                <div class="w-full h-full bg-gradient-to-br from-slate-800 to-slate-900"></div>
                <?php endif; ?>
                <div class="absolute inset-0 hero-overlay flex items-center justify-center" data-blox-banner-content<?php echo class_exists('BannerContentLayout') ? BannerContentLayout::attributes($banner ?? [], $block ?? []) : ''; ?>>
                    <div class="text-center text-white px-4 w-full max-w-4xl" data-blox-banner-box>
                        <?php if ($banner['title']): ?>
                        <?php if ($bannerHeadingIndex++ === 0): ?>
                        <h1 class="text-4xl md:text-6xl font-bold mb-6 tracking-wide" data-blox-layer style="--blox-layer-order:0"><?php echo e($banner['title']); ?></h1>
                        <?php else: ?>
                        <h2 class="text-4xl md:text-6xl font-bold mb-6 tracking-wide" data-blox-layer style="--blox-layer-order:0"><?php echo e($banner['title']); ?></h2>
                        <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($banner['subtitle']): ?>
                        <p class="text-lg md:text-xl opacity-80 mb-8 max-w-2xl mx-auto" data-blox-layer style="--blox-layer-order:1"><?php echo e($banner['subtitle']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($banner['btn1_text']) || !empty($banner['btn2_text'])): ?>
                        <div class="flex flex-wrap justify-center gap-4 pointer-events-auto" data-blox-banner-buttons data-blox-layer style="--blox-layer-order:2">
                            <?php if (!empty($banner['btn1_text'])): ?>
                            <a href="<?php echo e(safeUrl((string) ($banner['btn1_url'] ?? '')) ?: BasePath::url('/contact.html')); ?>" class="inline-block bg-primary hover:bg-secondary text-white px-8 py-3 rounded-full text-lg font-medium transition">
                                <?php echo e($banner['btn1_text']); ?>
                            </a>
                            <?php endif; ?>
                            <?php if (!empty($banner['btn2_text'])): ?>
                            <a href="<?php echo e(safeUrl((string) ($banner['btn2_url'] ?? '')) ?: BasePath::url('/about.html')); ?>" class="inline-block border-2 border-white text-white hover:bg-white hover:text-slate-900 px-8 py-3 rounded-full text-lg font-medium transition">
                                <?php echo e($banner['btn2_text']); ?>
                            </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
            <div class="swiper-slide relative">
                <img src="<?php echo e(BasePath::url('/assets/images/demo/banner-1.svg')); ?>" alt="Banner" class="w-full h-full object-cover" data-blox-banner-bg>
                <div class="absolute inset-0 hero-overlay flex items-center justify-center" data-blox-banner-content<?php echo class_exists('BannerContentLayout') ? BannerContentLayout::attributes($banner ?? [], $block ?? []) : ''; ?>>
                    <div class="text-center text-white px-4 w-full max-w-4xl" data-blox-banner-box>
                        <h1 class="text-4xl md:text-6xl font-bold mb-6 tracking-wide" data-blox-layer style="--blox-layer-order:0"><?php echo e($siteName); ?></h1>
                        <p class="text-lg md:text-xl opacity-80 mb-8" data-blox-layer style="--blox-layer-order:1"><?php echo e(config('site_description', '')); ?></p>
                        <div data-blox-banner-buttons><a href="<?php echo e(BasePath::url('/contact.html')); ?>" class="inline-block bg-primary hover:bg-secondary text-white px-8 py-3 rounded-full text-lg font-medium transition" data-blox-layer style="--blox-layer-order:2"><?php echo __('detail_consult'); ?></a></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="swiper-pagination"></div>
    </div>
</section>
