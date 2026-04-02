<?php
/**
 * Minimal Theme - Page Hero
 *
 * Expected variables:
 * @var array $channel - Channel data with name, description, image
 * @var array $breadcrumbItems - Breadcrumb items array
 */
?>
<section class="bg-white py-16 md:py-24">
    <div class="container mx-auto px-6 lg:px-8">
        <!-- Breadcrumb -->
        <div class="flex items-center gap-2 text-xs text-gray-300 mb-8">
            <a href="/" class="hover:text-gray-600 transition"><?php echo __('breadcrumb_home'); ?></a>
            <?php foreach ($breadcrumbItems as $i => $item): ?>
            <span>/</span>
            <?php if ($i === count($breadcrumbItems) - 1): ?>
            <span class="text-gray-500"><?php echo e($item['name']); ?></span>
            <?php else: ?>
            <a href="<?php echo $item['url']; ?>" class="hover:text-gray-600 transition"><?php echo e($item['name']); ?></a>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <!-- Title -->
        <div class="text-center">
            <h1 class="text-3xl md:text-4xl font-light text-gray-900 tracking-wide"><?php echo e($channel['name']); ?></h1>
            <?php if ($channel['description']): ?>
            <p class="text-gray-400 text-sm mt-4 max-w-xl mx-auto"><?php echo e($channel['description']); ?></p>
            <?php endif; ?>
            <div class="w-12 h-px bg-gray-900 mx-auto mt-6"></div>
        </div>
    </div>
</section>
