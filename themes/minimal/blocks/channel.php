<?php
/**
 * Minimal Theme - Channel Block
 * Variables: $currentChannel, $block
 */
if (empty($currentChannel)) return;
$bg = getBlockBg($block ?? [], 'bg-white');
$hChannel = $currentChannel;
$channelType = $hChannel['type'];
$contents = $hChannel['contents'];
?>
<section class="py-20 <?php echo $bg['class']; ?>" <?php echo $bg['style']; ?>>
    <?php echo $bg['overlay']; ?>
    <div class="<?php echo $bg['container']; ?> <?php echo $bg['content']; ?> px-6 lg:px-8">
        <!-- Section Title -->
        <div class="mb-16">
            <h2 class="text-2xl font-light text-gray-900 tracking-wide"><?php echo e($hChannel['name']); ?></h2>
            <div class="w-12 h-px bg-gray-900 mt-4"></div>
            <?php if ($hChannel['description']): ?>
            <p class="text-gray-400 text-sm mt-4 max-w-xl"><?php echo e($hChannel['description']); ?></p>
            <?php endif; ?>
        </div>

        <?php if (!empty($contents)): ?>

        <?php if ($hChannel['is_product'] ?? false): ?>
        <!-- Products -->
        <?php $categories = $hChannel['categories'] ?? []; ?>
        <?php if (!empty($categories)): ?>
        <div class="flex flex-wrap gap-4 mb-12" id="productCategoryNav">
            <button type="button" class="category-btn text-sm tracking-wide transition pb-1 border-b border-gray-900 text-gray-900" data-category="all">
                <?php echo __('all'); ?>
            </button>
            <?php foreach ($categories as $cat): ?>
            <button type="button" class="category-btn text-sm tracking-wide transition pb-1 border-b border-transparent text-gray-400 hover:text-gray-900 hover:border-gray-900" data-category="<?php echo $cat['id']; ?>">
                <?php echo e($cat['name']); ?>
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6" id="productGrid">
            <?php foreach ($contents as $item): ?>
            <a href="<?php echo productUrl($item); ?>" class="product-item block border border-gray-200 hover:border-gray-400 transition group" data-category="<?php echo $item['category_id'] ?? 0; ?>">
                <div class="aspect-[4/3] overflow-hidden">
                    <?php if ($item['cover']): ?>
                    <img loading="lazy" src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>" class="w-full h-full object-cover">
                    <?php else: ?>
                    <div class="w-full h-full bg-gray-50 flex items-center justify-center text-gray-300">
                        <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="p-4">
                    <h3 class="text-sm text-gray-700 group-hover:text-gray-900 transition line-clamp-2">
                        <?php echo e($item['title']); ?>
                    </h3>
                    <?php if (config('show_price', '0') === '1' && !empty($item['price']) && $item['price'] > 0): ?>
                    <div class="mt-2 text-gray-900 text-sm">&yen;<?php echo number_format((float)$item['price'], 2); ?></div>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <?php elseif ($channelType === 'case'): ?>
        <!-- Cases -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            <?php foreach ($contents as $item): ?>
            <a href="<?php echo contentUrl($item); ?>" class="block border border-gray-200 hover:border-gray-400 transition group">
                <div class="aspect-[4/3] overflow-hidden">
                    <?php if ($item['cover']): ?>
                    <img loading="lazy" src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>" class="w-full h-full object-cover">
                    <?php else: ?>
                    <div class="w-full h-full bg-gray-50 flex items-center justify-center text-gray-300">
                        <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="p-6">
                    <h3 class="text-base text-gray-700 group-hover:text-gray-900 transition line-clamp-2">
                        <?php echo e($item['title']); ?>
                    </h3>
                    <p class="text-gray-400 text-sm mt-2 line-clamp-2">
                        <?php echo e(cutStr($item['summary'] ?: strip_tags($item['content'] ?? ''), 60)); ?>
                    </p>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <?php else: ?>
        <!-- Articles -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <?php foreach ($contents as $item):
                $itemUrl = ($hChannel['slug'] === 'news') ? '/news/article/' . $item['id'] . '.html' : contentUrl($item);
                $itemCatName = $item['channel_name'] ?? $hChannel['name'];
            ?>
            <a href="<?php echo $itemUrl; ?>" class="block border border-gray-200 hover:border-gray-400 transition group">
                <?php if ($item['cover']): ?>
                <div class="overflow-hidden h-40">
                    <img loading="lazy" src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>" class="w-full h-full object-cover">
                </div>
                <?php endif; ?>
                <div class="p-4">
                    <div class="text-xs text-gray-300 mb-2">
                        <?php echo date('Y.m.d', (int)$item['publish_time']); ?>
                    </div>
                    <h3 class="text-sm text-gray-700 group-hover:text-gray-900 transition line-clamp-2">
                        <?php echo e($item['title']); ?>
                    </h3>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <!-- Empty placeholder -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <?php for ($i = 0; $i < 3; $i++): ?>
            <div class="border border-gray-200">
                <div class="h-48 bg-gray-50"></div>
                <div class="p-6">
                    <div class="h-4 bg-gray-100 mb-3"></div>
                    <div class="h-3 bg-gray-50 w-2/3"></div>
                </div>
            </div>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

        <div class="mt-16">
            <a href="<?php echo channelUrl($hChannel); ?>" class="inline-block text-sm tracking-wide text-gray-500 border-b border-gray-300 pb-1 hover:text-gray-900 hover:border-gray-900 transition">
                <?php echo __('home_view_all'); ?> &rarr;
            </a>
        </div>
    </div>
</section>
