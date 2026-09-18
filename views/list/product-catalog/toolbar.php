<?php
/**
 * 产品目录——目录工具栏区域（结构树「目录工具栏」节点对应）。
 *
 * 搜索、结果数量、排序、移动端筛选入口各自独立，可分别隐藏；
 * 隐藏搜索时不输出搜索表单（不加载无意义表单）。
 * 变体：full（搜索+数量+排序）/ sidebar（仅搜索）/ main（数量+排序）/ toolbar（分类+搜索+数量+排序）。
 */

declare(strict_types=1);

extract($context, EXTR_SKIP);

$pcToolbarVariant = in_array($pcToolbarVariant ?? 'full', ['full', 'sidebar', 'main', 'toolbar'], true)
    ? $pcToolbarVariant
    : 'full';
$pcShowSearch = !empty($catalog['show_search']) && $pcToolbarVariant !== 'main';
$pcShowCount = !empty($catalog['show_count']) && $pcToolbarVariant !== 'sidebar';
$pcShowSort = !empty($catalog['show_sort']) && !empty($enabledSorts) && count($enabledSorts) > 1 && $isProductType && $pcToolbarVariant !== 'sidebar';
$pcShowNavChips = $pcToolbarVariant === 'toolbar' && !empty($catalog['show_categories']);

$pcKeyword = (string) $keyword;
?>
<div class="<?php echo $pcToolbarVariant === 'sidebar' ? '' : 'mb-6'; ?>" data-catalog-region="toolbar">
    <?php if ($pcShowNavChips): ?>
    <?php $pcNavVariant = 'toolbar'; require __DIR__ . '/nav.php'; ?>
    <?php endif; ?>

    <?php if ($pcShowSearch): ?>
    <form method="get" action="<?php echo $pcDynamicRoute ? '/index.php' : $pcListUrl; ?>" class="flex items-center gap-2 <?php echo $pcToolbarVariant === 'full' || $pcToolbarVariant === 'toolbar' ? 'max-w-md' : ''; ?>">
        <?php if ($pcDynamicRoute): ?>
        <input type="hidden" name="yk_route" value="<?php echo e($pcRoute); ?>">
        <?php if (!$isProductType): ?>
        <input type="hidden" name="slug" value="<?php echo e((string) ($channel['slug'] ?? '')); ?>">
        <?php endif; ?>
        <?php endif; ?>
        <?php if ($isProductType && $productCategory && !empty($productCategory['slug'])): ?>
        <input type="hidden" name="cat" value="<?php echo e((string) $productCategory['slug']); ?>">
        <?php endif; ?>
        <div class="relative flex-1">
            <input type="text" name="keyword" value="<?php echo e($pcKeyword); ?>"
                   placeholder="<?php echo e($pcToolbarVariant === 'sidebar' ? __('search_placeholder') : __('list_search_product')); ?>"
                   class="w-full border rounded-lg pl-4 pr-10 py-2 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
            <button type="submit" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-primary" aria-label="<?php echo e(__('search')); ?>">
                <i class="ti ti-search text-lg"></i>
            </button>
        </div>
        <?php if ($pcKeyword !== ''): ?>
        <a href="<?php echo channelUrl($channel); ?>" class="text-gray-400 hover:text-red-500" title="<?php echo e(__('search_clear')); ?>">
            <i class="ti ti-x text-lg"></i>
        </a>
        <?php endif; ?>
    </form>
    <?php endif; ?>

    <?php if ($pcShowCount || $pcShowSort): ?>
    <div class="flex items-center justify-between flex-wrap gap-2 <?php echo $pcShowSearch ? 'mt-3' : ''; ?>">
        <div class="text-gray-600 text-sm">
            <?php if ($pcShowCount): ?>
            <?php if ($pcKeyword !== ''): ?>
            <?php echo __('search_result'); ?> "<span class="text-primary font-medium"><?php echo e($pcKeyword); ?></span>"
            <?php endif; ?>
            <?php echo __('list_total'); ?> <span class="text-primary font-medium"><?php echo (int) $total; ?></span> <?php echo __('list_items'); ?>
            <?php endif; ?>
        </div>
        <?php if ($pcShowSort): ?>
        <div class="flex items-center gap-1.5 text-sm">
            <?php foreach ($enabledSorts as $sortKey):
                if (!isset(ProductModel::SORT_LABELS[$sortKey])) { continue; }
                $isActive = ($sortKey === $currentSort);
                $sortUrl = strtok($_SERVER['REQUEST_URI'], '?');
                $sortParams = $_GET;
                $sortParams['sort'] = $sortKey;
                unset($sortParams['page']);
                $sortUrl .= '?' . http_build_query($sortParams);
            ?>
            <a href="<?php echo e($sortUrl); ?>"
               class="px-3 py-1 rounded-full border transition <?php echo $isActive ? 'bg-primary text-white border-primary' : 'bg-white text-gray-600 border-gray-200 hover:border-primary hover:text-primary'; ?>">
                <?php echo __(ProductModel::SORT_LABELS[$sortKey]); ?>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
