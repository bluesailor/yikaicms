<?php
/**
 * View partial: list page body with left sidebar — used for `type=product`
 * and `type=case` channels (showSidebar branch in list.php).
 *
 * Required-included from list.php with all the local scope intact:
 *   \$channel, \$contents, \$total, \$keyword, \$page, \$perPage,
 *   \$isProductType, \$productCategory, \$productCategoryId,
 *   \$currentSort, \$enabledSorts, \$subChannels.
 */
$productCatalogLayout = isset($productCatalogLayout) && in_array($productCatalogLayout, ['sidebar', 'grid'], true)
    ? $productCatalogLayout
    : 'sidebar';
$sidebarListUrl = channelUrl($channel);
$sidebarUsesDynamicRoute = str_contains($sidebarListUrl, 'yk_route=');
$sidebarRoute = $isProductType ? 'product_list' : 'list';
$productCatalogShowSearch = $productCatalogShowSearch ?? true;
$productCatalogShowCategories = $productCatalogShowCategories ?? true;
$productCatalogShowSort = $productCatalogShowSort ?? true;
$productCatalogColumns = in_array((int) ($productCatalogColumns ?? 3), [2, 3, 4], true)
    ? (int) ($productCatalogColumns ?? 3)
    : 3;
$productCatalogHasSidebar = $productCatalogLayout === 'sidebar'
    && ($productCatalogShowSearch || $productCatalogShowCategories);
