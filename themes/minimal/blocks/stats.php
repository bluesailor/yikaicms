<?php
/**
 * Minimal 主题 — 数据统计横栏
 *
 * 只用大数字 + 细线 + 小说明文字，无图标无背景图。
 */
$bg = getBlockBg($block ?? [], 'bg-white');
?>
<section class="py-20 <?php echo $bg['class']; ?>" <?php echo $bg['style']; ?>>
    <?php echo $bg['overlay']; ?>
    <div class="<?php echo $bg['container']; ?> <?php echo $bg['content']; ?> px-6 lg:px-8">
        <div class="grid grid-cols-2 md:grid-cols-4 divide-x divide-gray-200" data-stagger>
            <?php for ($i = 1; $i <= 4; $i++): ?>
            <div class="px-6 first:pl-0 last:pr-0">
                <div class="text-4xl md:text-5xl font-light text-gray-900 tracking-tight stat-number"
                     data-count="<?php echo e(config("home_stat_{$i}_num", '10+')); ?>">
                    <?php echo e(config("home_stat_{$i}_num", '10+')); ?>
                </div>
                <div class="w-8 h-px bg-gray-900 my-4"></div>
                <div class="text-xs text-gray-500 tracking-widest uppercase">
                    <?php echo e(configLang("home_stat_{$i}_text", "home_stat_{$i}")); ?>
                </div>
            </div>
            <?php endfor; ?>
        </div>
    </div>
</section>
