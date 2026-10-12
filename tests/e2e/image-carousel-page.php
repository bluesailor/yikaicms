<?php
/** 图片轮播缩略图导航与容器堆叠（2.1.0）的 e2e 页面：只渲染两个元素，加载它们自己的样式与脚本。 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!is_file($root . '/tests/smoke/fixtures.json')
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
define('ROOT_PATH', $root);
require $root . '/includes/init.php';
require_once $root . '/includes/builder/bootstrap.php';

$items = [];
foreach (['banner-1', 'banner-2', 'banner-3', 'banner-1', 'banner-2'] as $index => $name) {
    $items[] = ['image' => '/assets/images/demo/' . $name . '.svg', 'alt' => 'Slide ' . ($index + 1), 'caption' => 'Caption ' . ($index + 1)];
}
$carousel = (new ImageCarouselElement())->render(['items' => $items, 'nav' => 'thumbs']);
$cards = '';
foreach (['A', 'B', 'C'] as $letter) {
    $cards .= '<div class="bg-white border rounded-lg p-8" style="height:240px" data-stack-card>' . $letter . '</div>';
}
$stack = BuilderRegistry::get('container')->render(['stack_scroll' => true], $cards);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="/assets/css/tailwind.css">
<link rel="stylesheet" href="/assets/css/yikay-carousel.css">
<link rel="stylesheet" href="/assets/css/yikay-stack.css">
</head><body class="bg-gray-100">
<main class="max-w-3xl mx-auto p-6 space-y-12">
<section data-testid="carousel"><?php echo $carousel; ?></section>
<section data-testid="stack"><?php echo $stack; ?></section>
<div style="height:1200px"></div>
</main>
<script src="/assets/js/yikay-carousel.js"></script>
</body></html>
