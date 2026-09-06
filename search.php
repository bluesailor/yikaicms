<?php
/**
 * Yikai CMS - 全站搜索
 * 支持分类搜索：全部 / 文章 / 产品 / 案例 / 下载
 *
 * PHP 8.0+
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/search_query.php';

$keyword = trim(get('keyword', ''));
$type = get('type', 'all'); // all, article, product, case, download
$page = max(1, getInt('page', 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;
$searchLang = siteLang() === 'zh-TW' ? 'zh-CN' : siteLang();

$results = [];
$total = 0;

$typeLabels = [
    'all'      => __('search_all'),
    'article'  => __('search_article'),
    'product'  => __('search_product'),
    'case'     => __('search_case'),
    'download' => __('search_download'),
];

if ($keyword !== '') {
    $kw = '%' . $keyword . '%';

    if ($type === 'product') {
        // 产品搜索
        $total = (int)db()->fetchColumn(
            "SELECT COUNT(*) FROM " . DB_PREFIX . "products WHERE status = 1 AND lang = ? AND (title LIKE ? OR summary LIKE ? OR model LIKE ?)",
            [$searchLang, $kw, $kw, $kw]
        );
        $results = db()->fetchAll(
            "SELECT p.*, pc.name as category_name, pc.slug as category_slug, 'product' as _type
             FROM " . DB_PREFIX . "products p
             LEFT JOIN " . DB_PREFIX . "product_categories pc ON p.category_id = pc.id
             WHERE p.status = 1 AND p.lang = ? AND (p.title LIKE ? OR p.summary LIKE ? OR p.model LIKE ?)
             ORDER BY p.updated_at DESC LIMIT ? OFFSET ?",
            [$searchLang, $kw, $kw, $kw, $perPage, $offset]
        );

    } elseif ($type === 'download') {
        // 下载搜索
        $total = (int)db()->fetchColumn(
            "SELECT COUNT(*) FROM " . DB_PREFIX . "downloads WHERE status = 1 AND lang = ? AND (title LIKE ? OR description LIKE ?)",
            [$searchLang, $kw, $kw]
        );
        $results = db()->fetchAll(
            downloadSearchQuery(DB_PREFIX),
            [$searchLang, $kw, $kw, $perPage, $offset]
        );

    } elseif ($type === 'case') {
        // 案例搜索
        $total = (int)db()->fetchColumn(
            "SELECT COUNT(*) FROM " . DB_PREFIX . "contents WHERE status = 1 AND lang = ? AND type = 'case' AND (title LIKE ? OR summary LIKE ?)",
            [$searchLang, $kw, $kw]
        );
        $results = db()->fetchAll(
            "SELECT c.*, ch.name as channel_name, ch.slug as channel_slug, 'case' as _type
             FROM " . DB_PREFIX . "contents c
             LEFT JOIN " . DB_PREFIX . "channels ch ON c.channel_id = ch.id
             WHERE c.status = 1 AND c.lang = ? AND c.type = 'case' AND (c.title LIKE ? OR c.summary LIKE ?)
             ORDER BY c.publish_time DESC LIMIT ? OFFSET ?",
            [$searchLang, $kw, $kw, $perPage, $offset]
        );

    } elseif ($type === 'article') {
        // 文章搜索（排除案例）
        $total = (int)db()->fetchColumn(
            "SELECT COUNT(*) FROM " . DB_PREFIX . "contents WHERE status = 1 AND lang = ? AND type != 'case' AND (title LIKE ? OR summary LIKE ?)",
            [$searchLang, $kw, $kw]
        );
        $results = db()->fetchAll(
            "SELECT c.*, ch.name as channel_name, ch.slug as channel_slug, 'article' as _type
             FROM " . DB_PREFIX . "contents c
             LEFT JOIN " . DB_PREFIX . "channels ch ON c.channel_id = ch.id
             WHERE c.status = 1 AND c.lang = ? AND c.type != 'case' AND (c.title LIKE ? OR c.summary LIKE ?)
             ORDER BY c.publish_time DESC LIMIT ? OFFSET ?",
            [$searchLang, $kw, $kw, $perPage, $offset]
        );

    } else {
        // 全部搜索：合并内容 + 产品 + 下载
        // 内容（文章+案例）
        $contentTotal = (int)db()->fetchColumn(
            "SELECT COUNT(*) FROM " . DB_PREFIX . "contents WHERE status = 1 AND lang = ? AND (title LIKE ? OR summary LIKE ?)", [$searchLang, $kw, $kw]
        );
        $productTotal = (int)db()->fetchColumn(
            "SELECT COUNT(*) FROM " . DB_PREFIX . "products WHERE status = 1 AND lang = ? AND (title LIKE ? OR summary LIKE ?)", [$searchLang, $kw, $kw]
        );
        $downloadTotal = (int)db()->fetchColumn(
            "SELECT COUNT(*) FROM " . DB_PREFIX . "downloads WHERE status = 1 AND lang = ? AND (title LIKE ? OR description LIKE ?)", [$searchLang, $kw, $kw]
        );
        $total = $contentTotal + $productTotal + $downloadTotal;

        // 用 UNION 合并查询
        $results = db()->fetchAll(
            // c.type 除了别名成 _type（供下方分支判断），还要以原名选出——
            // contentUrl() 读的是 type，只有 _type 会让文章链接退化成 404 地址
            globalSearchQuery(DB_PREFIX),
            [$searchLang, $kw, $kw, $searchLang, $kw, $kw, $searchLang, $kw, $kw, $perPage, $offset]
        );
    }
}

// 各类别计数（用于标签显示）
$typeCounts = [];
if ($keyword !== '') {
    $kw = '%' . $keyword . '%';
    $typeCounts['article'] = (int)db()->fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "contents WHERE status = 1 AND lang = ? AND type != 'case' AND (title LIKE ? OR summary LIKE ?)", [$searchLang, $kw, $kw]);
    $typeCounts['product'] = (int)db()->fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "products WHERE status = 1 AND lang = ? AND (title LIKE ? OR summary LIKE ?)", [$searchLang, $kw, $kw]);
    $typeCounts['case'] = (int)db()->fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "contents WHERE status = 1 AND lang = ? AND type = 'case' AND (title LIKE ? OR summary LIKE ?)", [$searchLang, $kw, $kw]);
    $typeCounts['download'] = (int)db()->fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "downloads WHERE status = 1 AND lang = ? AND (title LIKE ? OR description LIKE ?)", [$searchLang, $kw, $kw]);
    $typeCounts['all'] = array_sum($typeCounts);
}

// 页面信息
$pageTitle = $keyword ? __('search_result') . '：' . $keyword : __('search_result');
$pageKeywords = config('site_keywords');
$pageDescription = config('site_description');
$isHomePage = false;

$navChannels = getNavChannels();

require_once theme_path('layouts/header.php');
?>

<!-- 搜索区域 -->
<section class="py-10 bg-gradient-to-r from-blue-600 to-blue-800">
    <div class="container mx-auto px-4">
        <div class="max-w-2xl mx-auto">
            <h1 class="text-white text-2xl font-bold text-center mb-6"><?php echo __('search_title'); ?></h1>
            <form method="GET" action="<?php echo e(dynamicFormAction(searchUrl())); ?>" class="relative">
                <?php echo dynamicFormHiddenInputs('search'); ?>
                <input type="hidden" name="type" value="<?php echo e($type); ?>" id="searchType">
                <input type="text" name="keyword" value="<?php echo e($keyword); ?>"
                       class="w-full px-5 py-3.5 pr-14 rounded-lg text-base border-0 shadow-lg focus:ring-2 focus:ring-blue-300 outline-none"
                       placeholder="<?php echo __('search_input_hint'); ?>" autofocus>
                <button type="submit" class="absolute right-2 top-2 px-4 py-2 bg-primary rounded-lg text-white transition hover:bg-secondary">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m21 21-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </button>
            </form>
        </div>
    </div>
</section>

<!-- 搜索结果 -->
<section class="py-8">
    <div class="container mx-auto px-4">

        <?php if ($keyword === ''): ?>
        <div class="text-center py-16 text-gray-400">
            <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m21 21-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <p class="text-lg"><?php echo __('search_empty_hint'); ?></p>
        </div>

        <?php else: ?>

        <!-- 分类标签 -->
        <div class="flex flex-wrap gap-2 mb-6 border-b pb-4">
            <?php foreach ($typeLabels as $tk => $tl):
                $cnt = $typeCounts[$tk] ?? 0;
                $isCurrentType = ($tk === $type);
                $tabUrl = searchUrl($keyword, $tk);
            ?>
            <a href="<?php echo e($tabUrl); ?>"
               class="px-4 py-2 rounded-full text-sm border transition <?php echo $isCurrentType
                   ? 'bg-primary text-white border-primary'
                   : 'bg-white text-gray-600 border-gray-200 hover:border-primary hover:text-primary'; ?>">
                <?php echo $tl; ?>
                <?php if ($cnt > 0): ?>
                <span class="ml-1 <?php echo $isCurrentType ? 'text-white/70' : 'text-gray-400'; ?>">(<?php echo $cnt; ?>)</span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if ($total === 0): ?>
        <div class="text-center py-12">
            <p class="text-lg text-gray-500 mb-2"><?php echo __('search_no_result'); ?> — "<span class="text-primary font-medium"><?php echo e($keyword); ?></span>"</p>
            <p class="text-sm text-gray-400"><?php echo __('search_try_other'); ?></p>
        </div>

        <?php else: ?>
        <div class="mb-4 text-sm text-gray-500">
            <?php echo __('search_total', ['count' => '<span class="text-primary font-medium">' . $total . '</span>']); ?>
        </div>

        <div class="space-y-4 max-w-3xl">
            <?php foreach ($results as $item):
                $title = $item['title'] ?? '';
                $summary = $item['summary'] ?: '';
                $itemType = $item['_type'] ?? $item['type'] ?? 'article';
                $channelName = $item['channel_name'] ?? '';

                // 生成 URL
                if ($itemType === 'product') {
                    $url = productUrl($item);
                    $typeTag = __('search_product');
                    $tagColor = '#059669';
                } elseif ($itemType === 'download') {
                    $url = '/download.php?id=' . $item['id'];
                    $typeTag = __('search_download');
                    $tagColor = '#7c3aed';
                } elseif ($itemType === 'case') {
                    $url = contentUrl($item);
                    $typeTag = __('search_case');
                    $tagColor = '#d97706';
                } else {
                    $url = contentUrl($item);
                    $typeTag = __('search_article');
                    $tagColor = '#2563eb';
                }

                // 高亮关键词
                $hlTitle = str_ireplace($keyword, '<mark class="bg-yellow-200 text-inherit px-0.5 rounded">' . e($keyword) . '</mark>', e($title));
                $hlSummary = $summary ? str_ireplace($keyword, '<mark class="bg-yellow-200 text-inherit px-0.5 rounded">' . e($keyword) . '</mark>', e(cutStr($summary, 200))) : '';
                $date = date('Y-m-d', (int)($item['publish_time'] ?? $item['sort_time'] ?? $item['created_at'] ?? time()));
            ?>
            <a href="<?php echo e($url); ?>" class="flex gap-4 bg-white rounded-lg shadow-sm border p-5 hover:shadow transition group">
                <?php if (!empty($item['cover'])): ?>
                <div class="flex-shrink-0 w-24 h-24 rounded overflow-hidden bg-gray-100">
                    <img src="<?php echo e($item['cover']); ?>" alt="<?php echo e($title); ?>" class="w-full h-full object-cover">
                </div>
                <?php endif; ?>
                <div class="flex-1 min-w-0">
                    <h3 class="text-base font-bold text-gray-800 group-hover:text-primary transition mb-1 line-clamp-1">
                        <?php echo $hlTitle; ?>
                    </h3>
                    <?php if ($hlSummary): ?>
                    <p class="text-sm text-gray-500 leading-relaxed line-clamp-2 mb-2"><?php echo $hlSummary; ?></p>
                    <?php endif; ?>
                    <div class="flex items-center gap-2 text-xs text-gray-400">
                        <span class="px-2 py-0.5 rounded text-white" style="background:<?php echo $tagColor; ?>;"><?php echo $typeTag; ?></span>
                        <?php if ($channelName): ?>
                        <span><?php echo e($channelName); ?></span>
                        <?php endif; ?>
                        <span><?php echo $date; ?></span>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- 分页 -->
        <?php
        $totalPages = (int)ceil($total / $perPage);
        if ($totalPages > 1):
        ?>
        <div class="flex justify-center mt-8 gap-2">
            <?php if ($page > 1): ?>
            <a href="<?php echo e(searchUrl($keyword, $type, $page - 1)); ?>" class="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50"><?php echo __('list_prev_page'); ?></a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 3); $i <= min($totalPages, $page + 3); $i++): ?>
            <a href="<?php echo e(searchUrl($keyword, $type, $i)); ?>"
               class="px-4 py-2 border rounded-lg text-sm <?php echo $i === $page ? 'bg-primary text-white border-primary' : 'hover:bg-gray-50'; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="<?php echo e(searchUrl($keyword, $type, $page + 1)); ?>" class="px-4 py-2 border rounded-lg text-sm hover:bg-gray-50"><?php echo __('list_next_page'); ?></a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

<?php require_once theme_path('layouts/footer.php'); ?>
