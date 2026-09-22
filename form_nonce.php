<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['code' => 1, 'msg' => __('form_nonce_invalid')], JSON_UNESCAPED_UNICODE);
    exit;
}

$slug = trim((string) post('form_slug', ''));
$template = preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $slug) === 1
    ? formTemplateModel()->findBySlug($slug) : null;
if (!$template) {
    http_response_code(404);
    echo json_encode(['code' => 1, 'msg' => __('form_nonce_invalid')], JSON_UNESCAPED_UNICODE);
    exit;
}

$lang = function_exists('siteLang') ? siteLang() : (string) config('site_lang', 'zh-CN');
$fieldsRaw = (string) ($template['fields'] ?? '');
$localized = (string) ($template['fields_' . $lang] ?? '');
if (trim($localized) !== '') $fieldsRaw = $localized;
if (!formFieldSetValid(formFieldsFromStored($fieldsRaw))) {
    http_response_code(422);
    echo json_encode(['code' => 1, 'msg' => __('form_nonce_invalid')], JSON_UNESCAPED_UNICODE);
    exit;
}

$secret = defined('ENCRYPT_KEY') ? (string) ENCRYPT_KEY : '';
$nonce = FormSubmissionNonce::issue($slug, $secret);
if ($nonce === '') {
    http_response_code(503);
    echo json_encode(['code' => 1, 'msg' => __('form_guard_unavailable')], JSON_UNESCAPED_UNICODE);
    exit;
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
echo json_encode(['code' => 0, 'nonce' => $nonce], JSON_UNESCAPED_UNICODE);
