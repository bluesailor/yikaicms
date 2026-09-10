<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-')
    || !is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) throw new RuntimeException('Disposable site required');
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') throw new RuntimeException('Local SQLite required');
$statePath = STORAGE_PATH . '/e2e-spam-settings.json';
$action = $argv[1] ?? '';
if ($action === 'setup') {
    if (is_file($statePath)) throw new RuntimeException('Fixture already active');
    $templates = [];
    foreach (['contact', 'product-inquiry'] as $slug) {
        $row = formTemplateModel()->findBySlug($slug);
        if (!$row) throw new RuntimeException('Seed form missing');
        $templates[] = $row;
    }
    $settings = ['form_max_submits' => config('form_max_submits', '5'), 'form_throttle_minutes' => config('form_throttle_minutes', '5'),
        'html_cache_enabled' => config('html_cache_enabled', '0'), 'html_cache_ttl' => config('html_cache_ttl', '300'),
        'form_signature_max_age' => config('form_signature_max_age', '7200')];
    file_put_contents($statePath, json_encode(compact('templates', 'settings'), JSON_THROW_ON_ERROR));
    foreach ($templates as $row) formTemplateModel()->updateById((int) $row['id'], ['captcha' => 0]);
    settingModel()->saveBatch(['form_max_submits' => '5', 'form_throttle_minutes' => '5']);
} elseif ($action === 'captcha' || $action === 'short-expiry') {
    if (!is_file($statePath)) throw new RuntimeException('Prepare fixture first');
    if ($action === 'captcha') {
        foreach (['contact', 'product-inquiry'] as $slug) {
            $row = formTemplateModel()->findBySlug($slug);
            formTemplateModel()->updateById((int) $row['id'], ['captcha' => 1]);
        }
    } else {
        settingModel()->saveBatch(['html_cache_enabled' => '1', 'html_cache_ttl' => '300', 'form_signature_max_age' => '5']);
    }
} elseif ($action === 'restore') {
    if (is_file($statePath)) {
        $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
        foreach ($state['templates'] as $row) {
            $id = (int) $row['id']; unset($row['id']);
            formTemplateModel()->updateById($id, $row);
        }
        settingModel()->saveBatch($state['settings']);
        unlink($statePath);
    }
} elseif ($action === 'result') {
    $name = $argv[2] ?? '';
    if (!preg_match('/^E7 Form [0-9]+$/', $name)) throw new RuntimeException('Invalid marker');
    $mailPath = STORAGE_PATH . '/e2e-form-mail.jsonl';
    $mail = is_file($mailPath) ? array_map(static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($mailPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
    echo json_encode(['rows' => formModel()->where(['name' => $name]), 'mail' => array_values(array_filter($mail, static fn(array $row): bool => $row['name'] === $name))], JSON_THROW_ON_ERROR);
} else { throw new RuntimeException('Unknown fixture action'); }
