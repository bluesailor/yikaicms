<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!is_file($root . '/tests/smoke/fixtures.json')
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
$theme = in_array($_GET['theme'] ?? '', ['business', 'minimal'], true) ? $_GET['theme'] : 'default';
$language = in_array($_GET['lang'] ?? '', ['en', 'ja'], true) ? $_GET['lang'] : 'zh-CN';
$id = filter_var($_GET['id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
$slug = is_string($_GET['slug'] ?? null) && preg_match('/^[a-z0-9_-]+$/', $_GET['slug']) === 1 ? $_GET['slug'] : '';
$GLOBALS['yikai_config_runtime_overrides'] = [
    'current_theme' => $theme, 'html_cache_enabled' => '0', 'site_lang' => $language,
    'blox_custom_header_enabled' => '0', 'blox_custom_footer_enabled' => '0',
    'catalog_product_page_size' => '12', 'url_mode' => 'query',
];
// Exercise real list/detail routes with the installed seed, not substituted cover values.
$_GET = ['yk_route' => $id > 0 || $slug !== '' ? 'product' : 'product_list', 'lang' => $language, 'preview' => '1'];
if ($id > 0) { $_GET['id'] = $id; }
if ($slug !== '') { $_GET['slug'] = $slug; }
$_REQUEST = $_GET;
$_SERVER['REQUEST_URI'] = '/index.php?' . http_build_query($_GET);
require $root . '/index.php';
