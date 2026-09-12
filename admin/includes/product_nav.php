<?php
declare(strict_types=1);
require_once __DIR__ . '/module_nav.php';

$productNavItems = [];
$productNavCurrent = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
foreach ([
    'product' => ['product_tab_list', 'package'],
    'product_category' => ['product_tab_category', 'category'],
    'product_brand' => ['product_tab_brand', 'building-store'],
    'product_tag' => ['product_tab_tag', 'tags'],
    'product_setting' => ['product_tab_setting', 'settings'],
] as $route => [$key, $icon]) {
    $productNavItems[] = [
        'label' => __($key),
        'url' => '/admin/' . $route . '.php?lang=' . rawurlencode((string) $_viewLang),
        'icon' => $icon,
        'active' => $productNavCurrent === $route . '.php',
        'testid' => 'admin-module-' . $route,
    ];
}
adminModuleStart($productNavItems, __('admin_product'));
