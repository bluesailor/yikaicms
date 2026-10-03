<?php

declare(strict_types=1);

// Isolated render fixture: the test bootstrap uses SQLite in memory, never the site database.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__, 2) . '/bootstrap.php';
require ROOT_PATH . '/includes/builder/bootstrap.php';

$items = [
    ['question' => 'Overview', 'answer' => 'Overview panel'],
    ['question' => 'Features', 'answer' => 'Features panel'],
    ['question' => '服务', 'answer' => 'Service panel'],
];
echo json_encode([
    'deep' => (new TabsElement())->render(['items' => $items, 'deep_link' => true, 'link_prefix' => 'plans']),
    'autoplay' => (new TabsElement())->render(['items' => $items, 'autoplay' => true, 'autoplay_interval' => 2]),
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
