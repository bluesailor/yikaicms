<?php

declare(strict_types=1);

final class ReleaseUploadGuard
{
    private const MTIME_TOLERANCE = 2;
    private const MAX_BUILD_WINDOW = 14400;

    /**
     * @return array{packages:list<string>,data:list<string>,version:string,channel:string}
     */
    public static function inspect(
        string $updateRoot,
        string $releaseDir,
        string $version
    ): array {
        self::assertVersion($version);
        $updateRoot = self::directory($updateRoot, 'update root');
        $releaseDir = self::directory($releaseDir, 'release directory');

        $catalog = self::jsonFile($updateRoot . '/data/releases.json', 'release catalog');
        $registry = self::jsonFile($updateRoot . '/data/release-registry.json', 'release registry');
        $release = self::validateCatalog($catalog, $registry, $version);

        $fullPackage = self::safePackageName((string) ($release['package'] ?? ''));
        $expectedFull = 'yikaicms-v' . $version . '.zip';
        if ($fullPackage !== $expectedFull) {
            throw new RuntimeException("Release {$version} package must be {$expectedFull}");
        }

        $localFull = $releaseDir . '/' . $fullPackage;
        $serverFull = $updateRoot . '/packages/' . $fullPackage;
        self::assertArtifact($localFull, (string) ($release['hash'] ?? ''), 'local full package');
        self::assertArtifact($serverFull, (string) ($release['hash'] ?? ''), 'update full package');

        $metadataPath = $releaseDir . '/deltas-v' . $version . '.json';
        $catalogDeltas = is_array($release['deltas'] ?? null) ? $release['deltas'] : [];
        $metadata = is_file($metadataPath) ? self::deltaMetadata($metadataPath) : [];
        self::assertDeltaCatalogMatches($metadata, $catalogDeltas, $version);

        $fullMtime = self::mtime($localFull);
        $metadataMtime = null;
        if ($metadata !== []) {
            $metadataMtime = self::mtime($metadataPath);
            if ($metadataMtime + self::MTIME_TOLERANCE < $fullMtime
                || $metadataMtime - $fullMtime > self::MAX_BUILD_WINDOW) {
                throw new RuntimeException('Delta metadata is outside the current full-package build window');
            }
        }

        $packages = [$serverFull];
        $listed = [];
        foreach ($metadata as $delta) {
            $package = self::safePackageName((string) ($delta['package'] ?? ''));
            $from = (string) ($delta['from'] ?? '');
            $expectedName = 'delta-' . $from . '-to-' . $version . '.zip';
            if (preg_match('/^\d+\.\d+\.\d+(?:\.\d+)?$/', $from) !== 1 || $package !== $expectedName) {
                throw new RuntimeException("Invalid delta identity for target {$version}: {$package}");
            }
            $localDelta = $releaseDir . '/' . $package;
            $serverDelta = $updateRoot . '/packages/' . $package;
            self::assertArtifact($localDelta, (string) ($delta['hash'] ?? ''), "local delta {$from}");
            self::assertArtifact($serverDelta, (string) ($delta['hash'] ?? ''), "update delta {$from}");

            $deltaMtime = self::mtime($localDelta);
            if ($metadataMtime === null
                || $deltaMtime + self::MTIME_TOLERANCE < $fullMtime
                || $deltaMtime > $metadataMtime + self::MTIME_TOLERANCE) {
                throw new RuntimeException("Delta {$package} is not from the current build (mtime mismatch)");
            }
            $listed[$package] = true;
            $packages[] = $serverDelta;
        }

        $extras = [];
        foreach ([$releaseDir, $updateRoot . '/packages'] as $artifactDir) {
            $matches = glob($artifactDir . '/delta-*-to-' . $version . '.zip');
            foreach (is_array($matches) ? $matches : [] as $path) {
                $name = basename($path);
                if (!isset($listed[$name])) {
                    $extras[$name] = true;
                }
            }
        }
        if ($extras !== []) {
            $extras = array_keys($extras);
            sort($extras);
            throw new RuntimeException(
                'Unlisted delta artifacts target this version: ' . implode(', ', $extras)
                . '. Remove them; uploads must follow deltas-v' . $version . '.json only.'
            );
        }

        return [
            'packages' => $packages,
            // Registry first keeps the old catalog valid; releases.json is the final broadcast switch.
            'data' => [
                $updateRoot . '/data/release-registry.json',
                $updateRoot . '/data/releases.json',
            ],
            'version' => $version,
            'channel' => (string) $release['channel'],
        ];
    }

