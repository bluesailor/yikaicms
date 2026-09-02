<?php
declare(strict_types=1);
/**
 * Business 主题 - 栏目区块（按可见顺序交替配色）
 */
if (empty($currentChannel)) return;
$hChannel = $currentChannel;
$channelType = $hChannel['type'];
$contents = $hChannel['contents'];
$perRow = (int) ($hChannel['per_row'] ?? 0);
$productGrid = AbstractElement::gridClasses($perRow, 3);
$caseGrid = AbstractElement::gridClasses($perRow, 3);
$articleGrid = AbstractElement::gridClasses($perRow, 4);
require_once dirname(__DIR__) . '/partials/home-surface.php';
$surface = businessHomeSurface($block ?? []);
?>
<section class="py-20 business-surface" <?= $surface['attributes'] ?> <?= $surface['style'] ?>>
    <?= $surface['overlay'] ?>
    <div class="<?= e($surface['container'] . ' ' . $surface['content']) ?>">
        <!-- title -->
        <div class="flex items-end justify-between mb-12" data-animate="fade-up">
            <div>
                <h2 class="text-3xl font-bold business-title mb-3"><?php echo e($hChannel['name']); ?></h2>
                <?php echo homeTitleDeco(false, '', '<img src="/themes/business/images/divide.png" alt="" class="mb-2">'); ?>
                <?php if ($hChannel['description']): ?>
                <p class="mt-2 business-copy"><?php echo e($hChannel['description']); ?></p>
                <?php endif; ?>
            </div>
            <a href="<?php echo e(($hChannel['home_button_url'] ?? '') ?: channelUrl($hChannel)); ?>" class="hidden md:inline-flex items-center gap-1 bg-primary hover:bg-secondary text-white px-5 py-2 rounded-full text-sm font-medium transition">
                <?php echo e($hChannel['home_button_text'] ?? __('home_view_all')); ?>
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </a>
        </div>

        <?php if (!empty($contents)): ?>

        <?php if ($hChannel['is_product'] ?? false): ?>
        <!-- Product grid -->
        <div class="grid <?php echo $productGrid; ?> gap-6" data-stagger>
            <?php foreach (array_slice($contents, 0, 6) as $item): ?>
            <a href="<?php echo productUrl($item); ?>" class="block group">
                <div class="aspect-square bg-gray-100 rounded-lg overflow-hidden mb-3">
                    <?php if ($item['cover']): ?>
                    <img loading="lazy" src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>" class="w-full h-full object-cover group-hover:scale-105 transition duration-500">
                    <?php else: ?>
                    <div class="w-full h-full flex items-center justify-center text-gray-300">
                        <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    </div>
                    <?php endif; ?>
                </div>
                <h3 class="text-center text-sm font-medium business-link transition"><?php echo e($item['title']); ?></h3>
            </a>
            <?php endforeach; ?>
        </div>

        <?php elseif ($channelType === 'case'): ?>
        <!-- Case Grid -->
        <div class="grid <?php echo $caseGrid; ?> gap-6" data-stagger>
            <?php foreach (array_slice($contents, 0, 6) as $item): ?>
            <a href="<?php echo contentUrl($item); ?>" class="block group">
                <div class="aspect-[4/3] bg-gray-200 rounded-lg overflow-hidden mb-3">
                    <?php if ($item['cover']): ?>
                    <img loading="lazy" src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>" class="w-full h-full object-cover group-hover:scale-105 transition duration-500">
                    <?php endif; ?>
                </div>
                <h3 class="text-center text-sm font-medium business-link transition"><?php echo e($item['title']); ?></h3>
            </a>
            <?php endforeach; ?>
        </div>

        <?php else: ?>
        <!-- Article grid: only display items with cover images. -->
        <?php $withCover = array_filter($contents, fn($i) => !empty($i['cover'])); ?>
        <div class="grid <?php echo $articleGrid; ?> gap-6" data-stagger>
            <?php foreach (array_slice($withCover, 0, 4) as $item): ?>
            <a href="<?php echo contentUrl($item); ?>" class="block group">
                <div class="aspect-[16/9] bg-gray-700 rounded-lg overflow-hidden mb-3">
                    <img loading="lazy" src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>" class="w-full h-full object-cover group-hover:scale-105 transition duration-500">
                </div>
                <h3 class="font-bold business-link transition"><?php echo e($item['title']); ?></h3>
                <p class="text-sm business-copy mt-1"><?php echo date('Y-m-d', (int)(($item['publish_time'] ?? 0) ?: ($item['created_at'] ?? 0))); ?></p>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- View more on mobile -->
        <div class="mt-8 text-center md:hidden">
            <a href="<?php echo e(($hChannel['home_button_url'] ?? '') ?: channelUrl($hChannel)); ?>" class="inline-block bg-primary hover:bg-secondary text-white px-6 py-2 rounded-full text-sm transition">
                <?php echo e($hChannel['home_button_text'] ?? __('home_view_all')); ?> &raquo;
            </a>
        </div>

        <?php endif; ?>
    </div>
</section>
