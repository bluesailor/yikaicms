<?php
/**
 * Minimal 主题 — 客户评价
 *
 * 设计：纯文字引用，无气泡阴影，作者姓名缩写代替头像，细线分隔。
 */
if (empty($testimonials)) return;
$tmTitle = configLang('home_testimonials_title', 'home_testimonials_title');
$tmDesc  = configLang('home_testimonials_desc',  'home_testimonials_desc');
$bg = getBlockBg($block ?? [], 'bg-gray-50');
?>
<section class="py-24 <?php echo $bg['class']; ?>" <?php echo $bg['style']; ?>>
    <?php echo $bg['overlay']; ?>
    <div class="<?php echo $bg['container']; ?> <?php echo $bg['content']; ?> px-6 lg:px-8">
        <div class="mb-16" data-animate="fade-up">
            <h2 class="text-2xl font-light text-gray-900 tracking-wide"><?php echo e($tmTitle); ?></h2>
            <div class="w-12 h-px bg-gray-900 mt-4"></div>
            <?php if ($tmDesc): ?>
            <p class="mt-6 text-gray-500 max-w-xl text-sm leading-relaxed"><?php echo e($tmDesc); ?></p>
            <?php endif; ?>
        </div>

        <?php // >3 条用 Swiper 轮播；≤3 条网格
        $tmSlider = count($testimonials) > 3; ?>
        <div class="<?php echo $tmSlider ? 'testimonials-swiper swiper' : 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-12'; ?>" data-stagger>
            <?php if ($tmSlider): ?><div class="swiper-wrapper"><?php endif; ?>
            <?php foreach ($testimonials as $tm):
                $name    = $tm['name']    ?? '';
                $company = $tm['company'] ?? '';
                $content = $tm['content'] ?? '';
                $initial = mb_substr($name, 0, 1);
            ?>
            <?php if ($tmSlider): ?><div class="swiper-slide"><?php endif; ?>
            <figure class="border-t border-gray-300 pt-8<?php echo $tmSlider ? ' h-full' : ''; ?>">
                <!-- 大引号字符替代图标 -->
                <div class="text-5xl font-serif text-gray-300 leading-none mb-4">&ldquo;</div>
                <blockquote class="text-gray-700 text-base leading-relaxed font-light">
                    <?php echo e($content); ?>
                </blockquote>
                <figcaption class="mt-8 flex items-center gap-3">
                    <div class="w-10 h-10 border border-gray-900 flex items-center justify-center text-sm font-light text-gray-900">
                        <?php echo e($initial); ?>
                    </div>
                    <div>
                        <div class="text-sm text-gray-900"><?php echo e($name); ?></div>
                        <?php if ($company): ?>
                        <div class="text-xs text-gray-400 mt-0.5 tracking-wide"><?php echo e($company); ?></div>
                        <?php endif; ?>
                    </div>
                </figcaption>
            </figure>
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
