<?php
/** Local, pinned mainland-China address data shared by checkout and order validation. */
declare(strict_types=1);

function shopMainlandRegionVersion(): string
{
    return '2026.0.1';
}

/**
 * Normalize upstream numeric province IDs to strings; never interpret codes as numbers.
 * Reject a broken bundle instead of falling back to unchecked free-text addresses.
 * @return list<array{c:string,n:string,ch?:array}>|null
 */
function shopNormalizeRegionNodes(mixed $nodes, int $depth): ?array
{
    if (!is_array($nodes) || $nodes === [] || $depth < 0 || $depth > 2
        || count($nodes) > 5000 || array_keys($nodes) !== range(0, count($nodes) - 1)) {
        return null;
    }
    $result = [];
    $seenCodes = [];
    $seenNames = [];
    foreach ($nodes as $node) {
        if (!is_array($node) || (!is_string($node['c'] ?? null) && !is_int($node['c'] ?? null))
            || !is_string($node['n'] ?? null)) {
            return null;
        }
        $code = (string) $node['c'];
        $name = $node['n'];
        $pattern = $depth === 0 ? '/^\d{2}$/' : ($depth === 1 ? '/^(?:\d{4}|\d{6})$/' : '/^(?:\d{6}|\d{12})$/');
        if (preg_match($pattern, $code) !== 1 || $name === '' || mb_strlen($name) > 50
            || trim($name) !== $name || preg_match('/[\x00-\x1F\x7F]/u', $name) !== 0
            || isset($seenCodes[$code]) || isset($seenNames[$name])) {
            return null;
        }
        $seenCodes[$code] = true;
        $seenNames[$name] = true;
        $clean = ['c' => $code, 'n' => $name];
        if ($depth < 2) {
            $children = shopNormalizeRegionNodes($node['ch'] ?? null, $depth + 1);
            if ($children === null) {
                return null;
            }
            $clean['ch'] = $children;
        }
        $result[] = $clean;
    }
    return $result;
}

/** @return list<array{c:string,n:string,ch:array}> */
function shopMainlandRegionTree(): array
{
    static $tree = null;
    if ($tree !== null) {
        return $tree;
    }
    $raw = @file_get_contents(__DIR__ . '/../data/cn-division/pca.json');
    // Pin the reviewed bytes too: valid JSON with changed parents/names must not weaken shipping rules.
    if (!is_string($raw) || !hash_equals('153f67889222d83323c63220bd6e3625221aac2dfd7f3ebc731f7156ac8ee94b', hash('sha256', $raw))) {
        error_log('[shop] Mainland region bundle checksum mismatch');
        $tree = [];
        return $tree;
    }
    $data = json_decode($raw, true);
    $normalized = shopNormalizeRegionNodes($data, 0);
    $expected = ['11', '12', '13', '14', '15', '21', '22', '23', '31', '32', '33', '34', '35', '36', '37', '41', '42', '43', '44', '45', '46', '50', '51', '52', '53', '54', '61', '62', '63', '64', '65'];
    $codes = array_column($normalized ?? [], 'c');
    sort($codes, SORT_STRING);
    if ($normalized === null || $codes !== $expected) {
        error_log('[shop] Mainland region bundle unavailable or invalid');
        $tree = [];
        return $tree;
    }
    // Depth-zero normalization has already required children for every province.
    /** @var list<array{c:string,n:string,ch:array}> $normalized */
    $tree = $normalized;
    return $tree;
}

/**
 * Resolve names through their actual parents, not a flat name/code lookup.
 * Direct-admin cities may share a code with their child: keep the full path.
 * @return array{province_code:string,city_code:string,district_code:string}|null
 */
function shopMainlandRegionPath(string $province, string $city, string $district): ?array
{
    foreach (shopMainlandRegionTree() as $provinceNode) {
        if ($provinceNode['n'] !== $province) {
            continue;
        }
        foreach ($provinceNode['ch'] as $cityNode) {
            if ($cityNode['n'] !== $city) {
                continue;
            }
            foreach ($cityNode['ch'] as $districtNode) {
                if ($districtNode['n'] === $district) {
                    return [
                        'province_code' => $provinceNode['c'],
                        'city_code' => $cityNode['c'],
                        'district_code' => $districtNode['c'],
                    ];
                }
            }
            return null;
        }
        return null;
    }
    return null;
}
