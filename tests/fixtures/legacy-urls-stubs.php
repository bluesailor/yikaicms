<?php
// LegacyUrlsTest 用的最小桩：生产实现在 includes/functions.php（单测不加载整份）。
declare(strict_types=1);

if (!function_exists('langPrefix')) {
    function langPrefix(?string $lang = null): string { return ''; }
}
if (!function_exists('searchUrl')) {
    function searchUrl(string $keyword = '', string $type = 'all', int $page = 1): string
    {
        return '/search.php' . ($keyword !== '' ? '?' . http_build_query(['keyword' => $keyword], '', '&', PHP_QUERY_RFC3986) : '');
    }
}
if (!function_exists('getMeta')) {
    function getMeta(string $ownerType, int $ownerId, string $key): ?string
    {
        $row = db()->fetchOne('SELECT meta_value FROM ' . DB_PREFIX . 'metas WHERE owner_type = ? AND owner_id = ? AND meta_key = ?', [$ownerType, $ownerId, $key]);
        return $row ? (string) $row['meta_value'] : null;
    }
}
if (!function_exists('productRouteModel')) {
    function productRouteModel(): ProductRouteModel { return new ProductRouteModel(); }
}
