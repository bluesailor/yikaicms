<?php
/**
 * Shop plugin language pack (English; falls back to zh-CN keys).
 */

declare(strict_types=1);

return [
    'shop_menu' => 'Shop',
    'shop_menu_sales' => 'Product sales',
    'shop_nav_sales' => 'Sales settings',
    'shop_nav_orders' => 'Orders',
    'shop_sales_title' => 'Product sales settings',
    'shop_sales_desc' => 'Enabling sales for a product adds selling power on top of it; products without sales enabled stay as normal showcase pages. Disabling the plugin never affects product pages.',
    'shop_col_product' => 'Product',
    'shop_col_price_display' => 'Product price',
    'shop_col_sale_price' => 'Sale price (empty = product price)',
    'shop_col_sku' => 'SKU',
    'shop_col_stock' => 'Stock',
    'shop_col_status' => 'Listed',
    'shop_col_sales' => 'Sold',
    'shop_col_action' => 'Action',
    'shop_btn_save' => 'Save',
    'shop_btn_search' => 'Search',
    'shop_placeholder_search' => 'Product name / model keyword',
    'shop_filter_all' => 'All',
    'shop_filter_on' => 'On sale',
    'shop_filter_off' => 'Disabled',
    'shop_status_on' => 'On sale',
    'shop_status_off' => 'Disabled',
    'shop_saved' => 'Saved',
    'shop_err_product' => 'Product not found',
    'shop_err_sku_len' => 'SKU must be at most 64 characters',
    'shop_err_status' => 'Invalid listing status',
    'shop_lang_tag' => 'Language',
    'shop_stock_infinite_hint' => 'With stock 0 and listing off, no buy entry is shown',
    'shop_order_status_pending_payment' => 'Pending payment',
    'shop_order_status_paid' => 'Paid',
    'shop_order_status_awaiting_ship' => 'Awaiting shipment',
    'shop_order_status_shipped' => 'Shipped',
    'shop_order_status_completed' => 'Completed',
    'shop_order_status_closed' => 'Closed',
    'shop_err_schema' => 'Shop data initialization failed; check database permissions and retry (details in system log)',
    'shop_shared_group' => 'shared across languages',
    'shop_err_price' => 'Invalid sale price (must be positive, at most 2 decimals, max 99999999.99; empty = product price)',
    'shop_err_stock' => 'Stock must be an integer 0 - 9999999999',
];
