<?php
declare(strict_types=1);
/**
 * Business 主题 - 客户评价
 */
if (empty($testimonials)) return;
$tmTitle = config('home_testimonials_title', '') ?: __('home_testimonials_title');
$tmDesc = config('home_testimonials_desc', '') ?: __('home_testimonials_desc');
require_once dirname(__DIR__) . '/partials/home-surface.php';
$surface = businessHomeSurface($block ?? []);
?>
<section class="py-20 business-surface" <?= $surface['attributes'] ?> <?= $surface['style'] ?>>
    <?= $surface['overlay'] ?>
    <div class="<?= e($surface['container'] . ' ' . $surface['content']) ?>">
        <div class="text-center mb-12" data-animate="fade-up">
            <h2 class="text-3xl font-bold business-title mb-4"><?php echo e($tmTitle); ?></h2>
            <?php echo homeTitleDeco(false, '', '<img src="/themes/business/images/divide.png" alt="" class="mx-auto mb-4">'); ?>
            <p class="business-copy"><?php echo e($tmDesc); ?></p>
        </div>
        <?php $tmSlider = count($testimonials) > 3; ?>
        <div class="<?php echo $tmSlider ? 'testimonials-swiper swiper' : 'grid grid-cols-1 md:grid-cols-3 gap-8'; ?>" data-stagger>
            <?php if ($tmSlider): ?><div class="swiper-wrapper"><?php endif; ?>
            <?php foreach ($testimonials as $tm): ?>
            <?php if ($tmSlider): ?><div class="swiper-slide"><?php endif; ?>
            <div class="business-card shadow-md p-6<?php echo $tmSlider ? ' h-full' : ''; ?>">
                <p class="business-copy text-sm leading-relaxed mb-6">"<?php echo e($tm['content'] ?? ''); ?>"</p>
                <div class="flex items-center">
                    <div class="w-10 h-10 rounded-full bg-primary text-white flex items-center justify-center font-bold mr-3">
                        <?php echo e(mb_substr($tm['name'] ?? '', 0, 1)); ?>
                    </div>
                    <div>
                        <div class="font-bold business-title text-sm"><?php echo e($tm['name'] ?? ''); ?></div>
                        <div class="text-xs business-copy"><?php echo e($tm['company'] ?? ''); ?></div>
                    </div>
                </div>
            </div>
            <?php if ($tmSlider): ?></div><?php endif; ?>
            <?php endforeach; ?>
            <?php if ($tmSlider): ?></div>
            <div class="swiper-pagination"></div>
            <div class="swiper-button-prev"></div>
            <div class="swiper-button-next"></div>
            <?php endif; ?>
        </div>
        <?php if ($tmSlider): ?>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof Swiper === 'undefined') return;
            new Swiper('.testimonials-swiper', { slidesPerView: 1, spaceBetween: 24, loop: true, autoplay: { delay: 4500, disableOnInteraction: false }, pagination: { el: '.testimonials-swiper .swiper-pagination', clickable: true }, navigation: { nextEl: '.testimonials-swiper .swiper-button-next', prevEl: '.testimonials-swiper .swiper-button-prev' }, breakpoints: { 768: { slidesPerView: 2 }, 1024: { slidesPerView: 3 } } });
        });
        </script>
        <?php endif; ?>
    </div>
</section>
