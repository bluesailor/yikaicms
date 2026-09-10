<?php
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-')
    || !is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404); exit;
}
$lang = $_GET['lang'] ?? 'zh-CN';
if (!in_array($lang, ['zh-CN', 'en', 'ja'], true)) { http_response_code(400); exit; }
define('SITE_LANG', $lang);
require ROOT_PATH . '/includes/init.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') { http_response_code(404); exit; }
header('Cache-Control: no-store');
?><!doctype html><html lang="<?= e($lang) ?>"><head><meta charset="utf-8"><title>Form spam regression</title></head><body>
<?= renderFormTemplate('contact') ?>
<?= renderFormTemplate('product-inquiry') ?>
</body></html>
