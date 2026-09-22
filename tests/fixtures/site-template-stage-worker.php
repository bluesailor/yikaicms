<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli' || count($argv) !== 6) exit(64);
define('ROOT_PATH', dirname(__DIR__, 2));
define('DB_DRIVER', $argv[2]);
if (DB_DRIVER === 'sqlite') {
    define('DB_PATH', $argv[3]);
} else {
    define('DB_HOST', getenv('SITE_TEMPLATE_DB_HOST') ?: '127.0.0.1');
    define('DB_PORT', getenv('SITE_TEMPLATE_DB_PORT') ?: '3306');
    define('DB_USER', getenv('SITE_TEMPLATE_DB_USER') ?: 'root');
    define('DB_PASS', getenv('SITE_TEMPLATE_DB_PASS') ?: '');
    define('DB_NAME', $argv[3]);
}
define('DB_PREFIX', 'yikai_');
define('DB_CHARSET', 'utf8mb4');
define('DEBUG', true);
function config(string $key, mixed $default = ''): mixed { return settingModel()->get($key, $default); }
function __(string $key, array $params = []): string { return $key; }
require ROOT_PATH . '/config/version.php';
require ROOT_PATH . '/config/database.php';
require ROOT_PATH . '/includes/models/autoload.php';
require ROOT_PATH . '/includes/SiteTemplateService.php';

$service = new SiteTemplateService($argv[1]);
echo json_encode($service->stage($argv[4], (int) $argv[5]), JSON_THROW_ON_ERROR) . "\n";
