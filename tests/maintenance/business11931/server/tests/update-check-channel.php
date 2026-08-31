<?php

declare(strict_types=1);

require dirname(__DIR__) . '/api/update/_channel.php';

function expectSameValue(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

$stable = ['version' => '1.18.1', 'channel' => 'stable'];
$beta = ['version' => '1.18.2', 'channel' => 'beta'];
$catalog = ['latest' => '1.18.1', 'releases' => [$beta, $stable]];
$registry = ['schema' => 1, 'versions' => [
    '1.18.1' => ['channel' => 'stable'],
    '1.18.2' => ['channel' => 'beta'],
]];

$legacy = updateChannelResolveCatalog($catalog, $registry, null, '1.16.2');
expectSameValue('stable', $legacy['requested_channel'], 'old client defaults to stable');
expectSameValue('1.18.1', $legacy['latest'], 'old client receives stable');

$stableOnly = updateChannelResolveCatalog($catalog, $registry, 'stable', '1.18.0');
expectSameValue('1.18.1', $stableOnly['latest'], 'stable subscription excludes beta');

$betaOptIn = updateChannelResolveCatalog($catalog, $registry, 'beta', '1.18.0');
expectSameValue('1.18.2', $betaOptIn['latest'], 'beta subscription receives beta');

$invalidCatalog = $catalog;
array_unshift($invalidCatalog['releases'], ['version' => '1.18.3', 'channel' => 'beta']);
try {
    updateChannelResolveCatalog($invalidCatalog, $registry, 'beta', '1.18.0');
    throw new RuntimeException('unregistered release was accepted');
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(), '未登记')) throw $e;
}

$bridge = [
    'version' => '1.18.13', 'channel' => 'beta', 'renormalize' => true,
    'domains' => ['gray.example.com'],
];
$bridgeCatalog = ['latest' => '1.18.1', 'releases' => [$bridge, $stable]];
$bridgeRegistry = ['schema' => 1, 'versions' => [
    '1.18.1' => ['channel' => 'stable'],
    '1.18.13' => ['channel' => 'beta'],
]];
$gray = updateChannelResolveCatalog($bridgeCatalog, $bridgeRegistry, 'stable', '1.18.10', 'www.gray.example.com');
expectSameValue('1.18.13', $gray['latest'], 'legacy gray domain receives bridge');
$normalized = updateChannelResolveCatalog($bridgeCatalog, $bridgeRegistry, 'stable', '1.18.1', 'gray.example.com');
expectSameValue('1.18.1', $normalized['latest'], 'normalized site exits bridge');

fwrite(STDOUT, "UPDATE CHANNEL MATRIX OK (6 scenarios)\n");
