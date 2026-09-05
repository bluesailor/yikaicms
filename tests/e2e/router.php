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

require $root . '/index.php';
