<?php

declare(strict_types=1);

// Only server-authorized destinations are exposed; document data never supplies URLs.
$bloxSourceLinks = [];
$sourceLang = rawurlencode(($isProductBlox || $isContentListBlox)
    ? (string) ($page['lang'] ?? siteLang()) : siteLang());
if (hasPermission('*')) {
    foreach (['about', 'cta', 'stats', 'advantage', 'testimonials', 'partners'] as $sourceType) {
        $bloxSourceLinks[$sourceType] = [
            'url' => '/admin/setting_home.php?lang=' . $sourceLang . '#home-source-' . $sourceType,
            'label' => __('blox_source_manage_shared'),
            'scope' => __('blox_source_shared_scope'),
        ];
    }
}
if (hasPermission('edit_product')) {
    $bloxSourceLinks['product-catalog'] = [
        'url' => '/admin/product.php?lang=' . $sourceLang,
        'label' => __('blox_source_manage_products'),
        'scope' => __('blox_source_catalog_scope'),
    ];
}
if (hasPermission('edit_article')) {
    $bloxSourceLinks['content-catalog'] = [
        'url' => '/admin/article.php?lang=' . $sourceLang,
        'label' => __('blox_source_manage_articles'),
        'scope' => __('blox_source_catalog_scope'),
    ];
}
