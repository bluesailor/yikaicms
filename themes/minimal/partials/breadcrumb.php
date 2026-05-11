<?php
/**
 * Minimal 主题 — 面包屑（极细一行 / 分隔，全灰阶）
 *
 *   @var array  $breadcrumbItems  [['name'=>...,'url'=>...]...]
 *   @var string $style            'light' (image bg) | 'default' (light bg)
 */
$style = $style ?? 'default';
$muted = $style === 'light' ? 'text-gray-300' : 'text-gray-400';
$cur   = $style === 'light' ? 'text-white' : 'text-gray-900';
?>
<nav aria-label="breadcrumb" class="text-xs <?php echo $muted; ?> tracking-wide mb-6">
    <a href="/" class="hover:<?php echo $cur; ?> transition"><?php echo __('breadcrumb_home'); ?></a>
    <?php foreach ($breadcrumbItems as $i => $item): ?>
    <span class="mx-2 opacity-50">/</span>
    <?php if ($i === count($breadcrumbItems) - 1): ?>
    <span class="<?php echo $cur; ?>"><?php echo e($item['name']); ?></span>
    <?php else: ?>
    <a href="<?php echo $item['url']; ?>" class="hover:<?php echo $cur; ?> transition"><?php echo e($item['name']); ?></a>
    <?php endif; ?>
    <?php endforeach; ?>
</nav>
