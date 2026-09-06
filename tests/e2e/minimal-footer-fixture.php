<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
define('ROOT_PATH', dirname(__DIR__, 2));
if (!is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')
    || (!getenv('CI') && !str_starts_with(basename(ROOT_PATH), 'yikai-e2e-'))) {
    throw new RuntimeException('Disposable smoke site required');
}
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
require ROOT_PATH . '/includes/builder/bootstrap.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Local SQLite required');
}
$action = $argv[1] ?? '';
if (!in_array($action, ['native', 'preset', 'restore'], true)) throw new RuntimeException('Invalid action');
$snapshot = ROOT_PATH . '/storage/minimal-footer-test.json';
if ($action === 'restore') {
    if (!is_file($snapshot)) exit(0);
    $state = json_decode((string) file_get_contents($snapshot), true, 64, JSON_THROW_ON_ERROR);
    foreach ($state['settings'] as $key => $row) {
        if ($row === null) db()->delete('settings', '`key` = ?', [$key]);
        else settingModel()->saveBatch([$key => $row['value']]);
    }
    db()->delete('blox_templates', 'source_ref = ?', ['e2e-minimal-footer']);
    unlink($snapshot);
    exit(0);
}
if (!is_file($snapshot)) {
    $settings = [];
    foreach (['current_theme', 'blox_custom_footer_enabled'] as $key) {
        $settings[$key] = db()->fetchOne('SELECT value FROM ' . DB_PREFIX . 'settings WHERE `key` = ?', [$key]);
    }
    file_put_contents($snapshot, json_encode(['settings' => $settings], JSON_THROW_ON_ERROR));
}
settingModel()->saveBatch(['current_theme' => 'minimal', 'blox_custom_footer_enabled' => $action === 'preset' ? '1' : '0']);
if ($action === 'preset') {
    db()->delete('blox_templates', 'source_ref = ?', ['e2e-minimal-footer']);
    $template = BloxTemplateImporter::importJson((string) file_get_contents(ROOT_PATH . '/templates/blox/areas/minimal-site-footer.json'), 1, 'import', 'e2e-minimal-footer');
    bloxTemplateModel()->publishDraft($template['id']);
}
