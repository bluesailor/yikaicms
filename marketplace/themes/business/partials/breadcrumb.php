<?php
/**
 * Business 主题 — 面包屑（>> 三角分隔，单色调，紧凑）
 *
 *   @var array  $breadcrumbItems  [['name'=>...,'url'=>...]...]
 *   @var string $style            'light' (image bg) | 'default' (dark slate bg)
 */
$style = $style ?? 'default';
$muted = $style === 'light' ? 'text-slate-300' : 'text-slate-400';
$hover = 'hover:text-primary';
$sep   = $style === 'light' ? 'text-slate-500' : 'text-slate-600';
echo breadcrumbJsonLd($breadcrumbItems ?? []);
?>
<nav aria-label="breadcrumb"
     class="flex items-center gap-1 text-xs <?php echo $muted; ?> font-medium tracking-wide">
    <a href="/" class="<?php echo $hover; ?> uppercase"><?php echo __('breadcrumb_home'); ?></a>
    <?php foreach ($breadcrumbItems as $i => $item): ?>
    <span aria-hidden class="<?php echo $sep; ?>">
        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/></svg>
    </span>
    <?php if ($i === count($breadcrumbItems) - 1): ?>
    <span class="text-primary truncate max-w-[60vw]"><?php echo e($item['name']); ?></span>
    <?php else: ?>
    <a href="<?php echo $item['url']; ?>" class="<?php echo $hover; ?> truncate max-w-[40vw]"><?php echo e($item['name']); ?></a>
    <?php endif; ?>
    <?php endforeach; ?>
</nav>
