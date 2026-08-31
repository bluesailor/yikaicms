<?php
declare(strict_types=1);

/** Legacy 1.19.3 interactive check shape; this is routing, not authentication. */
function updateTargetIsManualCheck(array $query, string $method): bool
{
    $keys = ['version', 'channel', 'domain', 'site_name', 'php', 't'];
    if ($method !== 'GET' || array_diff(array_keys($query), $keys) !== []) return false;
    foreach ($keys as $key) {
        if (!array_key_exists($key, $query) || !is_string($query[$key])) return false;
    }
    return in_array($query['channel'], ['stable', 'beta'], true)
        && preg_match('/^\d+\.\d+\.\d+(?:\.\d+)?$/D', $query['version']) === 1
        && ctype_digit($query['t']) && $query['domain'] !== '';
}

/** Explicit hosts only. Never interpret a URL, subdomain or userinfo as the target. */
function updateTargetHost(string $host): string
{
    $host = strtolower(trim($host));
    if (preg_match('/^([a-z0-9.-]+?)(?::(?:80|443))?$/D', $host, $match) !== 1) return '';
    return rtrim($match[1], '.');
}

function updateTargetValidate(array $release): void
{
    if (!array_key_exists('targeting', $release)) return;
    $t = $release['targeting'];
    if (!is_array($t) || ($release['channel'] ?? '') !== 'beta'
        || ($t['manual_only'] ?? null) !== true
        || !is_string($t['from'] ?? null)
        || preg_match('/^\d+\.\d+\.\d+(?:\.\d+)?$/D', $t['from']) !== 1
        || !version_compare((string) ($release['version'] ?? ''), $t['from'], '>')
        || !is_string($t['expires_at'] ?? null)
        || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $t['expires_at']) !== 1
        || strtotime($t['expires_at']) === false
        || !is_array($t['domains'] ?? null) || $t['domains'] === []) {
        throw new RuntimeException('Invalid targeted release policy');
    }
    foreach ($t['domains'] as $host) {
        if (!is_string($host) || $host === '' || updateTargetHost($host) !== $host
            || strpos($host, '.') === false) {
            throw new RuntimeException('Invalid targeted release host');
        }
    }
}

function updateTargetEligible(array $release, string $version, string $host, bool $manualCheck): bool
{
    $t = $release['targeting'];
    return $manualCheck && $version === $t['from']
        && time() < strtotime($t['expires_at'])
        && in_array(updateTargetHost($host), $t['domains'], true);
}
