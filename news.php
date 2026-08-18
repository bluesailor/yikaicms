<?php
/**
 * Yikai CMS - 新闻文章列表页
 *
 * 合并后使用 ContentModel + ChannelModel
 * PHP 8.0+
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

HtmlCache::start(600);

// 数据装配交给 NewsListController（栏目/分类/分页/搜索），本页只渲染新闻模板。
require_once __DIR__ . '/controllers/list/NewsListController.php';
extract((new NewsListController())->prepare([
    'cat'     => get('cat', ''),
    'cat_id'  => getInt('cat_id', 0),
    'page'    => getInt('page', 1),
    'keyword' => get('keyword', ''),
]), EXTR_OVERWRITE);

$newsBloxJson = $newsChannelId > 0 ? ChannelBloxDocument::publishedJson($newsChannelId) : null;
$hasPublishedNewsBlox = is_string($newsBloxJson) && $newsBloxJson !== '';
if (!isCleanFrontendPreview() && !empty($_SESSION['admin_id']) && $newsChannelId > 0) {
    $GLOBALS['ik_edit_url'] = '/admin/blox_editor.php?id=' . $newsChannelId;
    if ($hasPublishedNewsBlox) {
        BlockRenderer::$editChannelId = $newsChannelId;
        $GLOBALS['ik_front_edit_cid'] = $newsChannelId;
    }
}

// 页面信息
$pageTitle = $category ? $category['name'] : __('news_title');
$pageKeywords = ($category['seo_keywords'] ?? '') ?: config('site_keywords');
$pageDescription = ($category['seo_description'] ?? '') ?: config('site_description');

// 当前菜单高亮
$currentSlug = 'news';

// 获取导航
$navChannels = getNavChannels();

// 引入头部
require_once theme_path('layouts/header.php');

if ($hasPublishedNewsBlox) {
    ContentCatalogElement::setRuntimeContext([
        'channel' => $category ?: $newsChannel,
        'rootChannel' => $newsChannel,
        'categories' => $categories,
        'contents' => $articles,
        'keyword' => $keyword,
        'page' => $page,
        'perPage' => $perPage,
        'total' => $total,
    ]);
    echo renderContentBody([
        'content_type' => 'blocks',
        'blocks_data' => $newsBloxJson,
        'content' => '',
    ]);
    ContentCatalogElement::setRuntimeContext(null);
    require_once theme_path('layouts/footer.php');
    HtmlCache::end();
    exit;
}
?>

<!-- 页面头部 -->
<?php
$breadcrumbItems = [];
if ($category) {
    $breadcrumbItems[] = ['name' => __('news_title'), 'url' => '/news.html'];
    $breadcrumbItems[] = ['name' => $category['name'], 'url' => ''];
} else {
    $breadcrumbItems[] = ['name' => __('news_title'), 'url' => ''];
}
$heroChannel = $category ?: ($newsChannel ?: ['name' => __('news_title'), 'description' => '', 'image' => '']);
// Ensure channel var is set for page-hero partial
$_heroChannelBackup = $channel ?? null;
$channel = $heroChannel;
require theme_path('partials/page-hero.php');
$channel = $_heroChannelBackup;
unset($_heroChannelBackup);
?>

<!-- 分类导航 + 搜索 -->
<div class="bg-white border-b">
    <div class="container mx-auto px-4">
        <div class="flex flex-wrap items-center justify-between gap-4 py-4">
            <?php if (!empty($categories)): ?>
            <div class="flex flex-wrap gap-3">
                <a href="/news.html"
                   class="px-4 py-2 rounded-full text-sm <?php echo !$category && $keyword === '' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                    <?php echo __('all'); ?>
                </a>
                <?php foreach ($categories as $cat): ?>
                <a href="/news/<?php echo e($cat['slug']); ?>.html"
                   class="px-4 py-2 rounded-full text-sm <?php echo (int) ($category['id'] ?? 0) === (int) $cat['id'] ? 'bg-primary text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
                    <?php echo e($cat['name']); ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div></div>
            <?php endif; ?>
            <form method="get" action="<?php echo $category ? '/news/' . e($category['slug']) . '.html' : '/news.html'; ?>" class="flex items-center gap-2">
                <div class="relative">
                    <input type="text" name="keyword" value="<?php echo e($keyword); ?>"
                           placeholder="<?php echo __('news_search_placeholder'); ?>"
                           class="w-48 border rounded-full pl-4 pr-9 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    <button type="submit" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-primary">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                    </button>
                </div>
                <?php if ($keyword !== ''): ?>
                <a href="<?php echo $category ? '/news/' . e($category['slug']) . '.html' : '/news.html'; ?>" class="text-gray-400 hover:text-red-500" title="<?php echo __('search_clear'); ?>">
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

<!-- 文章列表 -->
<section class="py-12">
    <div class="container mx-auto px-4">
        <?php if (!empty($articles)): ?>
        <?php $listOpts = channelListOptions($category ?: ($newsChannel ?: [])); // 栏目「列表显示元素」配置 ?>
        <div class="space-y-6">
            <?php foreach ($articles as $item): ?>
            <?php $item['url'] = contentUrl($item); // 文章路由统一由 contentUrl 决定（含 slug 友好链接） ?>
            <?php require theme_path('partials/article-card.php'); // 共用当前主题卡片模板，勿再内联 ?>
            <?php endforeach; ?>
        </div>

        <!-- 分页 -->
        <?php if ($total > $perPage): ?>
        <?php
        $totalPages = (int)ceil($total / $perPage);
        $pageUrl = function(int $p) use ($category, $keyword): string {
            $base = $category ? '/news/' . $category['slug'] : '/news';
            $keywordParam = $keyword !== '' ? '?keyword=' . urlencode($keyword) : '';
            if ($p === 1) {
                return $base . '.html' . $keywordParam;
            } else {
                return $base . '/page/' . $p . '.html' . $keywordParam;
            }
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

        <?php else: ?>
        <div class="text-center py-16 text-gray-500 bg-white rounded-lg">
            <?php echo __('no_content'); ?>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php require_once theme_path('layouts/footer.php'); ?>
