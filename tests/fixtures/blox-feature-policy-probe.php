<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit;
define('ROOT_PATH', dirname(__DIR__, 2));
define('CMS_VERSION', ($argv[1] ?? '') === 'old_cms' ? '1.19.9' : '1.20.0');
function bloxAdvancedFeaturesEnabled(): bool { return true; }
function isPluginAvailable(string $slug): bool {
    return $slug === 'blox-pro' && ($GLOBALS['argv'][1] ?? '') !== 'disabled_plugin';
}
function license_has_module(string $module): bool {
    $GLOBALS['license_calls'][] = $module;
    return in_array($GLOBALS['argv'][1] ?? '', ['licensed', 'service_expired'], true);
}
$root = sys_get_temp_dir() . '/yk-feature-policy-' . bin2hex(random_bytes(8));
$GLOBALS['license_calls'] = [];
if (($argv[1] ?? '') !== 'missing_plugin') require ROOT_PATH . '/plugins/blox-pro/main.php';
mkdir($root . '/includes/builder', 0700, true);
mkdir($root . '/config', 0700);
try {
    copy(dirname(__DIR__, 2) . '/includes/builder/BloxFeaturePolicy.php', $root . '/includes/builder/BloxFeaturePolicy.php');
    file_put_contents($root . '/config/blox-feature-policy.php', '<?php return ' . var_export([
        'query_loop' => 'licensed', 'display_conditions' => 'disabled', 'style_presets' => 'free',
    ], true) . ';');
    require $root . '/includes/builder/BloxFeaturePolicy.php';
    $free = BloxFeaturePolicy::allows('style_presets');
    $freeCalls = count($GLOBALS['license_calls']);
    $query = BloxFeaturePolicy::allows('query_loop');
    $conditions = BloxFeaturePolicy::allows('display_conditions');
    $unknown = BloxFeaturePolicy::allows('unknown');
    echo json_encode(compact('free', 'freeCalls', 'query', 'conditions', 'unknown') + ['calls' => $GLOBALS['license_calls']], JSON_THROW_ON_ERROR);
} finally {
    unlink($root . '/includes/builder/BloxFeaturePolicy.php');
    unlink($root . '/config/blox-feature-policy.php');
    rmdir($root . '/includes/builder');
    rmdir($root . '/includes');
    rmdir($root . '/config');
    rmdir($root);
}
