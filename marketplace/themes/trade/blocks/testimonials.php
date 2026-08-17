<?php
/**
 * 首页区块：客户评价
 * 变量：$testimonials
 */
if (empty($testimonials)) return;
$tmTitle = configLang('home_testimonials_title', 'home_testimonials_title');
$tmDesc = configLang('home_testimonials_desc', 'home_testimonials_desc');
$bg = getBlockBg($block ?? [], '@auto');
?>
<section class="blk <?php echo $bg['class']; ?>" <?php echo $bg['style']; ?>>
    <?php echo $bg['overlay']; ?>
    <div class="<?php echo $bg['container']; ?> <?php echo $bg['content']; ?>">
        <div class="text-center mb-12" data-animate="fade-up">
            <h2 class="blk-title mb-2"><?php echo homeTitleInner($tmTitle); ?></h2>
            <?php echo homeTitleDeco(); ?>
            <p class="blk-sub"><?php echo e($tmDesc); ?></p>
        </div>
        <?php // 超过 3 条用 Swiper 轮播（复用 banner 已全站加载的 swiper-bundle）；≤3 条用网格
        $tmSlider = count($testimonials) > 3; ?>
        <div class="<?php echo $tmSlider ? 'testimonials-swiper swiper' : 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8'; ?>" data-stagger>
            <?php if ($tmSlider): ?><div class="swiper-wrapper"><?php endif; ?>
            <?php foreach ($testimonials as $tm): ?>
            <?php if ($tmSlider): ?><div class="swiper-slide"><?php endif; ?>
            <div class="u-card p-6 relative<?php echo $tmSlider ? ' h-full' : ''; ?>">
                <div class="absolute top-4 right-4 text-primary opacity-10">
                    <svg class="w-10 h-10" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M14.017 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151c-2.432.917-3.995 3.638-3.995 5.849h4v10H14.017zM0 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151C7.546 6.068 5.983 8.789 5.983 11H10v10H0z"></path>
                    </svg>
                </div>
                <p class="text-gray-600 text-sm leading-relaxed mb-6"><?php echo e($tm['content'] ?? ''); ?></p>
                <div class="flex items-center">
                    <?php if (!empty($tm['avatar'])): ?>
                    <img loading="lazy" src="<?php echo e($tm['avatar']); ?>" alt="<?php echo e($tm['name'] ?? ''); ?>" class="w-12 h-12 rounded-full object-cover mr-4">
                    <?php else: ?>
                    <div class="w-12 h-12 rounded-full bg-primary text-white flex items-center justify-center text-lg font-bold mr-4">
                        <?php echo e(mb_substr($tm['name'] ?? '', 0, 1)); ?>
                    </div>
                    <?php endif; ?>
                    <div>
                        <h4 class="font-bold text-dark"><?php echo e($tm['name'] ?? ''); ?></h4>
                        <p class="text-sm text-gray-400"><?php echo e($tm['company'] ?? ''); ?></p>
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
            new Swiper('.testimonials-swiper', {
                slidesPerView: 1, spaceBetween: 24, loop: true,
                autoplay: { delay: 4500, disableOnInteraction: false },
                pagination: { el: '.testimonials-swiper .swiper-pagination', clickable: true },
                navigation: { nextEl: '.testimonials-swiper .swiper-button-next', prevEl: '.testimonials-swiper .swiper-button-prev' },
                breakpoints: { 768: { slidesPerView: 2 }, 1024: { slidesPerView: 3 } }
            });
        });
        </script>
        <?php endif; ?>
    </div>
</section>
