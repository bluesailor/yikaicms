<?php
declare(strict_types=1);
require dirname(__DIR__) . '/api/update/_channel.php';
$count = 0;
function checkTarget(bool $pass, string $message): void {
    global $count;
    if (!$pass) throw new RuntimeException($message);
    $count++;
}
$target = ['version' => '1.19.3.1', 'channel' => 'beta', 'targeting' => [
    'domains' => ['target.example.com', 'www.target.example.com'], 'from' => '1.19.3',
    'manual_only' => true, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 86400),
]];
$stable = ['version' => '1.19.3', 'channel' => 'stable'];
$catalog = ['latest' => '1.19.3', 'releases' => [$target, $stable]];
$registry = ['schema' => 1, 'versions' => ['1.19.3' => ['channel' => 'stable'], '1.19.3.1' => ['channel' => 'beta']]];
foreach (['stable', 'beta'] as $channel) {
    foreach (['target.example.com', 'www.target.example.com', 'TARGET.example.com:80', 'target.example.com.'] as $host) {
        checkTarget(updateChannelResolveCatalog($catalog, $registry, $channel, '1.19.3', $host, true)['latest'] === '1.19.3.1', 'Target must receive patch');
        checkTarget(updateChannelResolveCatalog($catalog, $registry, $channel, '1.19.3', $host, false)['latest'] === '1.19.3', 'Automatic check must not receive patch');
    }
    foreach (['', 'other.example.com', 'sub.target.example.com', 'target.example.com.evil', 'target.example.com@evil', 'https://target.example.com', 'target.example.com:8080'] as $host) {
        checkTarget(updateChannelResolveCatalog($catalog, $registry, $channel, '1.19.3', $host, true)['latest'] === '1.19.3', 'Other hosts must not receive patch');
    }
    foreach (['1.19.2', '1.19.3.1', '1.19.4'] as $version) {
        checkTarget(updateChannelResolveCatalog($catalog, $registry, $channel, $version, 'target.example.com', true)['latest'] === '1.19.3', 'Exact base only; no repeat notification');
    }
}
$request = ['version' => '1.19.3', 'channel' => 'stable', 'domain' => 'target.example.com', 'site_name' => '', 'php' => '8.0.2', 't' => (string) time()];
checkTarget(updateTargetIsManualCheck($request, 'GET'), 'Known interactive request');
foreach (['auto', 'auto_scope', 'health_at', 'install_id', 'unexpected'] as $key) {
    checkTarget(!updateTargetIsManualCheck($request + [$key => '0'], 'GET'), 'Non-interactive request must fail closed');
}
checkTarget(!updateTargetIsManualCheck($request, 'POST'), 'POST heartbeat not interactive');
$health = $request; unset($health['channel']);
checkTarget(!updateTargetIsManualCheck($health, 'GET'), 'Legacy health check not interactive');
$invalid = $request; $invalid['domain'] = ['target.example.com'];
checkTarget(!updateTargetIsManualCheck($invalid, 'GET'), 'Array input rejected');
$custom = $request; $custom['version'] = '1.19.3-customer';
checkTarget(!updateTargetIsManualCheck($custom, 'GET'), 'Custom version is not the exact signed delta base');
$expired = $catalog; $expired['releases'][0]['targeting']['expires_at'] = '2020-01-01T00:00:00Z';
checkTarget(updateChannelResolveCatalog($expired, $registry, 'beta', '1.19.3', 'target.example.com', true)['latest'] === '1.19.3', 'Expired policy invisible');
$future = $catalog; $future['latest'] = '1.19.4'; $future['releases'][] = ['version' => '1.19.4', 'channel' => 'stable'];
$futureRegistry = $registry; $futureRegistry['versions']['1.19.4'] = ['channel' => 'stable'];
foreach (['1.19.3', '1.19.3.1'] as $version) {
    checkTarget(updateChannelResolveCatalog($future, $futureRegistry, 'stable', $version, 'target.example.com', true)['latest'] === '1.19.4', 'Next stable release wins');
}
foreach ([null, [], ['manual_only' => false], ['domains' => []], ['domains' => ['*.example.com']], ['from' => ''], ['expires_at' => 'never']] as $bad) {
    $broken = $catalog;
    $broken['releases'][0]['targeting'] = is_array($bad) && $bad !== [] ? array_replace($target['targeting'], $bad) : $bad;
    $rejected = false;
    try { updateChannelResolveCatalog($broken, $registry, 'beta', '1.19.3', 'target.example.com', true); }
    catch (RuntimeException $e) { $rejected = true; }
    checkTarget($rejected, 'Malformed policy must not fall through to ordinary beta');
}
echo "TARGETED UPDATE OK ($count assertions)\n";
