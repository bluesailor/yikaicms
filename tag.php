<?php
/**
 * Yikai CMS - 文章标签页（/tag/{slug}/ 等登记网址进入，按标签 id 渲染）
 *
 * 标签是 contents.tags 里的文字；登记表为每个标签在 metas 存一行（见 ProductRouteModel::contentTag）。
 * PHP 8.0+
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

HtmlCache::start(600);

$tagId = getInt('id');
$tag = $tagId > 0 ? ProductRouteModel::contentTag($tagId) : null;
if (!$tag || $tag['name'] === '') {
    header('HTTP/1.1 404 Not Found');
    render404(__('error_page_not_found'));
}

redirectToRegisteredUrl('content_tag', (int) $tag['id']);
$page = max(1, getInt('page', 1));
$perPage = catalogPageSize('article', 10, 0);
$filters = ['type' => 'article', 'tag' => $tag['name'], 'lang' => $tag['lang']];
$total = contentModel()->getCount(0, $filters);
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages && $total > 0) {
    header('HTTP/1.1 404 Not Found');
    render404(__('error_page_not_found'));
}
$articles = contentModel()->getList(0, $perPage, ($page - 1) * $perPage, $filters);

$tagBasePath = productRouteModel()->pathFor('content_tag', (int) $tag['id']);
$pageUrl = static function (int $p) use ($tagBasePath, $tag): string {
    if ($tagBasePath === '') {
        return '/tag.php?id=' . (int) $tag['id'] . ($p > 1 ? '&page=' . $p : '');
    }
    return $p > 1 ? rtrim($tagBasePath, '/') . '/page/' . $p . '/' : $tagBasePath;
};

$pageTitle = $tag['name'];
$pageKeywords = $tag['name'];
$pageDescription = configJsonLang('site_description');
$navChannels = getNavChannels();

require_once theme_path('layouts/header.php');

$channel = ['id' => 0, 'parent_id' => 0, 'type' => 'list', 'name' => $tag['name'], 'description' => '', 'image' => ''];
$breadcrumbItems = [['name' => $tag['name'], 'url' => '']];
require theme_path('partials/page-hero.php');
?>

<section class="py-12">
    <div class="container mx-auto px-4">
        <?php if (!empty($articles)): ?>
        <?php $listOpts = channelListOptions([]); ?>
        <div class="space-y-6">
            <?php foreach ($articles as $item): ?>
            <?php $item['url'] = contentUrl($item); ?>
            <?php require theme_path('partials/article-card.php'); ?>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="mt-8 flex items-center justify-center gap-2">
            <?php if ($page > 1): ?>
            <a href="<?php echo e($pageUrl($page - 1)); ?>" class="px-4 py-2 border rounded hover:bg-gray-100"><?php echo __('list_prev_page'); ?></a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2), $end = min($totalPages, $page + 2); $i <= $end; $i++): ?>
            <a href="<?php echo e($pageUrl($i)); ?>"
               class="px-4 py-2 border rounded <?php echo $i === $page ? 'bg-primary text-white border-primary' : 'hover:bg-gray-100'; ?>">
                <?php echo $i; ?>
            </a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="<?php echo e($pageUrl($page + 1)); ?>" class="px-4 py-2 border rounded hover:bg-gray-100"><?php echo __('list_next_page'); ?></a>
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
