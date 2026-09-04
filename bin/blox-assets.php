<?php

declare(strict_types=1);

const BLOX_ASSET_SCOPES = ['core', 'pro', 'runtime', 'private'];

/** @return array{version:int,core:list<string>,pro:list<string>,runtime:list<string>,private:list<string>} */
function loadBloxAssetPolicy(string $root): array
{
    $path = $root . '/config/blox-assets.json';
    if (!is_file($path)) {
        throw new RuntimeException('Blox asset policy not found: ' . $path);
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('Unable to read Blox asset policy: ' . $path);
    }

    $policy = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($policy) || ($policy['version'] ?? null) !== 2) {
        throw new RuntimeException('Unsupported Blox asset policy version.');
    }

    foreach (BLOX_ASSET_SCOPES as $scope) {
        if (!isset($policy[$scope]) || !is_array($policy[$scope])) {
            throw new RuntimeException('Missing Blox asset policy scope: ' . $scope);
        }
        foreach ($policy[$scope] as $pathValue) {
            if (!is_string($pathValue) || $pathValue === '' || str_contains($pathValue, '\\')
                || str_starts_with($pathValue, '/') || preg_match('#(^|/)\.\.(/|$)#', $pathValue) === 1) {
                throw new RuntimeException('Invalid Blox asset path in ' . $scope . '.');
            }
        }
        if (count($policy[$scope]) !== count(array_unique($policy[$scope]))) {
            throw new RuntimeException('Duplicate path in Blox asset policy scope: ' . $scope);
        }
    }

    $distributionScopes = ['core', 'pro', 'runtime'];
    foreach ($distributionScopes as $index => $scope) {
        foreach (array_slice($distributionScopes, $index + 1) as $otherScope) {
            $overlap = array_intersect($policy[$scope], $policy[$otherScope]);
            if ($overlap !== []) {
                throw new RuntimeException(
                    "Assets cannot be both {$scope} and {$otherScope}: " . implode(', ', $overlap)
                );
            }
        }
    }

    /** @var array{version:int,core:list<string>,pro:list<string>,runtime:list<string>,private:list<string>} $policy */
    return $policy;
}

/** @param list<string> $paths */
function assertPaths(string $root, array $paths, bool $mustExist): void
{
    $failures = [];
    foreach ($paths as $path) {
        $exists = file_exists($root . '/' . $path);
        if ($exists !== $mustExist) {
            $failures[] = $path;
        }
    }
    if ($failures !== []) {
        $expectation = $mustExist ? 'missing' : 'must be absent';
        throw new RuntimeException($expectation . ': ' . implode(', ', $failures));
    }
}

/** @param array{core:list<string>,pro:list<string>,runtime:list<string>} $policy */
function verifyBloxJavascriptClassification(string $root, array $policy): void
{
    $files = glob($root . '/assets/js/blox-*.js');
    if ($files === false) {
        throw new RuntimeException('Unable to enumerate Blox JavaScript assets.');
    }

    $declared = array_flip(array_merge($policy['core'], $policy['pro'], $policy['runtime']));
    $unclassified = [];
    foreach ($files as $file) {
        $relative = 'assets/js/' . basename($file);
        if (!isset($declared[$relative])) {
            $unclassified[] = $relative;
        }
    }
    if ($unclassified !== []) {
        throw new RuntimeException('Unclassified Blox JavaScript: ' . implode(', ', $unclassified));
    }
}

/** @return never */
function usage(): void
{
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php bin/blox-assets.php list <core|pro|runtime|private>\n");
    fwrite(STDERR, "  php bin/blox-assets.php verify-policy [root]\n");
    fwrite(STDERR, "  php bin/blox-assets.php verify-source [root]\n");
    fwrite(STDERR, "  php bin/blox-assets.php verify-free <package-root>\n");
    exit(2);
}

$projectRoot = dirname(__DIR__);
$command = $argv[1] ?? '';

try {
    if ($command === 'list') {
        $scope = $argv[2] ?? '';
        if (!in_array($scope, BLOX_ASSET_SCOPES, true)) {
            usage();
        }
        $policy = loadBloxAssetPolicy($projectRoot);
        echo implode(PHP_EOL, $policy[$scope]) . PHP_EOL;
        exit(0);
    }

    $root = isset($argv[2]) ? rtrim(str_replace('\\', '/', $argv[2]), '/') : $projectRoot;
    $policy = loadBloxAssetPolicy($projectRoot);

    if ($command === 'verify-policy') {
        assertPaths($root, $policy['runtime'], true);
        verifyBloxJavascriptClassification($root, $policy);
    } elseif ($command === 'verify-source') {
        assertPaths(
            $root,
            array_values(array_unique(array_merge(
                $policy['core'],
                $policy['pro'],
                $policy['runtime'],
                $policy['private']
            ))),
            true
        );
        verifyBloxJavascriptClassification($root, $policy);
    } elseif ($command === 'verify-free') {
        assertPaths($root, $policy['core'], true);
        assertPaths($root, $policy['pro'], false);
        assertPaths($root, $policy['runtime'], true);
    } else {
        usage();
    }

    echo "Blox asset policy OK ({$command})." . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Blox asset policy failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
