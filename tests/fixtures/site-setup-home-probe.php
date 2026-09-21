<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function config(string $key, mixed $default = ''): mixed
{
    return $GLOBALS['setup_override'][$key] ?? settingModel()->get($key, $default);
}
define('ROOT_PATH', dirname(__DIR__, 2));
define('DB_DRIVER', 'sqlite');
define('DB_PATH', ':memory:');
define('DB_PREFIX', '');
define('DB_CHARSET', 'utf8mb4');
define('DEBUG', true);
function __(string $key, array $params = []): string { return $key; }
require ROOT_PATH . '/config/database.php';
require ROOT_PATH . '/includes/models/autoload.php';
require_once ROOT_PATH . '/includes/SiteSetup.php';
db()->execute('CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, `key` TEXT UNIQUE, `value` TEXT, `group` TEXT, name TEXT, tip TEXT)');
settingModel()->set('home_layout_active', '1');
settingModel()->set('home_blox_active', '1');
settingModel()->set('home_blox_data', 'DRAFT');
settingModel()->set('home_layout_published', 'PUBLICATION');
settingModel()->set('home_blox_history', 'HISTORY');
$before = SiteSetup::homeState();
$undo = SiteSetup::changeHomeFlags(['home_layout_active' => '0', 'home_blox_active' => '0'], SiteSetup::homeFingerprint());
$results = ['switched' => config('home_layout_active') === '0' && config('home_blox_active') === '0',
    'preserved' => config('home_blox_data') === 'DRAFT' && config('home_layout_published') === 'PUBLICATION' && config('home_blox_history') === 'HISTORY'];
SiteSetup::changeHomeFlags($undo['flags'], $undo['fingerprint']);
$results['restored'] = $before === SiteSetup::homeState();
$stale = SiteSetup::homeFingerprint();
settingModel()->set('home_blox_data', 'NEW DRAFT');
try {
    SiteSetup::changeHomeFlags(['home_layout_active' => '0', 'home_blox_active' => '0'], $stale);
    $results['stale_blocked'] = false;
} catch (RuntimeException $e) {
    $results['stale_blocked'] = $e->getMessage() === 'setup_plan_stale' && config('home_layout_active') === '1';
}
$GLOBALS['setup_override'] = ['home_layout_active' => '1'];
try {
    SiteSetup::changeHomeFlags(['home_layout_active' => '0', 'home_blox_active' => '0'], SiteSetup::homeFingerprint());
    $results['override_blocked'] = false;
} catch (RuntimeException $e) {
    $results['override_blocked'] = $e->getMessage() === 'setup_home_locked' && settingModel()->get('home_blox_active') === '1';
}
echo json_encode($results, JSON_THROW_ON_ERROR);
