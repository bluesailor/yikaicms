<?php
/**
 * Smoke test for Compatibility. CLI:
 *   php tools/test_compatibility.php
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/includes/hooks.php';
require_once ROOT_PATH . '/includes/Compatibility.php';

echo "=== Test 1: HTTPS detection via X-Forwarded-Proto ===\n";
$_SERVER = ['HTTP_X_FORWARDED_PROTO' => 'https', 'REMOTE_ADDR' => '10.0.0.1'];
Compatibility::bootstrap();
echo "After bootstrap: HTTPS=" . ($_SERVER['HTTPS'] ?? '(none)') . "\n";
echo "Diagnostics: " . json_encode(Compatibility::diagnostics()) . "\n\n";

// Reset (Compatibility is singleton — for a real test runner I'd refactor; this is just smoke)
echo "=== Test 2: Cloudflare client IP ===\n";
$cls = new \ReflectionClass('Compatibility');
$prop = $cls->getProperty('bootstrapped');
$prop->setAccessible(true);
$prop->setValue(false);
$diagProp = $cls->getProperty('diagnostics');
$diagProp->setAccessible(true);
$diagProp->setValue([]);

$_SERVER = [
    'HTTP_CF_CONNECTING_IP' => '203.0.113.42',
    'REMOTE_ADDR'           => '10.0.0.1',
];
Compatibility::bootstrap();
echo "Compatibility::clientIp() = " . Compatibility::clientIp() . "\n";
echo "Diagnostics: " . json_encode(Compatibility::diagnostics()) . "\n\n";

echo "=== Test 3: flushBeforeJson ===\n";
$prop->setValue(false);
$diagProp->setValue([]);

ob_start();
echo "BOM and warning noise here";
Compatibility::flushBeforeJson();
echo "After flushBeforeJson, ob_level=" . ob_get_level() . "\n";
echo "Diagnostics: " . json_encode(Compatibility::diagnostics()) . "\n\n";

echo "=== Test 4: blockWriteIfDemo ===\n";
$prop->setValue(false);
$diagProp->setValue([]);
// not in demo mode → no-op
$_SERVER['REQUEST_METHOD'] = 'POST';
Compatibility::blockWriteIfDemo();
echo "Not demo mode → POST went through OK\n";
echo "isDemoMode() = " . var_export(Compatibility::isDemoMode(), true) . "\n";
