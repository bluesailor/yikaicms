<?php
/**
 * 首页区块：Banner轮播图
 * 变量：$banners
 */
?>
<section class="relative">
    <div class="swiper banner-swiper">
        <div class="swiper-wrapper">
            <?php if (!empty($banners)): ?>
                <?php foreach ($banners as $banner): ?>
                <div class="swiper-slide">
                    <?php if ($banner['link_url']): ?>
                    <a href="<?php echo e($banner['link_url']); ?>" target="<?php echo e($banner['link_target']); ?>" class="block w-full h-full">
                        <img src="<?php echo e($banner['image']); ?>" alt="<?php echo e($banner['title']); ?>" class="w-full h-full object-cover">
                    </a>
                    <?php else: ?>
                    <img src="<?php echo e($banner['image']); ?>" alt="<?php echo e($banner['title']); ?>" class="w-full h-full object-cover">
                    <?php endif; ?>
                    <?php if ($banner['title']): ?>
                    <div class="absolute inset-0 flex items-center justify-center bg-black/30 pointer-events-none">
                        <div class="text-center text-white px-4 w-full max-w-4xl">
                            <h2 class="text-3xl md:text-5xl font-bold mb-4"><?php echo e($banner['title']); ?></h2>
                            <?php if ($banner['subtitle']): ?>
                            <p class="text-lg md:text-2xl"><?php echo e($banner['subtitle']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($banner['btn1_text']) || !empty($banner['btn2_text'])): ?>
                            <div class="flex flex-wrap justify-center gap-4 mt-6 pointer-events-auto">
                                <?php if (!empty($banner['btn1_text'])): ?>
                                <a href="<?php echo e($banner['btn1_url'] ?: '#'); ?>" class="bg-white text-gray-800 hover:bg-gray-100 px-8 py-3 rounded-full text-lg font-semibold transition"><?php echo e($banner['btn1_text']); ?></a>
                                <?php endif; ?>
                                <?php if (!empty($banner['btn2_text'])): ?>
                                <a href="<?php echo e($banner['btn2_url'] ?: '#'); ?>" class="border-2 border-white text-white hover:bg-white/20 px-8 py-3 rounded-full text-lg font-semibold transition"><?php echo e($banner['btn2_text']); ?></a>
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
                    <div class="w-full h-full bg-gradient-to-r from-blue-600 to-blue-800"></div>
                    <div class="absolute inset-0 flex items-center justify-center">
                        <div class="text-center text-white px-4">
                            <h2 class="text-3xl md:text-5xl font-bold mb-4"><?php echo e(config('site_name', 'Yikai CMS')); ?></h2>
                            <p class="text-lg md:text-2xl mb-6"><?php echo e(config('site_description', '专业的企业内容管理系统')); ?></p>
                            <a href="/contact.html" class="inline-block bg-white text-primary hover:bg-gray-100 px-8 py-3 rounded-full text-lg transition"><?php echo __('nav_contact'); ?></a>
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
