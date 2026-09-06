<?php

declare(strict_types=1);

// PHP's development server does not apply the production rewrite rules.
// Serve real files normally and send every other frontend path to index.php,
// matching the WordPress/BaoTa catch-all configuration supported by YikaiCMS.
$documentRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');
$root = is_file($documentRoot . DIRECTORY_SEPARATOR . 'index.php')
    ? realpath($documentRoot)
    : dirname(__DIR__, 2);
if (!is_string($root) || $root === '') {
    $root = dirname(__DIR__, 2);
}
$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$decoded = rawurldecode($path);
$candidate = realpath($root . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $decoded), DIRECTORY_SEPARATOR));

if ($candidate !== false
    && str_starts_with($candidate, $root . DIRECTORY_SEPARATOR)
    && is_file($candidate)) {
    return false;
}

// PHP 内置服务器不会像 Apache DirectoryIndex 那样稳定处理目录首页。
// 目录请求必须先查找同目录的 index.php，否则 /admin/ 会错误落到前台
// index.php，最终被 Dispatcher 当成未知前台路径返回 404。
if ($candidate !== false
    && str_starts_with($candidate, $root . DIRECTORY_SEPARATOR)
    && is_dir($candidate)) {
    $directoryIndex = $candidate . DIRECTORY_SEPARATOR . 'index.php';
    if (is_file($directoryIndex)) {
        require $directoryIndex;
        return true;
    }
}

require $root . '/index.php';
