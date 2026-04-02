<?php
/**
 * Breadcrumb navigation partial
 *
 * Expected variables:
 * @var array $breadcrumbItems - Array of ['name' => ..., 'url' => ...]
 * @var string $style - Optional: 'light' for image bg (gray-300 text), 'default' for gradient bg (gray-400 text)
 */
$style = $style ?? 'default';
$textColor = $style === 'light' ? 'text-gray-300' : 'text-gray-400';
?>
<div class="flex items-center gap-2 text-sm <?php echo $textColor; ?> mb-6">
    <a href="/" class="hover:text-white"><?php echo __('breadcrumb_home'); ?></a>
    <?php foreach ($breadcrumbItems as $i => $item): ?>
    <span>/</span>
    <?php if ($i === count($breadcrumbItems) - 1): ?>
    <span class="text-white"><?php echo e($item['name']); ?></span>
    <?php else: ?>
    <a href="<?php echo $item['url']; ?>" class="hover:text-white"><?php echo e($item['name']); ?></a>
    <?php endif; ?>
    <?php endforeach; ?>
</div>
