<?php
declare(strict_types=1);

/** Local gallery server: only the page and catalog-declared public assets may be read. */
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
if (!in_array($method, ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}
$path = is_string($path) ? rawurldecode($path) : '';
if ($path === '/' || $path === '/index.php') {
    header('Content-Type: text/html; charset=utf-8');
    if ($method !== 'HEAD') require __DIR__ . '/index.php';
    exit;
}
$catalog = json_decode((string) @file_get_contents(__DIR__ . '/catalog.json'), true, 32);
$allowed = [];
foreach (is_array($catalog['templates'] ?? null) ? $catalog['templates'] : [] as $item) {
    $slug = (string) ($item['slug'] ?? '');
    $version = (string) ($item['version'] ?? '');
    $package = (string) ($item['package'] ?? '');
    if (preg_match('/^[a-z0-9][a-z0-9-]*$/D', $slug) !== 1 || preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1
        || $package !== $slug . '-site-v' . $version . '.zip') continue;
    $allowed['/assets/site-templates/' . $slug . '/' . $version . '/preview.webp'] = 'image/webp';
    $allowed['/packages/' . $package] = 'application/zip';
}
if (isset($allowed[$path])) {
    $candidate = str_replace('\\', '/', __DIR__) . $path;
    $resolved = realpath($candidate);
    if (is_string($resolved) && strcasecmp(str_replace('\\', '/', $resolved), $candidate) === 0 && is_file($candidate) && !is_link($candidate)) {
        header('Content-Type: ' . $allowed[$path]);
        header('Content-Length: ' . (string) filesize($candidate));
        if ($allowed[$path] === 'application/zip') header('Content-Disposition: attachment; filename="' . basename($candidate) . '"');
        if ($method !== 'HEAD') readfile($candidate);
        exit;
    }
}
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
if ($method !== 'HEAD') echo 'Not found';
