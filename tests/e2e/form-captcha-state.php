<?php
declare(strict_types=1);
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-')
    || !is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')
    || !is_file(ROOT_PATH . '/storage/e2e-spam-settings.json')
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404); exit;
}
require ROOT_PATH . '/config/config.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') { http_response_code(404); exit; }
// Test-only oracle for this disposable site's current browser session, never production.
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(['code' => $_SESSION['form_captcha'] ?? ''], JSON_THROW_ON_ERROR);
