<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/models/autoload.php';
require_once ROOT_PATH . '/includes/FormUploadService.php';

checkLogin();
requirePermission('form');

$id = getInt('id');
$field = (string) get('field', '');
if ($id <= 0 || preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,39}$/D', $field) !== 1) {
    http_response_code(404);
    exit;
}
$submission = formModel()->find($id);
$extra = $submission ? json_decode((string) ($submission['extra'] ?? ''), true) : null;
$reference = is_array($extra) && is_string($extra[$field] ?? null) ? $extra[$field] : '';
$metadata = FormUploadService::decodeReference($reference);
$service = new FormUploadService();
$path = $metadata !== null ? $service->pathForReference($reference) : null;
if ($metadata === null || $path === null) {
    http_response_code(404);
    exit;
}

$fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $metadata['name']) ?: 'attachment.' . pathinfo($metadata['path'], PATHINFO_EXTENSION);
header('Content-Type: application/octet-stream');
header('Content-Length: ' . (string) filesize($path));
header('Content-Disposition: attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($metadata['name']));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; sandbox");
header('Cache-Control: private, no-store');
readfile($path);
