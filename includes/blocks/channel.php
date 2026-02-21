<?php
/**
 * 首页区块：单个栏目（产品/案例/文章等）
 * 变量：$currentChannel, $block
 */
if (empty($currentChannel)) return;
$bg = getBlockBg($block ?? [], 'bg-gray-50');
$hChannel = $currentChannel;
$channelType = $hChannel['type'];
$contents = $hChannel['contents'];
?>
<section class="py-16 <?php echo $bg['class']; ?>" <?php echo $bg['style']; ?>>
    <?php echo $bg['overlay']; ?>
    <div class="<?php echo $bg['container']; ?> <?php echo $bg['content']; ?>">
        <div class="text-center mb-12">
            <h2 class="text-3xl font-bold text-dark mb-2">
                <span class="text-primary"><?php echo e(mb_substr($hChannel['name'], 0, 2)); ?></span><?php echo e(mb_substr($hChannel['name'], 2)); ?>
            </h2>
            <span class="section-title-bar"></span>
            <?php if ($hChannel['description']): ?>
            <p class="text-gray-500"><?php echo e($hChannel['description']); ?></p>
            <?php endif; ?>
        </div>

        <?php if (!empty($contents)): ?>

        <?php if ($hChannel['is_product'] ?? false): ?>
        <!-- 产品：带分类导航的卡片网格 -->
        <?php $categories = $hChannel['categories'] ?? []; ?>
        <?php if (!empty($categories)): ?>
        <div class="flex flex-wrap justify-center gap-3 mb-8" id="productCategoryNav">
            <button type="button" class="category-btn px-5 py-2 rounded-full text-sm transition bg-primary text-white" data-category="all">
                <?php echo __('all'); ?>
            </button>
            <?php foreach ($categories as $cat): ?>
            <button type="button" class="category-btn px-5 py-2 rounded-full text-sm transition bg-gray-100 text-gray-600 hover:bg-gray-200" data-category="<?php echo $cat['id']; ?>">
                <?php echo e($cat['name']); ?>
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6" id="productGrid">
            <?php foreach ($contents as $item): ?>
            <a href="<?php echo productUrl($item); ?>" class="product-item block bg-white rounded-lg shadow-md overflow-hidden hover:shadow-xl transition group" data-category="<?php echo $item['category_id'] ?? 0; ?>">
                <div class="relative overflow-hidden aspect-[4/3]">
                    <?php if ($item['cover']): ?>
                    <img src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>" class="w-full h-full object-cover group-hover:scale-110 transition duration-500">
                    <?php else: ?>
                    <div class="w-full h-full bg-gray-200 flex items-center justify-center text-gray-400">
                        <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($item['is_hot'])): ?>
                    <span class="absolute top-2 left-2 bg-red-500 text-white text-xs px-2 py-1 rounded"><?php echo __('home_hot'); ?></span>
                    <?php elseif (!empty($item['is_new'])): ?>
                    <span class="absolute top-2 left-2 bg-green-500 text-white text-xs px-2 py-1 rounded"><?php echo __('home_new'); ?></span>
                    <?php endif; ?>
                </div>
                <div class="p-4">
                    <h3 class="font-bold text-dark group-hover:text-primary transition line-clamp-2 text-sm md:text-base">
                        <?php echo e($item['title']); ?>
                    </h3>
                    <?php if (config('show_price', '0') === '1' && !empty($item['price']) && $item['price'] > 0): ?>
                    <div class="mt-2 text-primary font-bold text-sm">&yen;<?php echo number_format((float)$item['price'], 2); ?></div>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <?php elseif ($channelType === 'case'): ?>
        <!-- 案例：卡片网格 -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            <?php foreach ($contents as $item): ?>
            <a href="<?php echo contentUrl($item); ?>" class="block bg-white rounded-lg shadow-md overflow-hidden hover:shadow-xl transition group">
                <div class="relative overflow-hidden aspect-[4/3]">
                    <?php if ($item['cover']): ?>
                    <img src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>" class="w-full h-full object-cover group-hover:scale-110 transition duration-500">
                    <?php else: ?>
                    <div class="w-full h-full bg-gray-200 flex items-center justify-center text-gray-400">
                        <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($item['is_hot'])): ?>
                    <span class="absolute top-2 left-2 bg-red-500 text-white text-xs px-2 py-1 rounded"><?php echo __('home_hot'); ?></span>
                    <?php endif; ?>
                </div>
                <div class="p-6">
                    <h3 class="text-lg font-bold text-dark mb-2 group-hover:text-primary transition line-clamp-2">
                        <?php echo e($item['title']); ?>
                    </h3>
                    <p class="text-gray-500 text-sm line-clamp-2">
                        <?php echo e(cutStr($item['summary'] ?: strip_tags($item['content'] ?? ''), 60)); ?>
                    </p>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <?php else: ?>
        <!-- 文章/新闻：列表样式 -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <?php foreach ($contents as $item):
                $itemUrl = !empty($hChannel['is_article']) ? '/news/article/' . $item['id'] . '.html' : contentUrl($item);
                $itemCatName = !empty($hChannel['is_article']) ? ($item['category_name'] ?? $hChannel['name']) : ($item['channel_name'] ?? $hChannel['name']);
            ?>
            <a href="<?php echo $itemUrl; ?>" class="block bg-white rounded-lg overflow-hidden hover:shadow-lg transition group">
                <?php if ($item['cover']): ?>
                <div class="overflow-hidden h-40">
                    <img src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>" class="w-full h-full object-cover group-hover:scale-110 transition duration-500">
                </div>
                <?php endif; ?>
                <div class="p-4">
                    <div class="text-xs text-gray-400 mb-2">
                        <?php echo e($itemCatName); ?> · <?php echo friendlyTime((int)$item['publish_time']); ?>
                    </div>
                    <h3 class="font-bold text-dark group-hover:text-primary transition line-clamp-2">
                        <?php echo e($item['title']); ?>
                    </h3>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <!-- 无内容占位 -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <?php for ($i = 0; $i < 3; $i++): ?>
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="h-48 bg-gray-200 flex items-center justify-center text-gray-400">
                    <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                </div>
                <div class="p-6">
                    <div class="h-4 bg-gray-200 rounded mb-3"></div>
                    <div class="h-3 bg-gray-100 rounded w-2/3"></div>
                </div>
            </div>
            <?php endfor; ?>
        </div>
        <?php endif; ?>

        <div class="text-center mt-10">
            <a href="<?php echo channelUrl($hChannel); ?>" class="inline-block border-2 border-primary text-primary hover:bg-primary hover:text-white px-8 py-3 rounded-full transition">
                <?php echo __('home_view_all'); ?> &raquo;
            </a>
        </div>
    </div>
</section>
