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

// 获取分类参数
$categorySlug = get('cat', '');
$categoryId = getInt('cat_id', 0);
$category = null;

// 获取 news 顶级栏目
$newsChannel = getChannelBySlug('news');
$newsChannelId = $newsChannel ? (int)$newsChannel['id'] : 0;

// 通过 slug 获取子栏目
if ($categorySlug) {
    $category = channelModel()->findWhere(['slug' => $categorySlug, 'status' => 1]);
    if ($category) {
        $categoryId = (int)$category['id'];
    }
} elseif ($categoryId > 0) {
    $category = getChannel($categoryId);
}

// 页面信息
$pageTitle = $category ? $category['name'] : __('news_title');
$pageKeywords = ($category['seo_keywords'] ?? '') ?: config('site_keywords');
$pageDescription = ($category['seo_description'] ?? '') ?: config('site_description');

// 当前菜单高亮
$currentSlug = 'news';

// 获取子栏目（用于分类导航）
$categories = [];
if ($newsChannelId > 0) {
    $categories = channelModel()->where(['parent_id' => $newsChannelId, 'status' => 1]);
}

// 搜索关键词
$keyword = trim(get('keyword', ''));

// 分页
$page = max(1, getInt('page', 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

// 确定要查询的栏目 ID
$queryChannelId = $categoryId > 0 ? $categoryId : $newsChannelId;
$filters = ['include_children' => true];

if ($keyword !== '') {
    $filters['keyword'] = $keyword;
}

// 获取文章总数和列表
$total = contentModel()->getCount($queryChannelId, $filters);
$articles = contentModel()->getList($queryChannelId, $perPage, $offset, $filters);

// 获取导航
$navChannels = getNavChannels();

// 引入头部
require_once theme_path('layouts/header.php');
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
                   class="px-4 py-2 rounded-full text-sm <?php echo $categoryId === (int)$cat['id'] ? 'bg-primary text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
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
        <div class="space-y-6">
            <?php foreach ($articles as $item): ?>
            <a href="/news/article/<?php echo $item['id']; ?>.html" class="flex gap-6 bg-white rounded-lg shadow overflow-hidden hover:shadow-lg transition group">
                <div class="flex-shrink-0 w-48 md:w-64 overflow-hidden bg-gray-100">
                    <?php if ($item['cover']): ?>
                    <img loading="lazy" src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>"
                         class="w-full h-full object-cover group-hover:scale-110 transition duration-500">
                    <?php else: ?>
                    <div class="w-full h-full flex items-center justify-center text-gray-300 min-h-[120px]">
                        <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="flex-1 py-4 pr-4">
                    <h3 class="text-lg font-bold text-dark group-hover:text-primary transition line-clamp-2">
                        <?php if ($item['is_top']): ?>
                        <span class="text-xs bg-red-500 text-white px-1.5 py-0.5 rounded mr-2"><?php echo __('article_top'); ?></span>
                        <?php endif; ?>
                        <?php if ($item['is_recommend']): ?>
                        <span class="text-xs bg-orange-500 text-white px-1.5 py-0.5 rounded mr-2"><?php echo __('article_recommend'); ?></span>
                        <?php endif; ?>
                        <?php echo e($item['title']); ?>
                    </h3>
                    <p class="mt-2 text-gray-500 text-sm line-clamp-2">
                        <?php echo e($item['summary'] ?: cutStr(strip_tags($item['content']), 120)); ?>
                    </p>
                    <div class="mt-3 flex items-center gap-4 text-xs text-gray-400">
                        <?php if ($item['channel_name'] ?? ''): ?>
                        <span class="text-primary"><?php echo e($item['channel_name']); ?></span>
                        <?php endif; ?>
                        <?php if ($item['author'] ?? ''): ?>
                        <span><?php echo e($item['author']); ?></span>
                        <?php endif; ?>
                        <span><?php echo date('Y-m-d', (int)(($item['publish_time'] ?? 0) ?: ($item['created_at'] ?? 0))); ?></span>
                        <span><?php echo __('detail_views'); ?> <?php echo number_format((int)$item['views']); ?></span>
                    </div>
                </div>
            </a>
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
