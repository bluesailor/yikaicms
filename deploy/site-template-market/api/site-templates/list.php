<?php
declare(strict_types=1);

// Deploy this directory under the update service root only after release authorization.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || ($_GET['protocol_version'] ?? '') !== '1') {
    http_response_code(400);
    echo '{"code":1,"msg":"Unsupported site template catalog request"}';
    exit;
}
$path = dirname(__DIR__, 2) . '/data/site-templates.json';
$body = is_file($path) && @filesize($path) <= 524288 ? @file_get_contents($path) : false;
$data = is_string($body) ? json_decode($body, true, 32) : null;
if (!is_array($data) || !is_array($data['templates'] ?? null) || count($data['templates']) > 200) {
    http_response_code(503);
    echo '{"code":1,"msg":"Site template catalog unavailable"}';
    exit;
}
$items = [];
foreach ($data['templates'] as $entry) {
    if (!is_array($entry)) continue;
    $slug = $entry['slug'] ?? '';
    $version = $entry['version'] ?? '';
    if (!is_string($slug) || !is_string($version)
        || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,78}[a-z0-9])?$/D', $slug) !== 1
        || preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1) continue;
    // A strict allowlist prevents local preparation paths or private release notes leaking into responses.
    $item = array_intersect_key($entry, array_flip([
        'slug', 'name', 'name_en', 'name_ja', 'description', 'description_en', 'description_ja',
        'version', 'cms', 'format_version', 'category', 'category_name', 'category_name_en', 'category_name_ja', 'requires_php', 'screenshot', 'status', 'tier',
    ]));
    $package = $slug . '-site-v' . $version . '.zip';
    $hash = $entry['hash'] ?? '';
    $sig = $entry['sig'] ?? '';
    $size = $entry['size_bytes'] ?? 0;
    $ready = ($entry['status'] ?? '') === 'published' && ($entry['tier'] ?? 'free') === 'free'
        && in_array($entry['format_version'] ?? 0, [1, 2], true)
        && ($entry['package'] ?? '') === $package && is_string($hash) && preg_match('/^sha256:[a-f0-9]{64}$/D', $hash) === 1
        && is_string($sig) && ($decoded = base64_decode($sig, true)) !== false && $decoded !== ''
        && is_int($size) && $size > 0 && $size <= 33554432;
    if ($ready) {
        $item += ['package' => $package, 'hash' => $hash, 'sig' => $sig, 'size_bytes' => $size,
            'download_url' => 'https://update.yikaicms.com/packages/site-templates/' . $package];
    } else {
        $item['status'] = 'draft';
    }
    $items[] = $item;
}
echo json_encode(['code' => 0, 'data' => ['protocol_version' => 1,
    'updated_at' => is_string($data['updated_at'] ?? null) ? $data['updated_at'] : '', 'templates' => $items]],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
