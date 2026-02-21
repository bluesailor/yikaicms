<?php
/**
 * Yikai CMS - 文章详情页
 *
 * PHP 8.0+
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

$id = getInt('id');
$slug = get('slug');

// 获取文章
if ($slug) {
    $article = articleModel()->findBySlug($slug);
} elseif ($id > 0) {
    $article = articleModel()->getPublished($id);
} else {
    $article = null;
}

if (!$article) {
    header('HTTP/1.1 404 Not Found');
    exit('文章不存在');
}

// 更新浏览量
articleModel()->incrementViews((int)$article['id']);

// 页面信息
$pageTitle = $article['title'];
$pageKeywords = $article['tags'] ?: config('site_keywords');
$pageDescription = $article['summary'] ?: cutStr(strip_tags($article['content']), 150);

// 获取上一篇和下一篇
$prevArticle = articleModel()->getPrev((int)$article['category_id'], (int)$article['publish_time'], (int)$article['id']);
$nextArticle = articleModel()->getNext((int)$article['category_id'], (int)$article['publish_time'], (int)$article['id']);

// 获取相关文章
$relatedArticles = articleModel()->getRelated((int)$article['category_id'], (int)$article['id']);

// 当前菜单高亮
$currentSlug = 'news';

// 获取导航
$navChannels = getNavChannels();

// 引入头部
require_once INCLUDES_PATH . 'header.php';
?>

<!-- 页面头部 -->
<section class="bg-gradient-to-r from-gray-900 via-gray-800 to-gray-900 py-12 relative overflow-hidden">
    <div class="absolute inset-0 opacity-20">
        <div class="absolute top-0 left-1/4 w-96 h-96 bg-primary rounded-full blur-3xl"></div>
        <div class="absolute bottom-0 right-1/4 w-96 h-96 bg-secondary rounded-full blur-3xl"></div>
    </div>
    <div class="container mx-auto px-4 relative">
        <!-- 面包屑导航 -->
        <div class="flex items-center gap-2 text-sm text-gray-400">
            <a href="/" class="hover:text-white"><?php echo __('breadcrumb_home'); ?></a>
            <span>/</span>
            <a href="/news.html" class="hover:text-white"><?php echo __('news_title'); ?></a>
            <?php if ($article['category_name']): ?>
            <span>/</span>
            <a href="/news/<?php echo e($article['category_slug']); ?>.html" class="hover:text-white">
                <?php echo e($article['category_name']); ?>
            </a>
            <?php endif; ?>
            <span>/</span>
            <span class="text-white"><?php echo e(cutStr($article['title'], 30)); ?></span>
        </div>
    </div>
</section>

<!-- 文章内容 -->
<section class="py-12">
    <div class="container mx-auto px-4">
        <div class="flex flex-wrap lg:flex-nowrap gap-8">
            <!-- 左侧文章内容 -->
            <div class="flex-1 min-w-0">
                <article class="bg-white rounded-lg shadow overflow-hidden">
                    <!-- 文章头部 -->
                    <div class="p-6 md:p-8 border-b">
                        <h1 class="text-2xl md:text-3xl font-bold text-dark leading-snug">
                            <?php echo e($article['title']); ?>
                        </h1>
                        <?php if ($article['subtitle']): ?>
                        <p class="mt-2 text-gray-500"><?php echo e($article['subtitle']); ?></p>
                        <?php endif; ?>
                        <div class="mt-4 flex flex-wrap items-center gap-4 text-sm text-gray-500">
                            <?php if ($article['category_name']): ?>
                            <a href="/news/<?php echo e($article['category_slug']); ?>.html" class="text-primary hover:underline">
                                <?php echo e($article['category_name']); ?>
                            </a>
                            <?php endif; ?>
                            <?php if ($article['author']): ?>
                            <span class="flex items-center gap-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                </svg>
                                <?php echo e($article['author']); ?>
                            </span>
                            <?php endif; ?>
                            <span class="flex items-center gap-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                                </svg>
                                <?php echo date('Y-m-d', (int)$article['publish_time']); ?>
                            </span>
                            <span class="flex items-center gap-1">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                </svg>
                                <?php echo number_format((int)$article['views'] + 1); ?>
                            </span>
                        </div>
                    </div>

                    <!-- 封面图 -->
                    <?php if ($article['cover']): ?>
                    <div class="border-b">
                        <img src="<?php echo e($article['cover']); ?>" alt="<?php echo e($article['title']); ?>"
                             class="w-full max-h-96 object-cover">
                    </div>
                    <?php endif; ?>

                    <!-- 文章摘要 -->
                    <?php if ($article['summary']): ?>
                    <div class="px-6 md:px-8 py-4 bg-gray-50 border-b">
                        <p class="text-gray-600 italic"><?php echo e($article['summary']); ?></p>
                    </div>
                    <?php endif; ?>

                    <!-- 文章正文 -->
                    <div class="p-6 md:p-8">
                        <div class="prose prose-lg max-w-none content-body">
                            <?php echo $article['content']; ?>
                        </div>
                    </div>

                    <!-- 标签 -->
                    <?php if ($article['tags']): ?>
                    <div class="px-6 md:px-8 py-4 border-t bg-gray-50">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-gray-500 text-sm"><?php echo __('article_tags'); ?>:</span>
                            <?php foreach (explode(',', $article['tags']) as $tag): ?>
                            <span class="px-3 py-1 bg-white border rounded-full text-sm text-gray-600">
                                <?php echo e(trim($tag)); ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- 来源 -->
                    <?php if ($article['source']): ?>
                    <div class="px-6 md:px-8 py-4 border-t text-sm text-gray-500">
                        <?php echo __('article_source'); ?>: <?php echo e($article['source']); ?>
                    </div>
                    <?php endif; ?>
                </article>

                <!-- 上一篇/下一篇 -->
                <div class="mt-6 bg-white rounded-lg shadow p-6">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div class="flex-1">
                            <?php if ($prevArticle): ?>
                            <a href="/news/article/<?php echo $prevArticle['id']; ?>.html" class="group flex items-center gap-2 text-gray-600 hover:text-primary">
                                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                                </svg>
                                <span class="line-clamp-1"><?php echo e($prevArticle['title']); ?></span>
                            </a>
                            <?php else: ?>
                            <span class="text-gray-400"><?php echo __('no_prev_article'); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="flex-1 text-right">
                            <?php if ($nextArticle): ?>
                            <a href="/news/article/<?php echo $nextArticle['id']; ?>.html" class="group flex items-center justify-end gap-2 text-gray-600 hover:text-primary">
                                <span class="line-clamp-1"><?php echo e($nextArticle['title']); ?></span>
                                <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </a>
                            <?php else: ?>
                            <span class="text-gray-400"><?php echo __('no_next_article'); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 右侧边栏 -->
            <div class="w-full lg:w-80 flex-shrink-0 space-y-6">
                <!-- 相关文章 -->
                <?php if (!empty($relatedArticles)): ?>
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="bg-primary text-white px-4 py-3 font-bold">
                        <?php echo __('related_articles'); ?>
                    </div>
                    <div class="divide-y">
                        <?php foreach ($relatedArticles as $related): ?>
                        <a href="/news/article/<?php echo $related['id']; ?>.html"
                           class="flex gap-3 p-4 hover:bg-gray-50 transition">
                            <?php if ($related['cover']): ?>
                            <div class="flex-shrink-0 w-20 h-14 overflow-hidden rounded">
                                <img src="<?php echo e(thumbnail($related['cover'], 'thumb')); ?>" alt="<?php echo e($related['title']); ?>"
                                     class="w-full h-full object-cover">
                            </div>
                            <?php endif; ?>
                            <div class="flex-1 min-w-0">
                                <h4 class="text-sm font-medium text-gray-800 line-clamp-2 hover:text-primary transition">
                                    <?php echo e($related['title']); ?>
                                </h4>
                                <p class="text-xs text-gray-400 mt-1">
                                    <?php echo date('Y-m-d', (int)$related['publish_time']); ?>
                                </p>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- 返回列表 -->
                <div class="bg-white rounded-lg shadow p-4">
                    <a href="/news.html" class="flex items-center justify-center gap-2 text-primary hover:underline">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                        </svg>
                        <?php echo __('back_to_list'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once INCLUDES_PATH . 'footer.php'; ?>
