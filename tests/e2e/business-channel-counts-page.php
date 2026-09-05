<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!is_file($root . '/tests/smoke/fixtures.json')
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
$type = (string) ($_GET['type'] ?? '');
if (!in_array($type, ['case', 'article'], true)) {
    http_response_code(400);
    exit;
}

define('ROOT_PATH', $root);
require ROOT_PATH . '/includes/init.php';
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

$items = [];
for ($i = 1; $i <= 8; $i++) {
    $items[] = [
        'id' => $i,
        'title' => 'Fixture ' . $type . ' ' . $i,
        'slug' => 'fixture-' . $type . '-' . $i,
        'cover' => $i === 3 ? '' : '/assets/images/demo/article-201.svg',
        'type' => $type === 'article' ? 'article' : 'case',
        'channel_type' => $type === 'case' ? 'case' : 'list',
        'channel_slug' => 'news',
        'publish_time' => 1700000000 + $i,
        'created_at' => 1700000000 + $i,
    ];
}
$currentChannel = [
    'id' => 999,
    'name' => 'Fixture channel',
    'description' => '',
    'slug' => 'fixture-channel',
    'type' => $type === 'case' ? 'case' : 'list',
    'is_product' => false,
    'per_row' => 4,
    'contents' => $items,
];
$block = [];
require ROOT_PATH . '/marketplace/themes/business/blocks/channel.php';
