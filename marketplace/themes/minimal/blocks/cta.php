<?php
/**
 * Minimal 主题 — CTA 区块
 *
 * 大留白单行声明 + 极简下划线按钮，无强调色。
 */
// 用与 default 主题相同的 key 命名（home_cta_title / home_cta_desc），
// 按钮复用现有的 detail_consult 翻译，不需要单造一组 default_* key。
$ctaTitle = configLang('home_cta_title', 'home_cta_title');
$ctaDesc  = configLang('home_cta_desc',  'home_cta_desc');
$ctaBtn   = config('home_cta_button', '') ?: __('detail_consult');
$ctaLink  = config('home_cta_link',  '') ?: '/contact.html';
$_homeFieldAttr = isset($ykHomeFieldAttr) && is_callable($ykHomeFieldAttr)
    ? $ykHomeFieldAttr
    : static fn (string $field): string => '';
$blockData = $block ?? [];
$textLight = !empty($blockData['text_light']);
$bg = getBlockBg($blockData, 'bg-gray-50');
$eyebrowClass = $textLight ? 'text-white/70' : 'text-gray-400';
$titleClass = $textLight ? 'text-white' : 'text-gray-900';
$descriptionClass = $textLight ? 'text-white/80' : 'text-gray-500';
$buttonClass = $textLight
    ? 'text-white border-white hover:text-white'
    : 'text-gray-900 border-gray-900';
?>
<section class="py-28 <?php echo $bg['class']; ?>" <?php echo $bg['style']; ?>>
    <?php echo $bg['overlay']; ?>
    <div class="<?php echo $bg['container']; ?> <?php echo $bg['content']; ?> px-6 lg:px-8">
        <div class="max-w-3xl" data-animate="fade-up">
            <div class="text-xs <?php echo $eyebrowClass; ?> font-mono tracking-widest uppercase mb-4">
                — <?php echo 'Get in touch'; ?>
            </div>
            <h2<?php echo $_homeFieldAttr('override_title'); ?> class="text-3xl md:text-5xl font-light <?php echo $titleClass; ?> leading-tight tracking-tight">
                <?php echo e($ctaTitle); ?>
            </h2>
            <?php echo homeTitleDeco($textLight, '', ''); ?>
            <p<?php echo $_homeFieldAttr('override_description'); ?> class="mt-8 <?php echo $descriptionClass; ?> text-base leading-relaxed max-w-xl">
                <?php echo e($ctaDesc); ?>
            </p>
            <a<?php echo $_homeFieldAttr('override_button_text'); ?> href="<?php echo e($ctaLink); ?>"
               class="inline-flex items-center gap-3 mt-10 text-sm tracking-wide <?php echo $buttonClass; ?>
                      border-b pb-2 hover:gap-5 transition-all duration-300">
                <?php echo e($ctaBtn); ?>
                <span class="text-base">&rarr;</span>
            </a>
        </div>
    </div>
</section>
