<?php
/**
 * 首页区块：客户评价
 * 变量：$testimonials
 */
if (empty($testimonials)) return;
$tmTitle = config('home_testimonials_title', '客户评价');
$tmDesc = config('home_testimonials_desc', '听听合作伙伴怎么说');
$bg = getBlockBg($block ?? [], 'bg-gray-50');
?>
<section class="py-16 <?php echo $bg['class']; ?>" <?php echo $bg['style']; ?>>
    <?php echo $bg['overlay']; ?>
    <div class="<?php echo $bg['container']; ?> <?php echo $bg['content']; ?>">
        <div class="text-center mb-12">
            <h2 class="text-3xl font-bold text-dark mb-2">
                <span class="text-primary"><?php echo e(mb_substr($tmTitle, 0, 2)); ?></span><?php echo e(mb_substr($tmTitle, 2)); ?>
            </h2>
            <span class="section-title-bar"></span>
            <p class="text-gray-500 mt-4"><?php echo e($tmDesc); ?></p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            <?php foreach ($testimonials as $tm): ?>
            <div class="bg-white rounded-lg shadow-md p-6 relative">
                <div class="absolute top-4 right-4 text-primary opacity-10">
                    <svg class="w-10 h-10" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M14.017 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151c-2.432.917-3.995 3.638-3.995 5.849h4v10H14.017zM0 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151C7.546 6.068 5.983 8.789 5.983 11H10v10H0z"></path>
                    </svg>
                </div>
                <p class="text-gray-600 text-sm leading-relaxed mb-6"><?php echo e($tm['content'] ?? ''); ?></p>
                <div class="flex items-center">
                    <?php if (!empty($tm['avatar'])): ?>
                    <img src="<?php echo e($tm['avatar']); ?>" alt="<?php echo e($tm['name'] ?? ''); ?>" class="w-12 h-12 rounded-full object-cover mr-4">
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
