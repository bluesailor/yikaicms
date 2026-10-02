<?php
/**
 * 产品目录——分页区域（结构树「分页」节点对应）。
 *
 * URL 参数与缓存键沿用现有契约：动态路由走 dynamicChannelPageUrl，
 * 静态路由保留 keyword/sort/brand/tag/pmin/pmax，翻页不丢筛选。
 */

declare(strict_types=1);

/** @var array<string,mixed> $catalog */
extract($context, EXTR_SKIP);

$pcTotalPages = (int) ceil($total / max(1, (int) $perPage));
$totalPages = $pcTotalPages;
$currentSort = $currentSort ?? 'default';

$catalogQuery = $catalogQuery ?? ProductCatalogRequest::normalize($_GET);
$pageUrl = function (int $p) use ($channel, $keyword, $isProductType, $productCategory, $currentSort, $catalogQuery): string {
    // 产品目录与侧栏分页同一出处（含语言前缀与登记网址）；此前这里写死 /product/{分类}/page/N.html
    if ($isProductType) return ProductCatalogRequest::pageUrl($channel, $productCategory, $p, $catalogQuery);
    if (isDynamicUrlMode()) {
        $params = [];
        if ($keyword !== '') { $params['keyword'] = $keyword; }
        if ($isProductType && $currentSort !== 'default') { $params['sort'] = $currentSort; }
        if ($isProductType && !empty($productCategory['slug'])) { $params['cat'] = (string) $productCategory['slug']; }
        $params = array_merge($params, array_intersect_key(ProductCatalogRequest::filterQuery($catalogQuery),
            ['brand' => true, 'tag' => true, 'pmin' => true, 'pmax' => true]));
        return dynamicChannelPageUrl($channel, $p, $params)
            ?? dynamicUrl('list', ['id' => (int) ($channel['id'] ?? 0), 'page' => $p]);
    }
    $extraParams = '';
    if ($keyword !== '') { $extraParams .= '&keyword=' . urlencode($keyword); }
    if ($isProductType && $currentSort !== 'default') { $extraParams .= '&sort=' . urlencode($currentSort); }
    foreach (['brand', 'tag', 'pmin', 'pmax'] as $fk) {
        $fv = (string) ($catalogQuery[$fk] ?? '');
        if ($fv !== '') { $extraParams .= '&' . $fk . '=' . urlencode($fv); }
    }
    $queryStr = $extraParams !== '' ? '?' . ltrim($extraParams, '&') : '';

    if ($isProductType && $productCategory) {
        $catSlug = $productCategory['slug'] ?? '';
        return $p === 1
            ? '/product/' . $catSlug . '.html' . $queryStr
            : '/product/' . $catSlug . '/page/' . $p . '.html' . $queryStr;
    }
    $registered = productRouteModel()->pathFor('channel', (int) ($channel['id'] ?? 0));
    if ($registered !== '') return pagedUrl($registered, $p) . $queryStr;
    $slug = $channel['slug'] ?? '';
    if ($p === 1) {
        $url = $slug ? "/{$slug}.html" : "/list/{$channel['id']}.html";
    } else {
        $url = $slug ? "/{$slug}/page/{$p}.html" : "/list/{$channel['id']}/page/{$p}.html";
    }
    return $url . $queryStr;
};

require theme_path('partials/pagination.php');
