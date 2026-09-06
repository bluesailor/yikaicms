<?php
declare(strict_types=1);
/**
 * Business 主题 - 优势区块
 */
$advIcons = getAdvantageIcons();
$advantageDescription = configLang('home_advantage_desc', 'home_advantage_desc');
$advDefaults = [
    ['icon' => 'check-circle', 'title' => __('home_adv_1_title'), 'desc' => __('home_adv_1_desc')],
    ['icon' => 'academic-cap', 'title' => __('home_adv_2_title'), 'desc' => __('home_adv_2_desc')],
    ['icon' => 'briefcase',    'title' => __('home_adv_3_title'), 'desc' => __('home_adv_3_desc')],
    ['icon' => 'users',        'title' => __('home_adv_4_title'), 'desc' => __('home_adv_4_desc')],
];
$_homeFieldAttr = isset($ykHomeFieldAttr) && is_callable($ykHomeFieldAttr)
    ? $ykHomeFieldAttr
    : static fn (string $field): string => '';
require_once dirname(__DIR__) . '/partials/home-surface.php';
$surface = businessHomeSurface($block ?? []);
?>
<section class="py-20 business-surface" <?= $surface['attributes'] ?> <?= $surface['style'] ?>>
    <?= $surface['overlay'] ?>
    <div class="<?= e($surface['container'] . ' ' . $surface['content']) ?>">
        <div class="text-center mb-12" data-animate="fade-up">
            <h2<?php echo $_homeFieldAttr('override_title'); ?> class="text-3xl font-bold business-title mb-4"><?php echo e(configLang('home_advantage_title') ?: __('home_our_advantage')); ?></h2>
            <?php echo homeTitleDeco(true, '', '<img src="/themes/business/images/divide.png" alt="" class="mx-auto mb-4">'); ?>
            <p<?php echo $_homeFieldAttr('override_description'); ?> class="business-copy"><?php echo e($advantageDescription); ?></p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8" data-stagger>
            <?php for ($i = 0; $i < 4; $i++):
                $n = $i + 1;
                $iconKey = config("home_adv_{$n}_icon", $advDefaults[$i]['icon']);
                $iconData = BloxIcon::parse($iconKey, 'check-circle');
                $iconSvg = $advIcons[$iconKey]['svg'] ?? $advIcons['check-circle']['svg'];
            ?>
            <div class="text-center p-6 business-card">
                <div<?php echo $_homeFieldAttr('advantage_items.' . $i . '.icon'); ?> class="w-14 h-14 bg-primary rounded-full flex items-center justify-center mx-auto mb-4">
                    <?php if ($iconData['library'] === 'bootstrap'): ?>
                    <i class="<?php echo e(BloxIcon::classes($iconData['value'])); ?> text-2xl text-white"></i>
                    <?php else: ?>
                    <svg class="w-7 h-7 text-white" fill="currentColor" viewBox="0 0 20 20"><?php echo $iconSvg; ?></svg>
                    <?php endif; ?>
                </div>
                <h3<?php echo $_homeFieldAttr('advantage_items.' . $i . '.title'); ?> class="text-lg font-bold business-title mb-2"><?php echo e(config("home_adv_{$n}_title", $advDefaults[$i]['title'])); ?></h3>
                <p<?php echo $_homeFieldAttr('advantage_items.' . $i . '.description'); ?> class="business-copy text-sm"><?php echo e(config("home_adv_{$n}_desc", $advDefaults[$i]['desc'])); ?></p>
            </div>
            <?php endfor; ?>
        </div>
    </div>
</section>