    public static function runPhpScript(string $script, array $arguments = []): string
    {
        if (!is_file($script)) {
            throw new RuntimeException("Required release check not found: {$script}");
        }
        $command = array_merge([PHP_BINARY, $script], array_values($arguments));
        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException("Cannot start release check: {$script}");
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) {
            $detail = trim((string) $stderr . "\n" . (string) $stdout);
            throw new RuntimeException("Release check failed ({$script}): {$detail}");
        }
        return trim((string) $stdout);
    }

    /** @return list<array<string,mixed>> */
    private static function deltaMetadata(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("Delta metadata not found: {$path}");
        }
        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            throw new RuntimeException("Delta metadata is empty: {$path}");
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        try {
            $decoded = json_decode('{' . trim($raw) . '}', true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Delta metadata is invalid JSON: ' . $e->getMessage());
        }
        if (!is_array($decoded) || !is_array($decoded['deltas'] ?? null)) {
            throw new RuntimeException('Delta metadata must contain a deltas array');
        }
        $deltas = [];
        foreach ($decoded['deltas'] as $delta) {
            if (!is_array($delta)) {
                throw new RuntimeException('Delta metadata contains a non-object entry');
            }
            $deltas[] = $delta;
        }
        if ($deltas === []) {
            throw new RuntimeException('Delta metadata contains no uploadable delta');
        }
        return $deltas;
    }

    /**
     * @param array<string,mixed> $catalog
     * @param array<string,mixed> $registry
     * @return array<string,mixed>
     */
    private static function validateCatalog(array $catalog, array $registry, string $version): array
    {
        $releases = $catalog['releases'] ?? null;
        $versions = $registry['versions'] ?? null;
        if (!is_array($releases) || !is_array($versions)) {
            throw new RuntimeException('Release catalog or registry structure is invalid');
        }

        $target = null;
        foreach ($releases as $release) {
            if (!is_array($release)) {
                throw new RuntimeException('Release catalog contains a non-object entry');
            }
            $releaseVersion = (string) ($release['version'] ?? '');
            $channel = (string) ($release['channel'] ?? '');
            if ($releaseVersion === '' || !in_array($channel, ['stable', 'beta'], true)) {
                throw new RuntimeException("Release {$releaseVersion} has a missing or invalid channel");
            }
            $registeredChannel = is_array($versions[$releaseVersion] ?? null)
                ? (string) ($versions[$releaseVersion]['channel'] ?? '')
                : '';
            if ($registeredChannel !== $channel) {
                throw new RuntimeException("Release {$releaseVersion} channel does not match the registry");
            }
            if ($releaseVersion === $version) {
                if ($target !== null) {
                    throw new RuntimeException("Release {$version} is duplicated in the catalog");
                }
                $target = $release;
            }
        }
        if (!is_array($target)) {
            throw new RuntimeException("Release {$version} is missing from releases.json");
        }
        if (($target['channel'] ?? '') === 'stable' && (string) ($catalog['latest'] ?? '') !== $version) {
            throw new RuntimeException("Stable release {$version} must be the catalog latest value");
        }
        return $target;
    }

    /**
     * @param list<array<string,mixed>> $metadata
     * @param array<mixed> $catalogDeltas
     */
    private static function assertDeltaCatalogMatches(array $metadata, array $catalogDeltas, string $version): void
    {
        $normalize = static function (array $items): array {
            $result = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new RuntimeException('Release delta catalog contains a non-object entry');
                }
                $package = (string) ($item['package'] ?? '');
                if ($package === '' || isset($result[$package])) {
                    throw new RuntimeException("Release delta catalog has an empty or duplicate package: {$package}");
                }
                $result[$package] = [
                    'from' => (string) ($item['from'] ?? ''),
                    'package' => $package,
                    'hash' => (string) ($item['hash'] ?? ''),
                    'size' => (string) ($item['size'] ?? ''),
                ];
            }
            ksort($result);
            return $result;
        };
        if ($normalize($metadata) !== $normalize($catalogDeltas)) {
            throw new RuntimeException(
                "Release {$version} deltas do not exactly match deltas-v{$version}.json"
            );
        }
    }

    private static function assertArtifact(string $path, string $expectedHash, string $label): void
    {
        if (!is_file($path)) {
            throw new RuntimeException("{$label} not found: {$path}");
        }
        if (preg_match('/^sha256:([a-f0-9]{64})$/', $expectedHash, $match) !== 1) {
            throw new RuntimeException("{$label} has an invalid catalog hash");
        }
        $actual = hash_file('sha256', $path);
        if (!is_string($actual) || !hash_equals($match[1], $actual)) {
            throw new RuntimeException("{$label} SHA256 mismatch: " . basename($path));
        }
    }

    /** @return array<string,mixed> */
    private static function jsonFile(string $path, string $label): array
    {
        if (!is_file($path)) {
            throw new RuntimeException("{$label} not found: {$path}");
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("{$label} is invalid JSON: " . $e->getMessage());
        }
        if (!is_array($decoded)) {
            throw new RuntimeException("{$label} must decode to an object");
        }
        return $decoded;
    }

    private static function safePackageName(string $name): string
    {
        if ($name === '' || basename($name) !== $name || preg_match('/^[a-zA-Z0-9._-]+\.zip$/', $name) !== 1) {
            throw new RuntimeException("Unsafe release package name: {$name}");
        }
        return $name;
    }

    private static function directory(string $path, string $label): string
    {
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw new RuntimeException("Invalid {$label}: {$path}");
        }
        return rtrim(str_replace('\\', '/', $real), '/');
    }

    private static function mtime(string $path): int
    {
        $mtime = filemtime($path);
        if (!is_int($mtime)) {
            throw new RuntimeException("Cannot read artifact mtime: {$path}");
        }
        return $mtime;
    }

    private static function assertVersion(string $version): void
    {
        if (preg_match('/^\d+\.\d+\.\d+(?:\.\d+)?$/', $version) !== 1) {
            throw new InvalidArgumentException("Invalid release version: {$version}");
        }
    }
}
