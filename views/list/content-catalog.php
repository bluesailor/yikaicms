<?php
/** Runtime catalog used by the Blox content-catalog element. */

declare(strict_types=1);

$contentCatalogGridClass = [
    2 => 'grid grid-cols-1 md:grid-cols-2 gap-6',
    3 => 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6',
    4 => 'grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6',
][$contentCatalogColumns];
$catalogCurrentChannel = is_array($channel ?? null) ? $channel : $rootChannel;
$catalogRootChannel = is_array($rootChannel ?? null) ? $rootChannel : $catalogCurrentChannel;
$catalogCategories = is_array($categories ?? null) ? $categories : [];
$catalogContents = is_array($contents ?? null) ? $contents : [];
$catalogBaseUrl = channelUrl($catalogCurrentChannel);
?>
<div data-content-catalog>
    <?php if ($contentCatalogShowCategories || $contentCatalogShowSearch): ?>
    <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <?php if ($contentCatalogShowCategories && $catalogCategories): ?>
        <div class="flex flex-wrap gap-3">
            <a href="<?php echo e(channelUrl($catalogRootChannel)); ?>"
               class="px-4 py-2 rounded-full text-sm <?php echo (int) ($catalogCurrentChannel['id'] ?? 0) === (int) ($catalogRootChannel['id'] ?? 0) && $keyword === '' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                <?php echo e(__('all')); ?>
            </a>
            <?php foreach ($catalogCategories as $catalogCategory): ?>
            <a href="<?php echo e(channelUrl($catalogCategory)); ?>"
               class="px-4 py-2 rounded-full text-sm <?php echo (int) ($catalogCurrentChannel['id'] ?? 0) === (int) $catalogCategory['id'] ? 'bg-primary text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                <?php echo e($catalogCategory['name']); ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div></div>
        <?php endif; ?>

        <?php if ($contentCatalogShowSearch): ?>
        <form method="get" action="<?php echo e($catalogBaseUrl); ?>" class="flex items-center gap-2">
            <div class="relative">
                <input type="text" name="keyword" value="<?php echo e($keyword); ?>"
                       placeholder="<?php echo e(__('news_search_placeholder')); ?>"
                       class="w-56 border rounded-full pl-4 pr-9 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                <button type="submit" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-primary" aria-label="<?php echo e(__('search')); ?>">
                    <i class="ti ti-search text-base"></i>
                </button>
            </div>
            <?php if ($keyword !== ''): ?>
            <a href="<?php echo e($catalogBaseUrl); ?>" class="text-gray-400 hover:text-red-500" title="<?php echo e(__('search_clear')); ?>">
                <i class="ti ti-x text-base"></i>
            </a>
            <?php endif; ?>
        </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($keyword !== ''): ?>
    <div class="mb-5 text-sm text-gray-500">
        <?php echo __('search_total', ['count' => '<span class="text-primary font-medium">' . (int) $total . '</span>']); ?>
        — "<span class="text-primary"><?php echo e($keyword); ?></span>"
    </div>
    <?php endif; ?>

    <?php if ($catalogContents): ?>
    <div class="<?php echo $contentCatalogLayout === 'grid' ? $contentCatalogGridClass : 'space-y-6'; ?>">
        <?php foreach ($catalogContents as $item): ?>
        <?php if ($contentCatalogLayout === 'grid'): ?>
        <?php require theme_path('partials/article-grid-card.php'); ?>
        <?php else: ?>
        <?php require theme_path('partials/article-card.php'); ?>
        <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="text-center py-16 text-gray-500 bg-white rounded-lg"><?php echo e(__('no_content')); ?></div>
    <?php endif; ?>

    <?php
    $totalPages = (int) ceil(((int) $total) / max(1, (int) $perPage));
    $pageUrl = static function (int $targetPage) use ($catalogBaseUrl, $keyword): string {
        $base = $targetPage === 1
            ? $catalogBaseUrl
            : (preg_replace('/\.html$/', '/page/' . $targetPage . '.html', $catalogBaseUrl) ?: $catalogBaseUrl);
        return $base . ($keyword !== '' ? '?keyword=' . urlencode($keyword) : '');
    };
    require theme_path('partials/pagination.php');
    ?>
</div>
