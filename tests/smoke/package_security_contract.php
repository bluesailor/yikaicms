<?php

declare(strict_types=1);

define('ROOT_PATH', is_dir(__DIR__ . '/includes') ? __DIR__ : dirname(__DIR__, 2));

require ROOT_PATH . '/includes/security.php';

$payloads = [
    '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><circle cx="5" cy="5" r="4"/></svg>',
    '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><a href="javascript:alert(\'x\')">x</a></svg>',
    '<svg xmlns="http://www.w3.org/2000/svg"><a href="java&#x73;cript:alert(1)">x</a></svg>',
];

foreach ($payloads as $payload) {
    $clean = sanitizeSvg($payload);
    $decoded = strtolower(html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (str_contains($decoded, '<script')
        || str_contains($decoded, 'onload')
        || str_contains($decoded, 'javascript:')) {
        fwrite(STDERR, "SVG 安全契约失败\n");
        exit(1);
    }
}

$safe = sanitizeSvg('<svg xmlns="http://www.w3.org/2000/svg"><circle cx="5" cy="5" r="4"/></svg>');
if (!str_contains($safe, '<circle')) {
    fwrite(STDERR, "SVG 安全契约误删安全图形\n");
    exit(1);
}

echo "SVG 安全契约通过\n";
