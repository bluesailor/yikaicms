<?php
/** Read-only native product layout, never an anonymous override or cache variant. */
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
checkLogin();
requireBloxTemplateTypePermission('product-detail');
if (!bloxPageEditorEnabled() || !bloxAdvancedFeaturesEnabled()) error(__('blox_feature_disabled'));

$previewProduct = productModel()->getPublished(getInt('id'));
if (!$previewProduct || !isset(availableLanguages()[(string) $previewProduct['lang']])) {
    http_response_code(404);
    exit(e(__('error_product_not_found')));
}
define('SITE_LANG', (string) $previewProduct['lang']);
define('YK_PRODUCT_NATIVE_PREVIEW', true);
$GLOBALS['_LANG_DATA'] = null;
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
require ROOT_PATH . '/product.php';
