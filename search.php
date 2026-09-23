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

// v1.26 search 模板：已发布模板替换整页正文（主题壳保留）。search-results 元素
// 从这里注入的运行时上下文取数——与下方原生标记共用 includes/partials/search-results.php。
$searchTemplateRow = BloxSearchTemplateRuntime::resolve();
if ($searchTemplateRow !== null) {
    SearchResultsElement::setRuntimeContext([
        'keyword' => $keyword,
        'type' => $type,
        'results' => $results,
        'total' => $total,
        'page' => $page,
        'perPage' => $perPage,
        'typeLabels' => $typeLabels,
        'typeCounts' => $typeCounts,
    ]);
    $searchTemplateBody = BloxSearchTemplateRuntime::renderBody($searchTemplateRow);
    SearchResultsElement::setRuntimeContext(null);
    if ($searchTemplateBody !== '') {
        require_once theme_path('layouts/header.php');
        echo $searchTemplateBody;
        require_once theme_path('layouts/footer.php');
        exit;
    }
}

require_once theme_path('layouts/header.php');
?>

<?php /* 搜索区域 */ ?>
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

<?php /* 搜索结果 */ ?>
<section class="py-8">
    <div class="container mx-auto px-4">
<?php
// 结果体与 Blox search 模板的 search-results 元素同源（v1.26 抽出为共享局部）
$srKeyword = $keyword;
$srType = $type;
$srResults = $results;
$srTotal = $total;
$srPage = $page;
$srPerPage = $perPage;
$srTypeLabels = $typeLabels;
$srTypeCounts = $typeCounts;
require ROOT_PATH . '/includes/partials/search-results.php';
?>
    </div>
</section>

<?php require_once theme_path('layouts/footer.php'); ?>
