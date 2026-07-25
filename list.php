<?php
/**
 * Yikai CMS - 列表页
 *
 * PHP 8.0+
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

HtmlCache::start(300);

$channelId = getInt('id');
$slug = get('slug');

// 通过slug或id获取栏目（lang-aware：当前是非源语言时自动跳到翻译行）
if ($slug) {
    $channel = getChannelBySlug($slug, true);
    // 固定前缀路由兜底：/product/{分类}.html 重写固定携带 slug=product（见 .htaccess）。
    // 站点若把产品栏目别名改成了别的值（如 products），按栏目类型回退定位，避免产品分类页整线 404。
    if (!$channel && $slug === 'product') {
        $channel = channelModel()->findWhere(['type' => 'product', 'lang' => siteLang(), 'status' => 1]) ?: null;
    }
    $channelId = $channel ? (int)$channel['id'] : 0;
} elseif ($channelId > 0) {
    $channel = getChannel($channelId);
} else {
    $channel = null;
}

if (!$channel || $channel['status'] != 1) {
    header('HTTP/1.1 404 Not Found');
    render404(__('error_channel_not_found'));
}

// page / link 走 PageRedirectController（短路：要么 include page.php，要么 302）
require_once __DIR__ . '/controllers/list/ListRouter.php';
if (in_array($channel['type'], ['page', 'link'], true)) {
    $_short = ListRouter::dispatch($channel['type']);
    if ($_short->shortCircuit($channel)) {
        exit;
    }
    unset($_short);
}

// 页面信息
$pageTitle = $channel['seo_title'] ?: $channel['name'];
$pageKeywords = $channel['seo_keywords'] ?: configJsonLang('site_keywords');
$pageDescription = $channel['seo_description'] ?: configJsonLang('site_description');
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

// 全部类型走 ListRouter 调度，已无 inline 分支。
// 见 docs/refactor-list-detail-plan.md。
$isProductType     = ($channel['type'] === 'product');
$productCategory   = null;
$productCategoryId = 0;
$_request = [
    'channelId' => $channelId,
    'slug'      => $slug,
    'page'      => $page,
    'perPage'   => $perPage,
    'keyword'   => $keyword,
    'cat'       => get('cat', ''),
    'sort'      => get('sort', ''),
];
$_vars = ListRouter::dispatch($channel['type'])->prepare($channel, $_request);
// 把 controller 的视图变量解构进当前作用域。
// 'subChannels' / 'parentChannel' / 'rightSidebar*' 由下方原有逻辑覆写，
// 但 'contents' / 'total' / 'downloads' / 'jobs' / 产品相关变量由 controller 决定。
foreach (['contents', 'total', 'downloads', 'jobs', 'dlCatId',
          'productCategory', 'productCategoryId', 'currentSort',
          'enabledSorts', 'whereConditions',
          'facetBrands', 'facetTagGroups', 'facetPrice', 'filterActive',
          'selBrandIds', 'selTagIds', 'filterPriceMin', 'filterPriceMax'] as $_k) {
    if (array_key_exists($_k, $_vars)) {
        $$_k = $_vars[$_k];
    }
}
unset($_request, $_vars, $_k);

// 获取子栏目（不限制is_nav，侧边栏/子导航显示所有子栏目）
$subChannels = getChannels($channelId, false);

// 获取父栏目
$parentChannel = null;
if ($channel['parent_id'] > 0) {
    $parentChannel = getChannel((int)$channel['parent_id']);
}

// 下载类型：分类统一用 download_categories（下载记录只有 category_id，无 channel_id）
$dlCategories = [];
$dlCatId = 0;
$rightSidebarChannels = [];
$rightSidebarItems = null;    // 预构建的分类链接，供 right_sidebar.php 渲染
$rightSidebarTitle = '';
$rightSidebarActiveId = null; // null = 使用 $channelId
if ($channel['type'] === 'download') {
    $dlCategories = downloadCategoryModel()->getActive();
    // cat 支持 slug（伪静态 /download/{slug}.html）或数字 id（兼容旧 ?cat=1）
    $dlCatId = downloadCategoryModel()->resolveId((string) get('cat', ''));
    // 右侧导航：用 download_categories（与后台编辑/筛选同一套），不用子栏目
    if (!empty($dlCategories)) {
        $rightSidebarTitle = __('label_category');
        $rightSidebarItems = [[
            'label'  => __('all'),
            'url'    => channelUrl($channel),
            'active' => $dlCatId === 0,
        ]];
        foreach ($dlCategories as $dcat) {
            $rightSidebarItems[] = [
                'label'  => $dcat['name'],
                'url'    => downloadCategoryUrl($dcat),
                'active' => $dlCatId === (int) $dcat['id'],
            ];
        }
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

// 前台就地编辑：列表页 → 对应类型的后台内容管理（管理浮条「编辑此页」）
if (!empty($_SESSION['admin_id'])) {
    $__listAdmin = [
        'list' => '/admin/article.php', 'case' => '/admin/case.php',
        'product' => '/admin/product.php', 'download' => '/admin/download.php',
        'job' => '/admin/job.php', 'album' => '/admin/album.php',
    ][$channel['type']] ?? ('/admin/content.php?type=' . urlencode((string) $channel['type']));
    $GLOBALS['ik_edit_url'] = $__listAdmin;
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
            $conditions = ['parent_id' => $parentId, 'status' => 1];
            if (isMultiLangEnabled('product_categories')) {
                $conditions['lang'] = siteLang();
            }
            $categories = productCategoryModel()->where($conditions);
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
require_once theme_path('layouts/header.php');
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
<?php require theme_path('partials/page-hero.php'); ?>

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
                           placeholder="<?php echo __('list_search_product'); ?>"
                           class="w-48 border rounded-full pl-4 pr-9 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <button type="submit" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-primary">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </button>
                </div>
                <?php if ($keyword !== ''): ?>
                <a href="<?php echo channelUrl($channel); ?>" class="text-gray-400 hover:text-red-500" title="<?php echo __('search_clear'); ?>">
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
                <span class="text-gray-600 font-medium"><?php echo __('list_product_category'); ?>:</span>
                <a href="<?php echo channelUrl($rootChannel); ?>" class="<?php echo $productCategoryId === 0 && $keyword === '' ? 'text-primary font-medium' : 'text-gray-500 hover:text-primary'; ?>"><?php echo __('list_all_products'); ?></a>
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
                           placeholder="<?php echo __('search_placeholder'); ?>"
                           class="w-48 border rounded-full pl-4 pr-9 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <button type="submit" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-primary">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </button>
                </div>
                <?php if ($keyword !== ''): ?>
                <a href="<?php echo channelUrl($channel); ?>" class="text-gray-400 hover:text-red-500" title="<?php echo __('search_clear'); ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </a>
                <?php endif; ?>
            </form>
        </div>
        <?php if ($keyword !== ''): ?>
        <div class="pb-3 text-sm text-gray-500">
            <?php echo __('search_total', ['count' => '<span class="text-primary font-medium">' . $total . '</span>']); ?> — "<span class="text-primary"><?php echo e($keyword); ?></span>"
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- 内容列表 -->
<section class="py-12">
    <div class="container mx-auto px-4">
        <?php if ($showProductTopNav): ?>
        <!-- 产品排序栏 -->
        <?php if ($isProductType && !empty($enabledSorts) && count($enabledSorts) > 1): ?>
        <div class="flex items-center gap-2 mb-6 text-sm">
            <span class="text-gray-500 mr-1"><?php echo __('list_sort'); ?>：</span>
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
               class="px-3 py-1.5 rounded-full border transition <?php echo $isActive ? 'bg-primary text-white border-primary' : 'bg-white text-gray-600 border-gray-200 hover:border-primary hover:text-primary'; ?>">
                <?php echo __(ProductModel::SORT_LABELS[$sortKey]); ?>
            </a>
            <?php endforeach; ?>
            <span class="ml-auto text-gray-400"><?php echo __('list_total'); ?> <?php echo $total; ?> <?php echo __('list_items'); ?></span>
        </div>
        <?php endif; ?>

        <!-- 产品顶栏模式：全宽4列网格 -->
        <?php if (!empty($contents)): ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
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
        $pageUrl = function(int $p) use ($channel, $keyword, $productCategory, $currentSort): string {
            $extraParams = '';
            if ($keyword !== '') $extraParams .= '&keyword=' . urlencode($keyword);
            if (isset($currentSort) && $currentSort !== 'default') $extraParams .= '&sort=' . urlencode($currentSort);
            $queryStr = $extraParams !== '' ? '?' . ltrim($extraParams, '&') : '';

            if ($productCategory) {
                $catSlug = $productCategory['slug'] ?? '';
                return ($p === 1 ? '/product/' . $catSlug . '.html' : '/product/' . $catSlug . '/page/' . $p . '.html') . $queryStr;
            }
            $slug = $channel['slug'] ?? '';
            $url = $p === 1 ? ($slug ? "/{$slug}.html" : "/list/{$channel['id']}.html") : ($slug ? "/{$slug}/page/{$p}.html" : "/list/{$channel['id']}/page/{$p}.html");
            return $url . $queryStr;
        };
        require theme_path('partials/pagination.php');
        ?>

        <?php elseif ($showSidebar): ?>
        <?php require __DIR__ . '/views/list/sidebar.php'; ?>
        <?php elseif ($channel['type'] === 'download'): ?>
        <?php require __DIR__ . '/views/list/download.php'; ?>
        <?php else: ?>
        <!-- 其他类型的原有布局 -->
        <?php $hasRightSidebar = !empty($rightSidebarChannels); ?>
        <?php if ($hasRightSidebar): ?><div class="flex flex-wrap lg:flex-nowrap gap-8"><div class="w-full lg:flex-1"><?php endif; ?>

        <?php if (!empty($contents) || !empty($jobs ?? [])): ?>

        <?php if ($channel['type'] === 'case'): ?>
        <!-- 案例：图文网格 -->
        <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-6">
            <?php foreach ($contents as $item): ?>
            <?php require theme_path('partials/case-card.php'); ?>
            <?php endforeach; ?>
        </div>

        <?php elseif ($channel['type'] === 'job'): ?>
        <!-- 招聘：卡片列表（数据来自 yikai_jobs 表） -->
        <?php if (!empty($jobs)): ?>
        <div class="space-y-4">
            <?php foreach ($jobs as $item): ?>
            <?php require theme_path('partials/job-card.php'); ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php else: ?>
        <!-- 文章 / 自定义模型：图文列表。自定义模型可指定列表卡片模板，解析不到回退文章卡片 -->
        <?php
        $cardTpl = 'partials/article-card.php';
        $_listModel = contentModelModel()->getByKey($channel['type']);
        if ($_listModel && !empty($_listModel['list_template']) && theme_path_optional($_listModel['list_template'])) {
            $cardTpl = $_listModel['list_template'];
        }
        ?>
        <div class="space-y-6">
            <?php foreach ($contents as $item): ?>
            <?php require theme_path($cardTpl); ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- 分页 -->
        <?php
        $totalPages = (int)ceil($total / $perPage);
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
        require theme_path('partials/pagination.php');
        ?>

        <?php else: ?>
        <div class="text-center py-16 text-gray-500">
            <?php echo __('no_content'); ?>
        </div>
        <?php endif; ?>

        <?php if ($hasRightSidebar): ?>
        </div>
        <?php require theme_path('partials/right_sidebar.php'); ?>
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

<?php require_once theme_path('layouts/footer.php'); ?>
<?php HtmlCache::end(); ?>
