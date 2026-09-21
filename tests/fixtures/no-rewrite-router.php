<?php
declare(strict_types=1);
// Development server fixture: unlike PHP's implicit index fallback, missing paths really return 404.
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$root = realpath((string) $_SERVER['DOCUMENT_ROOT']);
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$file = realpath($root . '/' . ltrim($path, '/'));
if ($file !== false && ($file === $root || str_starts_with($file, $root . DIRECTORY_SEPARATOR))) {
    if (is_file($file)) return false;
    if (is_file($file . '/index.php')) { require $file . '/index.php'; return true; }
}
http_response_code(404);
echo 'Not found';
