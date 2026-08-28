<?php
/** Deterministic renderer used to capture and verify built-in section previews. */

declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/init.php';

$slug = trim((string) ($_GET['slug'] ?? ''));
if (preg_match('/^[a-z0-9][a-z0-9-]{0,98}$/', $slug) !== 1) {
    http_response_code(400);
    exit('Invalid section slug');
}

try {
    $template = (new BloxBuiltinTemplateProvider())->resolve($slug, 'page');
} catch (Throwable) {
    http_response_code(404);
    exit('Section not found');
}

$document = json_encode([
    'schema' => 1,
    'settings' => [],
    'sections' => $template['sections'] ?? [],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
?><!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="/assets/fontawesome/css/all.min.css">
    <link rel="stylesheet" href="/assets/tabler/tabler-icons.min.css">
    <title>Section preview</title>
    <style>html,body{margin:0;width:100%;min-height:100%;background:#fff}body{overflow:hidden}section{min-height:525px;display:flex;align-items:center}section>div{width:100%}</style>
</head>
<body><?php echo BlockRenderer::render($document); ?></body>
</html>
