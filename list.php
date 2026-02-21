<?php
/**
 * Yikai CMS - 列表页
 *
 * PHP 8.0+
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

$channelId = getInt('id');
$slug = get('slug');

// 通过slug或id获取栏目
if ($slug) {
    $channel = getChannelBySlug($slug);
    $channelId = $channel ? (int)$channel['id'] : 0;
} elseif ($channelId > 0) {
    $channel = getChannel($channelId);
} else {
    $channel = null;
}

if (!$channel || $channel['status'] != 1) {
    header('HTTP/1.1 404 Not Found');
    exit('栏目不存在');
}

// 如果是单页类型，直接加载单页（避免重定向循环）
if ($channel['type'] === 'page' && !defined('LOADING_FROM_LIST')) {
    define('LOADING_FROM_PAGE', true);
    $_GET['id'] = $channelId;
    $_GET['slug'] = $channel['slug'] ?? '';
    include __DIR__ . '/page.php';
    exit;
}

// 如果是链接类型，跳转
if ($channel['type'] === 'link' && $channel['link_url']) {
    header('Location: ' . $channel['link_url']);
    exit;
}

// 页面信息
$pageTitle = $channel['seo_title'] ?: $channel['name'];
$pageKeywords = $channel['seo_keywords'] ?: config('site_keywords');
$pageDescription = $channel['seo_description'] ?: config('site_description');
$currentChannelId = $channelId;

// 搜索关键词
$keyword = trim(get('keyword', ''));

// 分页
$page = max(1, getInt('page', 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

// 搜索条件
$whereConditions = [];
if ($keyword !== '') {
    $whereConditions['keyword'] = $keyword;
}

// 判断是否为产品类型 - 从产品表获取数据
$isProductType = ($channel['type'] === 'product');
$productCategory = null;
$productCategoryId = 0;

if ($isProductType) {
    // 产品类型：需要关联产品分类
    // 检查是否有分类参数
    $catSlug = get('cat', '');
    if ($catSlug) {
        // 通过分类slug获取产品分类
        $productCategory = getProductCategoryBySlug($catSlug);
    }

    if (!$productCategory) {
        // 没有指定分类或分类不存在，获取所有产品
        $productCategory = null;
        $productCategoryId = 0;
    } else {
        $productCategoryId = (int)$productCategory['id'];
    }

    // 获取产品列表
    $total = getProductsCount($productCategoryId, $whereConditions);
    $contents = getProducts($productCategoryId, $perPage, $offset, $whereConditions);
} elseif ($channel['type'] === 'download') {
    // 下载类型：从 yikai_downloads 表获取
    $dlCatId = getInt('cat');
    $dlFilters = ['status' => '1'];
    if ($keyword !== '') $dlFilters['keyword'] = $keyword;
    $dlResult = downloadModel()->getList($dlCatId, $dlFilters, $perPage, $offset);
    $total = $dlResult['total'];
    $downloads = $dlResult['items'];
    $contents = [];
} elseif ($channel['type'] === 'job') {
    // 招聘类型：从 yikai_jobs 表获取
    $jobFilters = ['status' => '1'];
    if ($keyword !== '') $jobFilters['keyword'] = $keyword;
    $jobResult = jobModel()->getList($jobFilters, $perPage, $offset);
    $total = $jobResult['total'];
    $jobs = $jobResult['items'];
    $contents = [];
} else {
    // 其他类型：从内容表获取
    $contentFilters = $whereConditions;
    $total = getContentsCount($channelId, $contentFilters);
    $contents = getContents($channelId, $perPage, $offset, $contentFilters);
}

// 获取子栏目（不限制is_nav，侧边栏/子导航显示所有子栏目）
$subChannels = getChannels($channelId, false);

// 获取父栏目
$parentChannel = null;
if ($channel['parent_id'] > 0) {
    $parentChannel = getChannel((int)$channel['parent_id']);
}

// 下载类型：使用 download_categories 做水平分类导航
$dlCategories = [];
$dlCatId = 0;
$rightSidebarChannels = [];
$rightSidebarTitle = '';
$rightSidebarActiveId = null; // null = 使用 $channelId
if ($channel['type'] === 'download') {
    $dlCategories = downloadCategoryModel()->getActive();
    $dlCatId = getInt('cat');
    // 右侧导航：使用父级栏目的子菜单
    if ($parentChannel) {
        $rightSidebarTitle = $parentChannel['name'];
        $rightSidebarChannels = getChannels((int)$parentChannel['id'], false);
    }
} elseif ($channel['parent_id'] > 0 && !in_array($channel['type'], ['product'])) {
    // 其他子栏目（如FAQ、招聘子栏目等）：右侧显示同级导航
    // 找到最顶层的非顶级父级
    $sidebarParent = $parentChannel;
    if ($sidebarParent) {
        $rightSidebarTitle = $sidebarParent['name'];
        $rightSidebarChannels = getChannels((int)$sidebarParent['id'], false);
    }
}

// 对于产品/案例类型，获取完整的分类树用于侧边栏
$rootChannel = $channel;
$rootProductCategory = null;
$categoryTree = [];
$showSidebar = in_array($channel['type'], ['product', 'case']);
$productLayout = $isProductType ? config('product_layout', 'sidebar') : 'sidebar';
$showProductTopNav = ($isProductType && $productLayout === 'top');
if ($showProductTopNav) {
    $showSidebar = false;
}

if ($showSidebar || $showProductTopNav) {
    if ($isProductType) {
        // 产品类型：从产品分类表获取完整分类树

        // 递归获取产品分类树
        function buildProductCategoryTree(int $parentId, int $currentId): array {
            $categories = productCategoryModel()->where(['parent_id' => $parentId, 'status' => 1]);
            $tree = [];
            foreach ($categories as $cat) {
                $cat['children'] = buildProductCategoryTree((int)$cat['id'], $currentId);
                $cat['is_active'] = ((int)$cat['id'] === $currentId);
                $cat['has_active_child'] = false;
                foreach ($cat['children'] as $child) {
                    if ($child['is_active'] || $child['has_active_child']) {
                        $cat['has_active_child'] = true;
                        break;
                    }
                }
                $tree[] = $cat;
            }
            return $tree;
        }

        // 始终从顶级开始构建完整分类树
        $categoryTree = buildProductCategoryTree(0, $productCategoryId);
    } else {
        // 非产品类型（如案例）：从栏目表获取
        $tempCh = $channel;
        while ($tempCh['parent_id'] > 0) {
            $parent = getChannel((int)$tempCh['parent_id']);
            if ($parent) {
                $tempCh = $parent;
            } else {
                break;
            }
        }
        $rootChannel = $tempCh;

        // 递归获取栏目分类树
        function buildChannelCategoryTree(int $parentId, int $currentId): array {
            $channels = channelModel()->getByParent($parentId, true);
            $tree = [];
            foreach ($channels as $ch) {
                $ch['children'] = buildChannelCategoryTree((int)$ch['id'], $currentId);
                $ch['is_active'] = ((int)$ch['id'] === $currentId);
                $ch['has_active_child'] = false;
                foreach ($ch['children'] as $child) {
                    if ($child['is_active'] || $child['has_active_child']) {
                        $ch['has_active_child'] = true;
                        break;
                    }
                }
                $tree[] = $ch;
            }
            return $tree;
        }

        $categoryTree = buildChannelCategoryTree((int)$rootChannel['id'], $channelId);
    }
}

// 顶栏模式：从分类树中提取大类和小类
$topCategories = [];
$activeTopCatId = 0;
$topSubCategories = [];
if ($showProductTopNav && !empty($categoryTree)) {
    $topCategories = $categoryTree;
    if ($productCategoryId > 0) {
        foreach ($topCategories as $topCat) {
            if ((int)$topCat['id'] === $productCategoryId) {
                $activeTopCatId = (int)$topCat['id'];
                $topSubCategories = $topCat['children'] ?? [];
                break;
            }
            foreach ($topCat['children'] ?? [] as $child) {
                if ((int)$child['id'] === $productCategoryId) {
                    $activeTopCatId = (int)$topCat['id'];
                    $topSubCategories = $topCat['children'] ?? [];
                    break 2;
                }
            }
        }
    }
}

// 获取导航
$navChannels = getNavChannels();

// 引入头部
require_once INCLUDES_PATH . 'header.php';
?>

<?php
// 准备面包屑数据
$breadcrumbItems = [];
if ($isProductType) {
    // 产品分类面包屑
    $breadcrumbItems[] = ['name' => $channel['name'], 'url' => channelUrl($channel)];
    if ($productCategory) {
        $tempCat = $productCategory;
        $catPath = [];
        while ($tempCat) {
            array_unshift($catPath, $tempCat);
            $tempCat = $tempCat['parent_id'] > 0 ? getProductCategory((int)$tempCat['parent_id']) : null;
        }
        foreach ($catPath as $cat) {
            $breadcrumbItems[] = ['name' => $cat['name'], 'url' => productCategoryUrl($cat)];
        }
    }
} else {
    // 栏目面包屑
    $tempChannel = $channel;
    while ($tempChannel) {
        array_unshift($breadcrumbItems, ['name' => $tempChannel['name'], 'url' => channelUrl($tempChannel)]);
        $tempChannel = $tempChannel['parent_id'] > 0 ? getChannel((int)$tempChannel['parent_id']) : null;
    }
}
?>

<!-- 页面头部 -->
<?php if ($channel['image']): ?>
<section class="relative py-16 bg-cover bg-center" style="background-image: url('<?php echo e($channel['image']); ?>')">
    <div class="absolute inset-0 bg-black/60"></div>
    <div class="container mx-auto px-4 relative">
        <!-- 面包屑导航 -->
        <div class="flex items-center gap-2 text-sm text-gray-300 mb-6">
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
        <div class="text-center">
            <h1 class="text-4xl md:text-5xl font-bold text-white mb-4"><?php echo e($channel['name']); ?></h1>
            <?php if ($channel['description']): ?>
            <p class="text-gray-200 text-lg max-w-2xl mx-auto"><?php echo e($channel['description']); ?></p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php else: ?>
<section class="bg-gradient-to-r from-gray-900 via-gray-800 to-gray-900 py-16 relative overflow-hidden">
    <div class="absolute inset-0 opacity-20">
        <div class="absolute top-0 left-1/4 w-96 h-96 bg-primary rounded-full blur-3xl"></div>
        <div class="absolute bottom-0 right-1/4 w-96 h-96 bg-secondary rounded-full blur-3xl"></div>
    </div>
    <div class="container mx-auto px-4 relative">
        <!-- 面包屑导航 -->
        <div class="flex items-center gap-2 text-sm text-gray-400 mb-6">
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
        <div class="text-center">
            <h1 class="text-4xl md:text-5xl font-bold text-white mb-4"><?php echo e($channel['name']); ?></h1>
            <?php if ($channel['description']): ?>
            <p class="text-gray-300 text-lg max-w-2xl mx-auto"><?php echo e($channel['description']); ?></p>
            <?php endif; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- 子栏目导航（横向分类标签） -->
<?php
$horizNav = $subChannels;
$horizRootChannel = $channel;
// 下载类型不使用 channel 子栏目做水平导航（改用 download_categories）
?>
<?php if ($showProductTopNav): ?>
<!-- 产品顶栏模式：分类筛选面板 -->
<div class="bg-white border-b">
    <div class="container mx-auto px-4 py-4">
        <!-- 搜索框 -->
        <div class="flex items-center justify-between mb-4">
            <div class="text-sm text-gray-500">
                <?php echo __('list_total'); ?> <span class="text-primary font-medium"><?php echo $total; ?></span> <?php echo __('list_items'); ?>
                <?php if ($keyword !== ''): ?>
                ，搜索 "<span class="text-primary"><?php echo e($keyword); ?></span>"
                <?php endif; ?>
            </div>
            <form method="get" action="<?php echo channelUrl($channel); ?>" class="flex items-center gap-2">
                <div class="relative">
                    <input type="text" name="keyword" value="<?php echo e($keyword); ?>"
                           placeholder="搜索产品..."
                           class="w-48 border rounded-full pl-4 pr-9 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <button type="submit" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-primary">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </button>
                </div>
                <?php if ($keyword !== ''): ?>
                <a href="<?php echo channelUrl($channel); ?>" class="text-gray-400 hover:text-red-500" title="清除搜索">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </a>
                <?php endif; ?>
            </form>
        </div>
        <!-- 分类筛选 -->
        <div class="border rounded-lg text-sm">
            <div class="px-4 py-2 bg-gray-50 border-b flex items-center gap-2">
                <span class="text-gray-600 font-medium">产品分类:</span>
                <a href="<?php echo channelUrl($channel); ?>" class="<?php echo $productCategoryId === 0 && $keyword === '' ? 'text-primary font-medium' : 'text-gray-500 hover:text-primary'; ?>">全部产品</a>
            </div>
            <div class="divide-y">
            <?php foreach ($topCategories as $tc):
                $isActiveTop = ($activeTopCatId === (int)$tc['id']);
                $children = $tc['children'] ?? [];
            ?>
            <div class="flex">
                <a href="<?php echo productCategoryUrl($tc); ?>"
                   class="flex-shrink-0 w-28 px-4 py-2.5 font-medium <?php echo $isActiveTop ? 'bg-primary/5 text-primary' : 'text-gray-700 hover:text-primary'; ?>"><?php echo e($tc['name']); ?></a>
                <?php if (!empty($children)): ?>
                <div class="flex-1 flex flex-wrap items-center px-4 py-2.5 border-l">
                    <?php foreach ($children as $i => $sc): ?>
                    <?php if ($i > 0): ?>&nbsp;&nbsp;&nbsp;&nbsp;<?php endif; ?>
                    <a href="<?php echo productCategoryUrl($sc); ?>"
                       class="<?php echo $productCategoryId === (int)$sc['id'] ? 'text-primary font-medium' : 'text-gray-500 hover:text-primary'; ?>"><?php echo e($sc['name']); ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>
<?php elseif (!$showSidebar && $channel['type'] !== 'download'): ?>
<div class="bg-white border-b">
    <div class="container mx-auto px-4">
        <div class="flex flex-wrap items-center justify-between gap-4 py-4">
            <?php if (!empty($horizNav)): ?>
            <div class="flex flex-wrap gap-3">
                <a href="<?php echo channelUrl($horizRootChannel); ?>"
                   class="px-4 py-2 rounded-full text-sm <?php echo $channelId === (int)$horizRootChannel['id'] && $keyword === '' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                    <?php echo __('all'); ?>
                </a>
                <?php foreach ($horizNav as $sub): ?>
                <a href="<?php echo channelUrl($sub); ?>"
                   class="px-4 py-2 rounded-full text-sm <?php echo (int)$sub['id'] === $channelId ? 'bg-primary text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                    <?php echo e($sub['name']); ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div></div>
            <?php endif; ?>
            <form method="get" action="<?php echo channelUrl($channel); ?>" class="flex items-center gap-2">
                <div class="relative">
                    <input type="text" name="keyword" value="<?php echo e($keyword); ?>"
                           placeholder="搜索..."
                           class="w-48 border rounded-full pl-4 pr-9 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <button type="submit" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-primary">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </button>
                </div>
                <?php if ($keyword !== ''): ?>
                <a href="<?php echo channelUrl($channel); ?>" class="text-gray-400 hover:text-red-500" title="清除搜索">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </a>
                <?php endif; ?>
            </form>
        </div>
        <?php if ($keyword !== ''): ?>
        <div class="pb-3 text-sm text-gray-500">
            搜索 "<span class="text-primary"><?php echo e($keyword); ?></span>" 共 <span class="text-primary font-medium"><?php echo $total; ?></span> 条结果
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- 内容列表 -->
<section class="py-12">
    <div class="container mx-auto px-4">
        <?php if ($showProductTopNav): ?>
        <!-- 产品顶栏模式：全宽4列网格 -->
        <?php if (!empty($contents)): ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
            <?php foreach ($contents as $item): ?>
            <a href="<?php echo productUrl($item); ?>" class="group bg-white rounded-lg overflow-hidden shadow hover:shadow-lg transition relative">
                <div class="aspect-[4/3] overflow-hidden relative">
                    <?php if ($item['cover']): ?>
                    <img src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>"
                         class="w-full h-full object-cover group-hover:scale-110 transition duration-500">
                    <?php else: ?>
                    <div class="w-full h-full bg-gray-200 flex items-center justify-center text-gray-400">
                        <?php echo __('admin_no_image'); ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($item['is_new']) || !empty($item['is_hot']) || !empty($item['is_recommend'])): ?>
                    <div class="absolute top-2 left-2 flex flex-col gap-1">
                        <?php if (!empty($item['is_new'])): ?>
                        <span class="bg-green-500 text-white text-xs px-2 py-0.5 rounded">NEW</span>
                        <?php endif; ?>
                        <?php if (!empty($item['is_hot'])): ?>
                        <span class="bg-red-500 text-white text-xs px-2 py-0.5 rounded">HOT</span>
                        <?php endif; ?>
                        <?php if (!empty($item['is_recommend'])): ?>
                        <span class="bg-primary text-white text-xs px-2 py-0.5 rounded">推荐</span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="p-4">
                    <h3 class="font-bold text-dark group-hover:text-primary transition line-clamp-2">
                        <?php echo e($item['title']); ?>
                    </h3>
                    <?php if (!empty($item['model'])): ?>
                    <p class="text-xs text-gray-400 mt-1"><?php echo e($item['model']); ?></p>
                    <?php endif; ?>
                    <?php if (config('show_price', '0') === '1' && !empty($item['price']) && $item['price'] > 0): ?>
                    <div class="mt-2 text-primary font-bold">&yen;<?php echo number_format((float)$item['price'], 2); ?></div>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="text-center py-16 text-gray-500 bg-white rounded-lg">
            <?php echo __('no_content'); ?>
        </div>
        <?php endif; ?>

        <!-- 分页 -->
        <?php if ($total > $perPage): ?>
        <?php
        $totalPages = (int)ceil($total / $perPage);
        $pageUrl = function(int $p) use ($channel, $keyword, $productCategory): string {
            if ($productCategory) {
                $catSlug = $productCategory['slug'] ?? '';
                $keywordParam = $keyword !== '' ? '?keyword=' . urlencode($keyword) : '';
                return $p === 1 ? '/product/' . $catSlug . '.html' . $keywordParam : '/product/' . $catSlug . '/page/' . $p . '.html' . $keywordParam;
            }
            $slug = $channel['slug'] ?? '';
            $keywordParam = $keyword !== '' ? '?keyword=' . urlencode($keyword) : '';
            $url = $p === 1 ? ($slug ? "/{$slug}.html" : "/list/{$channel['id']}.html") : ($slug ? "/{$slug}/page/{$p}.html" : "/list/{$channel['id']}/page/{$p}.html");
            return $url . $keywordParam;
        };
        ?>
        <div class="mt-8 flex items-center justify-center gap-2">
            <?php if ($page > 1): ?>
            <a href="<?php echo $pageUrl($page - 1); ?>" class="px-4 py-2 border rounded hover:bg-gray-100"><?php echo __('list_prev_page'); ?></a>
            <?php endif; ?>
            <?php
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
            <a href="<?php echo $pageUrl($i); ?>"
               class="px-4 py-2 border rounded <?php echo $i === $page ? 'bg-primary text-white border-primary' : 'hover:bg-gray-100'; ?>">
                <?php echo $i; ?>
            </a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="<?php echo $pageUrl($page + 1); ?>" class="px-4 py-2 border rounded hover:bg-gray-100"><?php echo __('list_next_page'); ?></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php elseif ($showSidebar): ?>
        <!-- 产品/案例：带侧边栏布局 -->
        <div class="flex flex-wrap lg:flex-nowrap gap-8">
            <!-- 左侧分类菜单 -->
            <div class="w-full lg:w-64 flex-shrink-0 space-y-4">
                <!-- 搜索框 -->
                <div class="bg-white rounded-lg shadow p-4">
                    <form method="get" action="<?php echo channelUrl($channel); ?>">
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

                <!-- 分类菜单 -->
                <div class="bg-white rounded-lg shadow overflow-hidden sticky top-20">
                    <!-- 分类标题 -->
                    <div class="bg-primary text-white px-4 py-3 font-bold">
                        <?php echo e($rootChannel['name']); ?>
                    </div>
                    <!-- 分类列表 -->
                    <div class="divide-y">
                        <?php if ($isProductType): ?>
                        <!-- 产品分类 -->
                        <a href="<?php echo channelUrl($rootChannel); ?>"
                           class="block px-4 py-3 hover:bg-gray-50 transition <?php echo $productCategoryId === 0 ? 'text-primary font-medium bg-blue-50' : 'text-gray-700'; ?>">
                            <?php echo __('all'); ?><?php echo e($rootChannel['name']); ?>
                        </a>
                        <?php
                        // 递归渲染产品分类树
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
                        renderChannelTree($categoryTree);
                        ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 右侧产品列表 -->
            <div class="flex-1 min-w-0">
                <!-- 列表头部 -->
                <div class="flex items-center justify-between mb-6">
                    <div class="text-gray-600">
                        <?php if ($keyword !== ''): ?>
                        <?php echo __('search_result'); ?> "<span class="text-primary font-medium"><?php echo e($keyword); ?></span>"
                        <?php endif; ?>
                        <?php echo __('list_total'); ?> <span class="text-primary font-medium"><?php echo $total; ?></span> <?php echo __('list_items'); ?>
                    </div>
                </div>

                <?php if (!empty($contents)): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($contents as $item): ?>
                    <a href="<?php echo $isProductType ? productUrl($item) : contentUrl($item); ?>" class="group bg-white rounded-lg overflow-hidden shadow hover:shadow-lg transition relative">
                        <div class="aspect-[4/3] overflow-hidden relative">
                            <?php if ($item['cover']): ?>
                            <img src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>"
                                 class="w-full h-full object-cover group-hover:scale-110 transition duration-500">
                            <?php else: ?>
                            <div class="w-full h-full bg-gray-200 flex items-center justify-center text-gray-400">
                                <?php echo __('admin_no_image'); ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($isProductType && (!empty($item['is_new']) || !empty($item['is_hot']) || !empty($item['is_recommend']))): ?>
                            <div class="absolute top-2 left-2 flex flex-col gap-1">
                                <?php if (!empty($item['is_new'])): ?>
                                <span class="bg-green-500 text-white text-xs px-2 py-0.5 rounded">NEW</span>
                                <?php endif; ?>
                                <?php if (!empty($item['is_hot'])): ?>
                                <span class="bg-red-500 text-white text-xs px-2 py-0.5 rounded">HOT</span>
                                <?php endif; ?>
                                <?php if (!empty($item['is_recommend'])): ?>
                                <span class="bg-primary text-white text-xs px-2 py-0.5 rounded">推荐</span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="p-4">
                            <h3 class="font-bold text-dark group-hover:text-primary transition line-clamp-2">
                                <?php echo e($item['title']); ?>
                            </h3>
                            <?php if ($isProductType && !empty($item['model'])): ?>
                            <p class="text-xs text-gray-400 mt-1"><?php echo e($item['model']); ?></p>
                            <?php endif; ?>
                            <?php if (config('show_price', '0') === '1' && $isProductType && !empty($item['price']) && $item['price'] > 0): ?>
                            <div class="mt-2 text-primary font-bold">&yen;<?php echo number_format((float)$item['price'], 2); ?></div>
                            <?php endif; ?>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center py-16 text-gray-500 bg-white rounded-lg">
                    <?php echo __('no_content'); ?>
                </div>
                <?php endif; ?>

                <!-- 分页 -->
                <?php if ($total > $perPage): ?>
                <?php
                $totalPages = (int)ceil($total / $perPage);
                $pageUrl = function(int $p) use ($channel, $keyword, $isProductType, $productCategory): string {
                    if ($isProductType && $productCategory) {
                        // 产品分类分页
                        $catSlug = $productCategory['slug'] ?? '';
                        $keywordParam = $keyword !== '' ? '?keyword=' . urlencode($keyword) : '';
                        if ($p === 1) {
                            return '/product/' . $catSlug . '.html' . $keywordParam;
                        } else {
                            return '/product/' . $catSlug . '/page/' . $p . '.html' . $keywordParam;
                        }
                    }
                    $slug = $channel['slug'] ?? '';
                    $keywordParam = $keyword !== '' ? '?keyword=' . urlencode($keyword) : '';
                    if ($p === 1) {
                        $url = $slug ? "/{$slug}.html" : "/list/{$channel['id']}.html";
                    } else {
                        $url = $slug ? "/{$slug}/page/{$p}.html" : "/list/{$channel['id']}/page/{$p}.html";
                    }
                    return $url . $keywordParam;
                };
                ?>
                <div class="mt-8 flex items-center justify-center gap-2">
                    <?php if ($page > 1): ?>
                    <a href="<?php echo $pageUrl($page - 1); ?>" class="px-4 py-2 border rounded hover:bg-gray-100"><?php echo __('list_prev_page'); ?></a>
                    <?php endif; ?>
                    <?php
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $page + 2);
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                    <a href="<?php echo $pageUrl($i); ?>"
                       class="px-4 py-2 border rounded <?php echo $i === $page ? 'bg-primary text-white border-primary' : 'hover:bg-gray-100'; ?>">
                        <?php echo $i; ?>
                    </a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?>
                    <a href="<?php echo $pageUrl($page + 1); ?>" class="px-4 py-2 border rounded hover:bg-gray-100"><?php echo __('list_next_page'); ?></a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php elseif ($channel['type'] === 'download'): ?>
        <!-- 下载：表格 + 右侧导航（数据来自 yikai_downloads 表） -->
        <div class="flex flex-wrap lg:flex-nowrap gap-8">
            <div class="w-full <?php echo !empty($rightSidebarChannels) ? 'lg:flex-1' : ''; ?>">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-6">
                    <?php if (!empty($dlCategories)): ?>
                    <div class="flex flex-wrap gap-3">
                        <a href="<?php echo channelUrl($channel); ?>"
                           class="px-4 py-2 rounded-full text-sm <?php echo $dlCatId === 0 && $keyword === '' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                            <?php echo __('all'); ?>
                        </a>
                        <?php foreach ($dlCategories as $dcat): ?>
                        <a href="<?php echo channelUrl($channel); ?>?cat=<?php echo $dcat['id']; ?>"
                           class="px-4 py-2 rounded-full text-sm <?php echo $dlCatId === (int)$dcat['id'] ? 'bg-primary text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                            <?php echo e($dcat['name']); ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div></div>
                    <?php endif; ?>
                    <form method="get" action="<?php echo channelUrl($channel); ?>" class="flex items-center gap-2">
                        <?php if ($dlCatId > 0): ?>
                        <input type="hidden" name="cat" value="<?php echo $dlCatId; ?>">
                        <?php endif; ?>
                        <div class="relative">
                            <input type="text" name="keyword" value="<?php echo e($keyword); ?>"
                                   placeholder="搜索下载..."
                                   class="w-48 border rounded-full pl-4 pr-9 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                            <button type="submit" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-primary">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </button>
                        </div>
                        <?php if ($keyword !== ''): ?>
                        <a href="<?php echo channelUrl($channel); ?><?php echo $dlCatId > 0 ? '?cat=' . $dlCatId : ''; ?>" class="text-gray-400 hover:text-red-500" title="清除搜索">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </a>
                        <?php endif; ?>
                    </form>
                </div>
                <?php if ($keyword !== ''): ?>
                <div class="mb-4 text-sm text-gray-500">
                    搜索 "<span class="text-primary"><?php echo e($keyword); ?></span>" 共 <span class="text-primary font-medium"><?php echo $total; ?></span> 条结果
                </div>
                <?php endif; ?>
                <?php if (!empty($downloads)): ?>
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-sm font-medium text-gray-500"><?php echo __('download_filename'); ?></th>
                                <th class="px-6 py-3 text-center text-sm font-medium text-gray-500 hidden md:table-cell"><?php echo __('download_date'); ?></th>
                                <th class="px-6 py-3 text-center text-sm font-medium text-gray-500 hidden md:table-cell"><?php echo __('download_count'); ?></th>
                                <th class="px-6 py-3 text-center text-sm font-medium text-gray-500"><?php echo __('download_action'); ?></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <?php foreach ($downloads as $item): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <?php
                                        $extClass = match(strtolower($item['file_ext'])) {
                                            'pdf' => 'bg-red-100 text-red-600',
                                            'doc', 'docx' => 'bg-blue-100 text-blue-600',
                                            'xls', 'xlsx' => 'bg-green-100 text-green-600',
                                            'zip', 'rar', '7z' => 'bg-purple-100 text-purple-600',
                                            'exe', 'msi' => 'bg-gray-100 text-gray-600',
                                            default => 'bg-gray-100 text-gray-500',
                                        };
                                        ?>
                                        <span class="flex-shrink-0 w-9 h-9 <?php echo $extClass; ?> rounded flex items-center justify-center text-xs font-bold">
                                            <?php echo strtoupper($item['file_ext']) ?: '?'; ?>
                                        </span>
                                        <div>
                                            <span class="text-dark hover:text-primary font-medium"><?php echo e($item['title']); ?></span>
                                            <?php if (!empty($item['description'])): ?>
                                            <div class="text-xs text-gray-400 mt-0.5 line-clamp-1"><?php echo e($item['description']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center text-sm text-gray-500 hidden md:table-cell">
                                    <?php echo $item['created_at'] > 0 ? date('Y-m-d', (int)$item['created_at']) : '-'; ?>
                                </td>
                                <td class="px-6 py-4 text-center text-sm text-gray-500 hidden md:table-cell">
                                    <?php echo number_format((int)$item['download_count']); ?>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <?php if ($item['file_url']): ?>
                                    <a href="/download.php?fid=<?php echo $item['id']; ?>"
                                       class="inline-flex items-center gap-1 text-primary hover:underline text-sm">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                                        </svg>
                                        <?php echo __('download_btn'); ?>
                                    </a>
                                    <?php if (!empty($item['require_login'])): ?>
                                    <div class="text-xs text-orange-500 mt-1">需登录</div>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <span class="text-gray-400 text-sm">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="text-center py-16 text-gray-500 bg-white rounded-lg">
                    <?php echo __('no_content'); ?>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($rightSidebarChannels)): ?>
            <?php require __DIR__ . '/includes/partials/right_sidebar.php'; ?>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <!-- 其他类型的原有布局 -->
        <?php $hasRightSidebar = !empty($rightSidebarChannels); ?>
        <?php if ($hasRightSidebar): ?><div class="flex flex-wrap lg:flex-nowrap gap-8"><div class="w-full lg:flex-1"><?php endif; ?>

        <?php if (!empty($contents) || !empty($jobs ?? [])): ?>

        <?php if ($channel['type'] === 'case'): ?>
        <!-- 案例：图文网格 -->
        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-6">
            <?php foreach ($contents as $item): ?>
            <a href="<?php echo contentUrl($item); ?>" class="group bg-white rounded-lg overflow-hidden shadow hover:shadow-lg transition">
                <div class="aspect-[4/3] overflow-hidden">
                    <?php if ($item['cover']): ?>
                    <img src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>"
                         class="w-full h-full object-cover group-hover:scale-110 transition duration-500">
                    <?php else: ?>
                    <div class="w-full h-full bg-gray-200 flex items-center justify-center text-gray-400">
                        <?php echo __('admin_no_image'); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="p-4">
                    <h3 class="font-bold text-dark group-hover:text-primary transition line-clamp-2">
                        <?php echo e($item['title']); ?>
                    </h3>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <?php elseif ($channel['type'] === 'job'): ?>
        <!-- 招聘：卡片列表（数据来自 yikai_jobs 表） -->
        <?php if (!empty($jobs)): ?>
        <div class="space-y-4">
            <?php foreach ($jobs as $item): ?>
            <a href="<?php echo jobUrl($item); ?>" class="block bg-white rounded-lg shadow p-6 hover:shadow-lg transition group">
                <div class="flex flex-wrap gap-4 items-start justify-between">
                    <div class="flex-1 min-w-0">
                        <h3 class="text-lg font-bold text-dark group-hover:text-primary transition"><?php echo e($item['title']); ?></h3>
                        <div class="flex flex-wrap gap-2 mt-2">
                            <?php if ($item['job_type']): ?>
                            <span class="px-2 py-0.5 bg-blue-100 text-blue-700 text-xs rounded"><?php echo e($item['job_type']); ?></span>
                            <?php endif; ?>
                            <?php if ($item['education']): ?>
                            <span class="px-2 py-0.5 bg-green-100 text-green-700 text-xs rounded"><?php echo e($item['education']); ?></span>
                            <?php endif; ?>
                            <?php if ($item['experience']): ?>
                            <span class="px-2 py-0.5 bg-orange-100 text-orange-700 text-xs rounded"><?php echo e($item['experience']); ?></span>
                            <?php endif; ?>
                            <?php if ($item['headcount']): ?>
                            <span class="px-2 py-0.5 bg-purple-100 text-purple-700 text-xs rounded">招<?php echo e($item['headcount']); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="flex flex-wrap gap-4 mt-2 text-sm text-gray-500">
                            <?php if ($item['location']): ?>
                            <span class="flex items-center gap-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                </svg>
                                <?php echo e($item['location']); ?>
                            </span>
                            <?php endif; ?>
                            <span><?php echo friendlyTime((int)$item['publish_time']); ?></span>
                        </div>
                    </div>
                    <?php if ($item['salary']): ?>
                    <div class="text-primary font-bold text-lg flex-shrink-0"><?php echo e($item['salary']); ?></div>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <!-- 文章：图文列表 -->
        <div class="space-y-6">
            <?php foreach ($contents as $item): ?>
            <a href="<?php echo contentUrl($item); ?>" class="flex gap-6 bg-white rounded-lg shadow overflow-hidden hover:shadow-lg transition group">
                <?php if ($item['cover']): ?>
                <div class="flex-shrink-0 w-48 md:w-64 overflow-hidden">
                    <img src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>"
                         class="w-full h-full object-cover group-hover:scale-110 transition duration-500">
                </div>
                <?php endif; ?>
                <div class="flex-1 py-4 pr-4 <?php echo $item['cover'] ? '' : 'pl-4'; ?>">
                    <h3 class="text-lg font-bold text-dark group-hover:text-primary transition line-clamp-2">
                        <?php if ($item['is_top']): ?>
                        <span class="text-xs bg-red-500 text-white px-1.5 py-0.5 rounded mr-2">置顶</span>
                        <?php endif; ?>
                        <?php echo e($item['title']); ?>
                    </h3>
                    <p class="mt-2 text-gray-500 text-sm line-clamp-2">
                        <?php echo e($item['summary'] ?: cutStr(strip_tags($item['content']), 120)); ?>
                    </p>
                    <div class="mt-3 flex items-center gap-4 text-xs text-gray-400">
                        <?php if ($item['author']): ?>
                        <span><?php echo e($item['author']); ?></span>
                        <?php endif; ?>
                        <span><?php echo date('Y-m-d', (int)$item['publish_time']); ?></span>
                        <span><?php echo __('detail_views'); ?> <?php echo number_format((int)$item['views']); ?></span>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- 分页 -->
        <?php if ($total > $perPage): ?>
        <?php
        $totalPages = (int)ceil($total / $perPage);
        // 生成分页URL
        $pageUrl = function(int $p) use ($channel, $keyword): string {
            $slug = $channel['slug'] ?? '';
            $keywordParam = $keyword !== '' ? '?keyword=' . urlencode($keyword) : '';
            if ($p === 1) {
                $url = $slug ? "/{$slug}.html" : "/list/{$channel['id']}.html";
            } else {
                $url = $slug ? "/{$slug}/page/{$p}.html" : "/list/{$channel['id']}/page/{$p}.html";
            }
            return $url . $keywordParam;
        };
        ?>
        <div class="mt-8 flex items-center justify-center gap-2">
            <?php if ($page > 1): ?>
            <a href="<?php echo $pageUrl($page - 1); ?>"
               class="px-4 py-2 border rounded hover:bg-gray-100"><?php echo __('list_prev_page'); ?></a>
            <?php endif; ?>

            <?php
            $start = max(1, $page - 2);
            $end = min($totalPages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
            <a href="<?php echo $pageUrl($i); ?>"
               class="px-4 py-2 border rounded <?php echo $i === $page ? 'bg-primary text-white border-primary' : 'hover:bg-gray-100'; ?>">
                <?php echo $i; ?>
            </a>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
            <a href="<?php echo $pageUrl($page + 1); ?>"
               class="px-4 py-2 border rounded hover:bg-gray-100"><?php echo __('list_next_page'); ?></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <div class="text-center py-16 text-gray-500">
            <?php echo __('no_content'); ?>
        </div>
        <?php endif; ?>

        <?php if ($hasRightSidebar): ?>
        </div>
        <?php require __DIR__ . '/includes/partials/right_sidebar.php'; ?>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php if ($showSidebar): ?>
<script>
// 分类菜单展开/收起
document.querySelectorAll('.category-toggle').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        var item = this.closest('.category-item');
        var children = item.querySelector('.category-children');
        var icon = this.querySelector('svg');
        var expanded = this.dataset.expanded === 'true';

        if (expanded) {
            children.classList.add('hidden');
            icon.classList.remove('rotate-180');
            this.dataset.expanded = 'false';
        } else {
            children.classList.remove('hidden');
            icon.classList.add('rotate-180');
            this.dataset.expanded = 'true';
        }
    });
});
</script>
<?php endif; ?>

<?php require_once INCLUDES_PATH . 'footer.php'; ?>
