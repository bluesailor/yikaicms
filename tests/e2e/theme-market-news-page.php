<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!is_file($root . '/tests/smoke/fixtures.json')
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}

$GLOBALS['yikai_config_runtime_overrides'] = [
    'current_theme' => 'minimal',
    'html_cache_enabled' => '0',
];
$_SERVER['REQUEST_URI'] = '/news.html';
require $root . '/news.php';
