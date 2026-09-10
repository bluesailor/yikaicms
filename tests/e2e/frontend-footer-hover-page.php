<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!str_starts_with(basename($root), 'yikai-e2e-')
    || !is_file($root . '/storage/.smoke-state-backup/manifest.json')
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
$language = in_array($_GET['lang'] ?? '', ['en', 'ja'], true) ? $_GET['lang'] : 'zh-CN';
$GLOBALS['yikai_config_runtime_overrides'] = [
    'current_theme' => 'default', 'html_cache_enabled' => '0',
    'blox_custom_header_enabled' => '0', 'blox_custom_footer_enabled' => '0',
];
$_GET = ['_lang' => $language];
$_REQUEST = $_GET;
$_SERVER['REQUEST_URI'] = '/index.php?' . http_build_query($_GET);
require $root . '/index.php';
