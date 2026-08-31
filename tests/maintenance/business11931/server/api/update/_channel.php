<?php

declare(strict_types=1);

require_once __DIR__ . '/_targeting.php';

function updateChannelNormalize(mixed $channel): string
{
    return trim((string) $channel) === 'beta' ? 'beta' : 'stable';
}

function updateChannelNormalizeDomain(mixed $domain): string
{
    $value = strtolower(trim((string) $domain));
    $value = (string) preg_replace('#^https?://#', '', $value);
    $value = explode('/', $value, 2)[0];
    $value = explode(':', $value, 2)[0];
    return (string) preg_replace('/^www\./', '', $value);
}

/** @return array<string,array<string,mixed>> */
function updateChannelRegistryIndex(array $registry): array
{
    if (($registry['schema'] ?? null) !== 1 || !is_array($registry['versions'] ?? null)) {
        throw new RuntimeException('版本登记表格式错误');
    }

    $index = [];
    foreach ($registry['versions'] as $version => $entry) {
        if (!is_string($version) || preg_match('/^\d+\.\d+(\.\d+){0,2}$/', $version) !== 1 || !is_array($entry)) {
            throw new RuntimeException('版本登记表包含无效条目');
        }
        $channel = (string) ($entry['channel'] ?? '');
        if (!in_array($channel, ['stable', 'beta'], true)) {
            throw new RuntimeException('版本 ' . $version . ' 的登记通道无效');
        }
        $index[$version] = $entry;
    }
    return $index;
}

/** @return array<string,array<string,mixed>> keyed by version */
function updateChannelValidateCatalog(array $catalog, array $registry): array
{
    $stableLatest = trim((string) ($catalog['latest'] ?? ''));
    $releases = $catalog['releases'] ?? null;
    if ($stableLatest === '' || !is_array($releases)) {
        throw new RuntimeException('版本数据格式错误');
    }

    $registryIndex = updateChannelRegistryIndex($registry);
    $releaseIndex = [];
    foreach ($releases as $release) {
        if (!is_array($release)) {
            throw new RuntimeException('版本列表包含无效条目');
        }
        $version = trim((string) ($release['version'] ?? ''));
        $channel = trim((string) ($release['channel'] ?? ''));
        if ($version === '' || preg_match('/^\d+\.\d+(\.\d+){0,2}$/', $version) !== 1) {
            throw new RuntimeException('版本列表包含无效版本号');
        }
        if (isset($releaseIndex[$version])) {
            throw new RuntimeException('版本号重复：' . $version);
        }
        if (!isset($registryIndex[$version])) {
            throw new RuntimeException('版本号未登记，拒绝发布：' . $version);
        }
        if ($channel === '' || $channel !== (string) ($registryIndex[$version]['channel'] ?? '')) {
            throw new RuntimeException('版本通道与登记表不一致：' . $version);
        }
        updateTargetValidate($release);
        $releaseIndex[$version] = $release;
    }

    if (!isset($releaseIndex[$stableLatest]) || ($releaseIndex[$stableLatest]['channel'] ?? '') !== 'stable') {
        throw new RuntimeException('latest 必须指向已登记的 stable 版本');
    }
    return $releaseIndex;
}

/**
 * @return array{latest:string,releases:array<int,array<string,mixed>>,requested_channel:string,release_channel:string}
 */
function updateChannelResolveCatalog(
    array $catalog,
    array $registry,
    mixed $requestedChannel,
    string $coreVersion,
    mixed $domain = '',
    bool $manualCheck = false
): array {
    updateChannelValidateCatalog($catalog, $registry);

    $requested = updateChannelNormalize($requestedChannel);
    $requestDomain = updateChannelNormalizeDomain($domain);
    $stableLatest = (string) $catalog['latest'];
    $eligible = [];

    foreach ($catalog['releases'] as $release) {
        if (array_key_exists('targeting', $release)) {
            if (updateTargetEligible($release, $coreVersion, (string) $domain, $manualCheck)) {
                $eligible[] = $release;
            }
            continue;
        }
        $channel = (string) $release['channel'];
        if ($channel === 'stable') {
            $eligible[] = $release;
            continue;
        }

        $domains = array_values(array_filter(array_map('updateChannelNormalizeDomain', (array) ($release['domains'] ?? []))));
        $domainAllowed = $requestDomain !== '' && in_array($requestDomain, $domains, true);
        if ($requested !== 'beta' && !$domainAllowed) {
            continue;
        }

        $bridgeVersion = trim((string) ($release['bridge_version'] ?? ''));
        if ($bridgeVersion !== '' && version_compare($coreVersion, $bridgeVersion, '<')) {
            continue;
        }

        if (!empty($release['renormalize']) && !version_compare($coreVersion, $stableLatest, '>')) {
            continue;
        }
        $eligible[] = $release;
    }

    usort($eligible, static fn (array $a, array $b): int => version_compare((string) $b['version'], (string) $a['version']));
    if ($eligible === []) {
        throw new RuntimeException('当前通道没有可用版本');
    }

    return [
        'latest' => (string) $eligible[0]['version'],
        'releases' => $eligible,
        'requested_channel' => $requested,
        'release_channel' => (string) $eligible[0]['channel'],
    ];
}