$productCatalogGridClass = [
    2 => 'grid grid-cols-1 md:grid-cols-2 gap-6',
    3 => 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6',
    4 => 'grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6',
][$productCatalogColumns];
?>
<!-- 产品/案例：带侧边栏布局 -->
        <div class="flex flex-wrap lg:flex-nowrap gap-8" data-product-catalog-layout>
            <?php if ($productCatalogHasSidebar): ?>
            <!-- 左侧分类菜单 -->
            <div class="w-full lg:w-64 flex-shrink-0 space-y-4" data-product-catalog-sidebar>
                <?php if ($productCatalogShowSearch): ?>
                <!-- 搜索框 -->
                <div class="bg-white rounded-lg shadow p-4" data-catalog-search>
                    <form method="get" action="<?php echo $sidebarUsesDynamicRoute ? '/index.php' : $sidebarListUrl; ?>">
                        <?php if ($sidebarUsesDynamicRoute): ?>
                        <input type="hidden" name="yk_route" value="<?php echo e($sidebarRoute); ?>">
                        <?php if (!$isProductType): ?>
                        <input type="hidden" name="slug" value="<?php echo e((string) ($channel['slug'] ?? '')); ?>">
                        <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($isProductType && $productCategory && !empty($productCategory['slug'])): ?>
                        <input type="hidden" name="cat" value="<?php echo e((string) $productCategory['slug']); ?>">
                        <?php endif; ?>
                        <div class="relative">
                            <input type="text" name="keyword" value="<?php echo e($keyword); ?>"
                                   placeholder="<?php echo __('search_placeholder'); ?>"
                                   class="w-full border rounded-lg pl-4 pr-10 py-2 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                            <button type="submit" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-primary">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </button>
                        </div>
                    </form>
                    <?php if ($keyword !== ''): ?>
                    <div class="mt-2 flex items-center justify-between text-sm">
                        <span class="text-gray-500"><?php echo __('search_result'); ?>: <span class="text-primary"><?php echo e($keyword); ?></span></span>
                        <a href="<?php echo channelUrl($channel); ?>" class="text-gray-400 hover:text-red-500">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($productCatalogShowCategories): ?>
                <!-- 分类菜单 -->
                <div class="bg-white rounded-lg shadow overflow-hidden sticky top-20" data-catalog-categories>
                    <!-- 分类标题 -->
                    <div class="bg-primary text-white px-4 py-3 font-bold" data-catalog-title>
                        <?php echo e($rootChannel['name']); ?>
                    </div>
                    <!-- 分类列表 -->
                    <div class="divide-y">
                        <?php if ($isProductType): ?>
                        <!-- 产品分类 -->
                        <a href="<?php echo channelUrl($rootChannel); ?>"
                           class="block px-4 py-3 hover:bg-gray-50 transition <?php echo ($productCategoryId === 0 && $keyword === '') ? 'text-primary font-medium bg-blue-50' : 'text-gray-700'; ?>">
                            <?php echo __('all'); ?><?php echo __('list_product'); ?>
                        </a>
                        <?php if ($channel['parent_id'] > 0): ?>
                        <a href="<?php echo ($channel['parent_id'] == (int)$rootChannel['id']) ? channelUrl($rootChannel) : productCategoryUrl(getChannel((int)$channel['parent_id'])); ?>"
                           class="block px-4 py-2 text-sm text-gray-500 hover:text-primary hover:bg-gray-50 transition">
                            ← <?php echo __('back'); ?>
                        </a>
                        <?php endif; ?>
                        <?php
                        // 递归渲染产品分类树
                        if (!function_exists('renderProductCategoryTree')) {
                        function renderProductCategoryTree(array $items, int $level, int $currentCatId): void {
                            foreach ($items as $item):
                                $hasChildren = !empty($item['children']);
                                $isExpanded = true;
                                $paddingLeft = 16 + ($level * 16);
                        ?>
                        <div class="category-item">
                            <div class="flex items-center justify-between hover:bg-gray-50 transition <?php echo $item['is_active'] ? 'text-primary font-medium bg-blue-50' : 'text-gray-700'; ?>">
                                <a href="<?php echo productCategoryUrl($item); ?>"
                                   class="flex-1 py-3 block"
                                   style="padding-left: <?php echo $paddingLeft; ?>px;">
                                    <?php echo e($item['name']); ?>
                                </a>
                                <?php if ($hasChildren): ?>
                                <button type="button" class="category-toggle px-4 py-3 text-gray-400 hover:text-primary"
                                        data-expanded="<?php echo $isExpanded ? 'true' : 'false'; ?>">
                                    <svg class="w-4 h-4 transition-transform <?php echo $isExpanded ? 'rotate-180' : ''; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </button>
                                <?php endif; ?>
                            </div>
                            <?php if ($hasChildren): ?>
                            <div class="category-children <?php echo $isExpanded ? '' : 'hidden'; ?>">
                                <?php renderProductCategoryTree($item['children'], $level + 1, $currentCatId); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php
                            endforeach;
                        }
                        }
                        renderProductCategoryTree($categoryTree, 0, $productCategoryId);
                        ?>
                        <?php else: ?>
                        <!-- 栏目分类（案例等） -->
                        <a href="<?php echo channelUrl($rootChannel); ?>"
                           class="block px-4 py-3 hover:bg-gray-50 transition <?php echo $channelId === (int)$rootChannel['id'] ? 'text-primary font-medium bg-blue-50' : 'text-gray-700'; ?>">
                            <?php echo __('all'); ?><?php echo e($rootChannel['name']); ?>
                        </a>
                        <?php
                        // 递归渲染栏目分类树
                        if (!function_exists('renderChannelTree')) {
                        function renderChannelTree(array $items, int $level = 0): void {
                            foreach ($items as $item):
                                $hasChildren = !empty($item['children']);
                                $isExpanded = true;
                                $paddingLeft = 16 + ($level * 16);
                        ?>
                        <div class="category-item">
                            <div class="flex items-center justify-between hover:bg-gray-50 transition <?php echo $item['is_active'] ? 'text-primary font-medium bg-blue-50' : 'text-gray-700'; ?>">
                                <a href="<?php echo channelUrl($item); ?>"
                                   class="flex-1 py-3 block"
                                   style="padding-left: <?php echo $paddingLeft; ?>px;">
                                    <?php echo e($item['name']); ?>
                                </a>
                                <?php if ($hasChildren): ?>
                                <button type="button" class="category-toggle px-4 py-3 text-gray-400 hover:text-primary"
                                        data-expanded="<?php echo $isExpanded ? 'true' : 'false'; ?>">
                                    <svg class="w-4 h-4 transition-transform <?php echo $isExpanded ? 'rotate-180' : ''; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                                    </svg>
                                </button>
                                <?php endif; ?>
                            </div>
                            <?php if ($hasChildren): ?>
                            <div class="category-children <?php echo $isExpanded ? '' : 'hidden'; ?>">
                                <?php renderChannelTree($item['children'], $level + 1); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php
                            endforeach;
                        }
                        }
                        renderChannelTree($categoryTree);
                        ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php // 多条件筛选面板（仅产品类型）；theme_path_optional 防主题缺该 partial 时 require 致命
                if ($productCatalogShowCategories && $isProductType && ($__pfPath = theme_path_optional('partials/product-filter.php'))) {
                    require $__pfPath;
                } ?>
            </div>
            <?php endif; ?>

            <!-- 右侧产品列表 -->
            <div class="flex-1 min-w-0">
                <?php if (!$productCatalogHasSidebar && $productCatalogShowSearch): ?>
                <form method="get" action="<?php echo $sidebarUsesDynamicRoute ? '/index.php' : $sidebarListUrl; ?>" class="mb-6 flex items-center gap-2 max-w-md">
                    <?php if ($sidebarUsesDynamicRoute): ?>
                    <input type="hidden" name="yk_route" value="<?php echo e($sidebarRoute); ?>">
                    <?php if (!$isProductType): ?>
                    <input type="hidden" name="slug" value="<?php echo e((string) ($channel['slug'] ?? '')); ?>">
                    <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($isProductType && $productCategory && !empty($productCategory['slug'])): ?>
                    <input type="hidden" name="cat" value="<?php echo e((string) $productCategory['slug']); ?>">
                    <?php endif; ?>
                    <div class="relative flex-1">
                        <input type="text" name="keyword" value="<?php echo e($keyword); ?>"
                               placeholder="<?php echo __('list_search_product'); ?>"
                               class="w-full border rounded-lg pl-4 pr-10 py-2 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                        <button type="submit" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-primary" aria-label="<?php echo e(__('search')); ?>">
                            <i class="ti ti-search text-lg"></i>
                        </button>
                    </div>
                    <?php if ($keyword !== ''): ?>
                    <a href="<?php echo channelUrl($channel); ?>" class="text-gray-400 hover:text-red-500" title="<?php echo e(__('search_clear')); ?>">
                        <i class="ti ti-x text-lg"></i>
                    </a>
                    <?php endif; ?>
                </form>
                <?php endif; ?>

                <!-- 列表头部 -->
                <div class="flex items-center justify-between mb-6 flex-wrap gap-2">
                    <div class="text-gray-600 text-sm">
                        <?php if ($keyword !== ''): ?>
                        <?php echo __('search_result'); ?> "<span class="text-primary font-medium"><?php echo e($keyword); ?></span>"
                        <?php endif; ?>
                        <?php echo __('list_total'); ?> <span class="text-primary font-medium"><?php echo $total; ?></span> <?php echo __('list_items'); ?>
                    </div>
                    <?php if ($productCatalogShowSort && $isProductType && !empty($enabledSorts) && count($enabledSorts) > 1): ?>
                    <div class="flex items-center gap-1.5 text-sm">
                        <?php foreach ($enabledSorts as $sortKey):
                            if (!isset(ProductModel::SORT_LABELS[$sortKey])) continue;
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

                <?php if (!empty($contents)): ?>
                <div class="<?php echo $productCatalogGridClass; ?>">
                    <?php foreach ($contents as $item): ?>
                    <?php require theme_path('partials/product-card.php'); ?>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center py-16 text-gray-500 bg-white rounded-lg">
                    <?php echo __('no_content'); ?>
                </div>
                <?php endif; ?>

                <!-- 分页 -->
                <?php
                $totalPages = (int)ceil($total / $perPage);
                $currentSort = $currentSort ?? 'default';
                $pageUrl = function(int $p) use ($channel, $keyword, $isProductType, $productCategory, $currentSort): string {
                    $extraParams = '';
                    if ($keyword !== '') $extraParams .= '&keyword=' . urlencode($keyword);
                    if ($isProductType && isset($currentSort) && $currentSort !== 'default') $extraParams .= '&sort=' . urlencode($currentSort);
                    // 多条件筛选参数随分页带上，翻页不丢筛选
                    foreach (['brand', 'tag', 'pmin', 'pmax'] as $fk) {
                        $fv = trim((string) ($_GET[$fk] ?? ''));
                        if ($fv !== '') $extraParams .= '&' . $fk . '=' . urlencode($fv);
                    }
                    $queryStr = $extraParams !== '' ? '?' . ltrim($extraParams, '&') : '';

                    if ($isProductType && $productCategory) {
                        $catSlug = $productCategory['slug'] ?? '';
                        if ($p === 1) {
                            return '/product/' . $catSlug . '.html' . $queryStr;
                        } else {
                            return '/product/' . $catSlug . '/page/' . $p . '.html' . $queryStr;
                        }
                    }
                    $slug = $channel['slug'] ?? '';
                    if ($p === 1) {
                        $url = $slug ? "/{$slug}.html" : "/list/{$channel['id']}.html";
                    } else {
                        $url = $slug ? "/{$slug}/page/{$p}.html" : "/list/{$channel['id']}/page/{$p}.html";
                    }
                    return $url . $queryStr;
                };
                require theme_path('partials/pagination.php');
                ?>
            </div>
        </div>
