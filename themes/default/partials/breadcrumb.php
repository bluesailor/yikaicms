<?php
/**
 * Breadcrumb navigation partial
 *
 * Expected variables:
 * @var array $breadcrumbItems - Array of ['name' => ..., 'url' => ...]
 * @var string $style - Optional: 'light' for image bg (gray-300 text), 'default' for gradient bg (gray-400 text)
 */
$style = $style ?? 'default';
$compact = $style === 'compact';
$darkText = $compact || $style === 'dark';
$textColor = $darkText ? 'text-gray-600' : ($style === 'light' ? 'text-gray-300' : 'text-gray-400');
$linkHover = $darkText ? 'hover:text-gray-900' : 'hover:text-white';
echo breadcrumbJsonLd($breadcrumbItems ?? []);
if ($compact):
?>
<nav class="yk-breadcrumb-nav" aria-label="<?= e(__('blox_breadcrumb_layout')) ?>">
    <ol>
        <li><a href="<?= e(langUrl('/')) ?>" aria-label="<?= e(__('breadcrumb_home')) ?>"><i class="ti ti-home" aria-hidden="true"></i></a></li>
        <?php foreach (($breadcrumbItems ?? []) as $i => $item): ?>
        <li>
            <i class="ti ti-chevron-right yk-breadcrumb-separator" aria-hidden="true"></i>
            <?php if ($i === count($breadcrumbItems) - 1): ?>
            <span aria-current="page"><?= e($item['name']) ?></span>
            <?php else: ?>
            <a href="<?= e($item['url']) ?>"><?= e($item['name']) ?></a>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ol>
</nav>
<?php return; endif; ?>
<div class="flex flex-wrap items-center gap-2 text-sm <?php echo $textColor; ?> <?php echo $compact ? '' : 'mb-6'; ?>">
    <a href="<?php echo e(langUrl('/')); ?>" class="<?php echo $linkHover; ?>"><?php echo __('breadcrumb_home'); ?></a>
    <?php foreach ($breadcrumbItems as $i => $item): ?>
    <span>/</span>
    <?php if ($i === count($breadcrumbItems) - 1): ?>
    <span class="<?php echo $darkText ? 'text-gray-900' : 'text-white'; ?>" aria-current="page"><?php echo e($item['name']); ?></span>
    <?php else: ?>
    <a href="<?php echo e($item['url']); ?>" class="<?php echo $linkHover; ?>"><?php echo e($item['name']); ?></a>
    <?php endif; ?>
    <?php endforeach; ?>
</div>
