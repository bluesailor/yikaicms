<?php
/**
 * Business 主题 - 客户评价
 */
if (empty($testimonials)) return;
$tmTitle = config('home_testimonials_title', '') ?: __('home_testimonials_title');
$tmDesc = config('home_testimonials_desc', '') ?: __('home_testimonials_desc');
?>
<section class="py-20 bg-gray-50">
    <div class="container mx-auto px-4">
        <div class="text-center mb-12" data-animate="fade-up">
            <h2 class="text-3xl font-bold text-dark mb-4"><?php echo e($tmTitle); ?></h2>
            <img src="/themes/business/images/divide.png" alt="" class="mx-auto mb-4">
            <p class="text-gray-500"><?php echo e($tmDesc); ?></p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8" data-stagger>
            <?php foreach ($testimonials as $tm): ?>
            <div class="bg-white rounded-xl shadow-md p-6 border border-gray-100">
                <p class="text-gray-600 text-sm leading-relaxed mb-6">"<?php echo e($tm['content'] ?? ''); ?>"</p>
                <div class="flex items-center">
                    <div class="w-10 h-10 rounded-full bg-primary text-white flex items-center justify-center font-bold mr-3">
                        <?php echo e(mb_substr($tm['name'] ?? '', 0, 1)); ?>
                    </div>
                    <div>
                        <div class="font-bold text-dark text-sm"><?php echo e($tm['name'] ?? ''); ?></div>
                        <div class="text-xs text-gray-400"><?php echo e($tm['company'] ?? ''); ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>
