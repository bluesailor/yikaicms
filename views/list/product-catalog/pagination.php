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

$pageUrl = function (int $p) use ($channel, $keyword, $isProductType, $productCategory, $currentSort): string {
    if (isDynamicUrlMode()) {
        $params = [];
        if ($keyword !== '') { $params['keyword'] = $keyword; }
        if ($isProductType && $currentSort !== 'default') { $params['sort'] = $currentSort; }
        if ($isProductType && !empty($productCategory['slug'])) { $params['cat'] = (string) $productCategory['slug']; }
        foreach (['brand', 'tag', 'pmin', 'pmax'] as $filter) {
            $value = $_GET[$filter] ?? '';
            if (is_string($value) && trim($value) !== '') { $params[$filter] = trim($value); }
        }
        return dynamicChannelPageUrl($channel, $p, $params)
            ?? dynamicUrl('list', ['id' => (int) ($channel['id'] ?? 0), 'page' => $p]);
    }
    $extraParams = '';
    if ($keyword !== '') { $extraParams .= '&keyword=' . urlencode($keyword); }
    if ($isProductType && $currentSort !== 'default') { $extraParams .= '&sort=' . urlencode($currentSort); }
    foreach (['brand', 'tag', 'pmin', 'pmax'] as $fk) {
        $fv = trim((string) ($_GET[$fk] ?? ''));
        if ($fv !== '') { $extraParams .= '&' . $fk . '=' . urlencode($fv); }
    }
    $queryStr = $extraParams !== '' ? '?' . ltrim($extraParams, '&') : '';

    if ($isProductType && $productCategory) {
        $catSlug = $productCategory['slug'] ?? '';
        return $p === 1
            ? '/product/' . $catSlug . '.html' . $queryStr
            : '/product/' . $catSlug . '/page/' . $p . '.html' . $queryStr;
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
