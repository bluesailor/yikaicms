<?php

declare(strict_types=1);

// Isolated render fixture: the test bootstrap uses SQLite in memory, never the site database.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__, 2) . '/bootstrap.php';
require ROOT_PATH . '/includes/builder/bootstrap.php';

function getDefaultNavigation(): array
{
    $items = [];
    for ($i = 1; $i <= 10; $i++) {
        $items[] = [
            'name' => 'Section ' . $i, '_url' => '/section-' . $i, 'icon' => 'link',
            'children' => $i === 9 ? [[
                'name' => 'Child', '_url' => '/child', 'icon' => 'link',
                'children' => [['name' => 'Grandchild', '_url' => '/grandchild']],
            ]] : [],
        ];
    }
    return $items;
}

$data = ['dropdown' => true, 'cta_text' => 'Contact', 'cta_url' => '/contact'];
echo json_encode([
    'mega' => (new NavMegaElement())->render($data),
    'standard' => (new NavElement())->render($data),
], JSON_THROW_ON_ERROR);
