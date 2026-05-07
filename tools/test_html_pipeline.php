<?php
/**
 * Smoke test for HtmlPipeline. Run from CLI:
 *   php tools/test_html_pipeline.php
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/includes/hooks.php';
require_once ROOT_PATH . '/includes/HtmlPipeline.php';

// Stub config() since we're not loading full init
if (!function_exists('config')) {
    function config(string $k, mixed $d = ''): mixed {
        if ($k === 'site_url') return 'https://example.com';
        return $d;
    }
}

HtmlPipeline::bootstrap();

$samples = [
    'lazy_load' => '<p>Hello <img src="/x.jpg" alt="x"> and <img src="/y.png"></p>',
    'alt_fallback' => '<img src="/a.jpg" title="A title"><img src="/b.jpg">',
    'external_link' => '<a href="https://google.com/x">Google</a> <a href="/local">Local</a> <a href="https://example.com/page">Same site</a>',
    'heading_ids' => '<h2>First</h2><p>x</p><h3>Sub</h3><h2 id="custom">Has ID</h2><h2>Last</h2>',
    'mixed' => '<div><img src="/i.jpg"><a href="https://github.com">GH</a><h2>T</h2></div>',
];

foreach ($samples as $name => $html) {
    $out = apply_filters('content_render', $html);
    echo "=== $name ===\n";
    echo "IN : $html\n";
    echo "OUT: $out\n\n";
}
