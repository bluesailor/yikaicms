<?php
/**
 * Business 主题 - 全屏 Hero Banner
 */
$bannerHeightPC = (int)config('banner_height_pc', 700);
$bannerHeightMobile = (int)config('banner_height_mobile', 400);
?>
<section class="relative" style="height: <?php echo $bannerHeightMobile; ?>px;" id="heroBanner">
    <style>@media(min-width:768px){#heroBanner{height:<?php echo $bannerHeightPC; ?>px!important}}</style>
    <div class="swiper banner-swiper h-full">
        <div class="swiper-wrapper">
            <?php if (!empty($banners)): ?>
            <?php foreach ($banners as $banner): ?>
            <div class="swiper-slide relative">
                <?php if (!empty($banner['image'])): ?>
                <img src="<?php echo e($banner['image']); ?>" alt="<?php echo e($banner['title']); ?>" class="w-full h-full object-cover">
                <?php else: ?>
                <div class="w-full h-full bg-gradient-to-br from-slate-800 to-slate-900"></div>
                <?php endif; ?>
                <div class="absolute inset-0 hero-overlay flex items-center justify-center">
                    <div class="text-center text-white px-4 w-full max-w-4xl">
                        <?php if ($banner['title']): ?>
                        <h1 class="text-4xl md:text-6xl font-bold mb-6 tracking-wide"><?php echo e($banner['title']); ?></h1>
                        <?php endif; ?>
                        <?php if ($banner['subtitle']): ?>
                        <p class="text-lg md:text-xl opacity-80 mb-8 max-w-2xl mx-auto"><?php echo e($banner['subtitle']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($banner['btn1_text'])): ?>
                        <a href="<?php echo e($banner['btn1_url'] ?: '/contact.html'); ?>" class="inline-block bg-primary hover:bg-secondary text-white px-8 py-3 rounded-full text-lg font-medium transition">
                            <?php echo e($banner['btn1_text']); ?>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
            <div class="swiper-slide relative">
                <img src="https://picsum.photos/1920/800?random=1" alt="Banner" class="w-full h-full object-cover">
                <div class="absolute inset-0 hero-overlay flex items-center justify-center">
                    <div class="text-center text-white px-4 w-full max-w-4xl">
                        <h1 class="text-4xl md:text-6xl font-bold mb-6 tracking-wide"><?php echo e($siteName); ?></h1>
                        <p class="text-lg md:text-xl opacity-80 mb-8"><?php echo e(config('site_description', '')); ?></p>
                        <a href="/contact.html" class="inline-block bg-primary hover:bg-secondary text-white px-8 py-3 rounded-full text-lg font-medium transition"><?php echo __('detail_consult'); ?></a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="swiper-pagination"></div>
    </div>
</section>
