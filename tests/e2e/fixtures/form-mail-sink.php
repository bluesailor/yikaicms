<?php
declare(strict_types=1);

if (!defined('ROOT_PATH') || !str_starts_with(basename(ROOT_PATH), 'yikai-e2e-')
    || !is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')
    || DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Disposable local site required for mail sink');
}

// Capture at the existing notification hook, before any SMTP transport.
add_filter('mail_notify', static function (array $mail): array {
    $entry = ['tpl' => $mail['tpl'], 'name' => $mail['vars']['name'], 'body' => $mail['body']];
    file_put_contents(ROOT_PATH . '/storage/e2e-form-mail.jsonl', json_encode($entry, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    return [];
});
