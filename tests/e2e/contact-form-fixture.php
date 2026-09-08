<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-')
    || !is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) {
    throw new RuntimeException('Disposable site required');
}
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Local SQLite required');
}

$action = $argv[1] ?? '';
$statePath = ROOT_PATH . '/storage/e2e-contact-settings.json';
$pluginDir = ROOT_PATH . '/plugins/e2e-form-mail-sink';
if ($action === 'restore') {
    if (is_file($statePath)) {
        $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
        settingModel()->saveBatch($state['settings']);
        $template = $state['template'];
        $id = (int) $template['id'];
        unset($template['id']);
        formTemplateModel()->updateById($id, $template);
        pluginModel()->deleteBySlug('e2e-form-mail-sink');
        if (is_file($pluginDir . '/main.php')) unlink($pluginDir . '/main.php');
        if (is_dir($pluginDir)) rmdir($pluginDir);
        unlink($statePath);
    }
    exit;
}
if ($action === 'result') {
    $name = $argv[2] ?? '';
    if (!preg_match('/^E7 Form [0-9]+$/', $name)) throw new RuntimeException('Invalid test marker');
    $rows = formModel()->where(['name' => $name]);
    $mailPath = ROOT_PATH . '/storage/e2e-form-mail.jsonl';
    $mail = is_file($mailPath) ? array_map(static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($mailPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) : [];
    echo json_encode(['rows' => $rows, 'mail' => $mail], JSON_THROW_ON_ERROR);
    exit;
}
if ($action !== 'setup' || is_file($statePath)) throw new RuntimeException('Invalid fixture state');
$template = formTemplateModel()->findBySlug('contact');
$page = channelModel()->findWhere(['type' => 'page', 'slug' => 'contact', 'lang' => 'zh-CN']);
if (!$template || !$page) throw new RuntimeException('Contact demo content required');
$settings = [
    'html_cache_enabled' => '0', 'map_lat' => '', 'map_lng' => '',
    'mail_notify_form' => '1', 'mail_admin' => 'sink@example.invalid',
    'smtp_host' => '127.0.0.1', 'smtp_user' => '', 'smtp_pass' => '',
    'form_security_version' => '2', 'form_signature_max_age' => '7200',
];
$before = [];
foreach ($settings as $key => $_) $before[$key] = config($key, '');
file_put_contents($statePath, json_encode(['settings' => $before, 'template' => $template], JSON_THROW_ON_ERROR));
if (!mkdir($pluginDir)) throw new RuntimeException('Cannot create local mail sink');
if (!copy(__DIR__ . '/fixtures/form-mail-sink.php', $pluginDir . '/main.php')) throw new RuntimeException('Cannot install local mail sink');
pluginModel()->activate('e2e-form-mail-sink');
settingModel()->saveBatch($settings);
// No network transport is possible even if the hook is accidentally removed:
// SMTP credentials stay empty. All tested edits below are made through Blox UI.
echo json_encode(['page' => (int) $page['id']], JSON_THROW_ON_ERROR);
