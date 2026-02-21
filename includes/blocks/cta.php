<?php
/**
 * 首页区块：CTA行动号召
 */
$bg = getBlockBg($block ?? [], 'bg-primary text-white');
?>
<section class="py-16 <?php echo $bg['class']; ?>" <?php echo $bg['style']; ?>>
    <?php echo $bg['overlay']; ?>
    <div class="<?php echo $bg['container']; ?> text-center <?php echo $bg['content']; ?>">
        <h2 class="text-3xl font-bold mb-2"><?php echo e(config('home_cta_title', '准备好开始合作了吗？')); ?></h2>
        <span class="section-title-bar section-title-bar-light"></span>
        <p class="text-xl opacity-90 mb-8 mt-4"><?php echo e(config('home_cta_desc', '联系我们，获取专业的解决方案')); ?></p>
        <div class="flex flex-wrap justify-center gap-4">
            <a href="/contact.html" class="bg-white text-primary hover:bg-gray-100 px-8 py-3 rounded-full font-bold transition">
                <?php echo __('detail_consult'); ?>
            </a>
            <?php if ($phone = config('contact_phone')): ?>
            <a href="tel:<?php echo e($phone); ?>" class="border-2 border-white text-white hover:bg-white hover:text-primary px-8 py-3 rounded-full font-bold transition">
                <?php echo __('detail_call'); ?>
            </a>
            <?php endif; ?>
        </div>
    </div>
</section>
