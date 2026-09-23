<?php

declare(strict_types=1);

// Only server-authorized destinations are exposed; document data never supplies URLs.
$bloxSourceLinks = [];
$sourceLang = rawurlencode(($isProductBlox || $isContentListBlox)
    ? (string) ($page['lang'] ?? siteLang()) : siteLang());
if (hasPermission('*')) {
    foreach (['about', 'cta', 'stats', 'advantage', 'testimonials'] as $sourceType) {
        $bloxSourceLinks[$sourceType] = [
            'url' => '/admin/setting_home.php?lang=' . $sourceLang . '#home-source-' . $sourceType,
            'label' => __('blox_source_manage_shared'),
            'scope' => __('blox_source_shared_scope'),
        ];
    }
}
if (hasPermission('link')) {
    $bloxSourceLinks['partners'] = [
        'url' => '/admin/link.php?lang=' . $sourceLang,
        'label' => __('blox_partners_manage'),
        'scope' => '',
    ];
}
if (hasPermission('edit_product')) {
    $bloxSourceLinks['product-catalog'] = [
        'url' => '/admin/product.php?lang=' . $sourceLang,
        'label' => __('blox_source_manage_products'),
        'scope' => __('blox_source_catalog_scope'),
    ];
}
// 内容目录的数据管理入口随栏目类型走：案例栏目进案例管理，其余进文章管理
$contentCatalogIsCase = (string) ($page['type'] ?? '') === 'case';
if (hasPermission($contentCatalogIsCase ? 'edit_case' : 'edit_article')) {
    $bloxSourceLinks['content-catalog'] = [
        'url' => ($contentCatalogIsCase ? '/admin/case.php' : '/admin/article.php') . '?lang=' . $sourceLang,
        'label' => __($contentCatalogIsCase ? 'admin_case' : 'blox_source_manage_articles'),
        'scope' => __('blox_source_catalog_scope'),
    ];
}
if (hasPermission('edit_download')) {
    $bloxSourceLinks['download-catalog'] = [
        'url' => '/admin/download.php',
        'label' => __('admin_download'),
        'scope' => __('blox_source_catalog_scope'),
    ];
}
if (hasPermission('edit_job')) {
    $bloxSourceLinks['job-catalog'] = [
        'url' => '/admin/job.php',
        'label' => __('admin_job'),
        'scope' => __('blox_source_catalog_scope'),
    ];
}
