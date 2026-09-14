<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit;
define('ROOT_PATH', dirname(__DIR__, 2));
$version = $argv[1] ?? '';
if ($version !== '') define('CMS_VERSION', $version);
$hooks = [];
function add_action(string $name, callable $handler): void { $GLOBALS['hooks'][] = $name; }
function checkLogin(): void {}
function requirePermission(string $permission): void {}
function __(string $key): string { return $key; }
function error(string $message, int $code = 400): never { throw new RuntimeException($message, $code); }
require ROOT_PATH . '/plugins/dologin/main.php';
$supported = DoLoginCompatibility::currentSiteSupported();
$blocked = [];
if (!$supported) {
    foreach (['admin', 'login'] as $entry) {
        try {
            require ROOT_PATH . '/plugins/dologin/' . $entry . '.php';
        } catch (RuntimeException $error) {
            $blocked[$entry] = [$error->getCode(), $error->getMessage()];
        }
    }
}
echo json_encode(compact('supported', 'hooks', 'blocked'), JSON_THROW_ON_ERROR);
