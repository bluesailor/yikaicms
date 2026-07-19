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
        <?php
        // 3 条内用网格居中；超过 3 条改水平滚动（可放到 10 条），移动端亦滑动
        $tmScroll  = count($testimonials) > 3;
        $tmWrapCls = $tmScroll
            ? 'flex gap-6 overflow-x-auto snap-x snap-mandatory pb-4 tm-scroll'
            : 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8';
        $tmCardCls = $tmScroll ? ' snap-start shrink-0 w-[86%] sm:w-[380px]' : '';
        ?>
        <div class="<?php echo $tmWrapCls; ?>" data-stagger>
            <?php foreach ($testimonials as $tm): ?>
            <div class="u-card p-6 relative<?php echo $tmCardCls; ?>">
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
            <?php endforeach; ?>
        </div>
    </div>
</section>
