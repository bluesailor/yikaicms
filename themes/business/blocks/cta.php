<?php
/**
 * Business 主题 - CTA 行动号召。
 *
 * 旧首页仍由 footer 输出；Home Blox 启用后由本区块按文档顺序输出。
 */
$ctaTitle = configLang('home_cta_title', 'home_cta_title');
$ctaDesc = configLang('home_cta_desc', 'home_cta_desc');
$ctaButton = config('home_cta_button', '') ?: __('detail_consult');
$ctaLink = config('home_cta_link', '') ?: '/contact.html';
$_homeFieldAttr = isset($ykHomeFieldAttr) && is_callable($ykHomeFieldAttr)
    ? $ykHomeFieldAttr
    : static fn (string $field): string => '';
$bg = getBlockBg($block ?? [], 'cta-gradient text-white');
?>
<section class="py-16 text-center <?php echo $bg['class']; ?>" <?php echo $bg['style']; ?>>
    <?php echo $bg['overlay']; ?>
    <div class="<?php echo $bg['container']; ?> <?php echo $bg['content']; ?>">
        <h2<?php echo $_homeFieldAttr('override_title'); ?> class="text-3xl font-bold mb-4"><?php echo e($ctaTitle); ?></h2>
        <?php echo homeTitleDeco(true, '', ''); ?>
        <p<?php echo $_homeFieldAttr('override_description'); ?> class="text-xl opacity-90 mb-8"><?php echo e($ctaDesc); ?></p>
        <?php if ($qrcode = config('contact_qrcode')): ?>
        <div class="inline-block bg-white p-3 rounded-xl mb-4">
            <img src="<?php echo e($qrcode); ?>" alt="QR Code" class="w-32 h-32">
        </div>
        <?php endif; ?>
        <?php if ($phone = configRawLang('contact_phone')): ?>
        <p class="opacity-80 mb-6"><?php echo __('contact_phone'); ?>：<?php echo e($phone); ?></p>
        <?php endif; ?>
        <a<?php echo $_homeFieldAttr('override_button_text'); ?> href="<?php echo e($ctaLink); ?>" class="inline-block bg-white text-primary hover:bg-gray-100 px-8 py-3 rounded-full font-bold transition">
            <?php echo e($ctaButton); ?>
        </a>
    </div>
</section>
