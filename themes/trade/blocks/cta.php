<?php
/**
 * 首页区块：CTA行动号召
 */
$bg = getBlockBg($block ?? [], 'bg-primary text-white');
?>
<section class="blk <?php echo $bg['class']; ?>" <?php echo $bg['style']; ?>>
    <?php echo $bg['overlay']; ?>
    <div class="<?php echo $bg['container']; ?> text-center <?php echo $bg['content']; ?>" data-animate="fade-up">
        <h2 class="blk-title blk-title--light mb-2"><?php echo e(configLang('home_cta_title', 'home_cta_title')); ?></h2>
        <?php echo homeTitleDeco(true); ?>
        <p class="text-xl opacity-90 mb-8 mt-4"><?php echo e(configLang('home_cta_desc', 'home_cta_desc')); ?></p>
        <div class="flex flex-wrap justify-center gap-4">
            <a href="/contact.html" class="bg-white text-primary hover:bg-gray-100 px-8 py-3 rounded-full font-bold shadow-lg transition">
                <?php echo __('detail_consult'); ?>
            </a>
            <?php if ($phone = configRawLang('contact_phone')): ?>
            <a href="tel:<?php echo e($phone); ?>" class="border-2 border-white text-white hover:bg-white hover:text-primary px-8 py-3 rounded-full font-bold transition">
                <?php echo __('detail_call'); ?>
            </a>
            <?php endif; ?>
        </div>
    </div>
</section>
