<?php
/**
 * Part of YikaiCMS.
 * Product ID: cn.yikai.yikaicms
 * License: see LICENSE.
 */

declare(strict_types=1);

final class YikaiProductIdentity
{
    public const VENDOR_ID = 'cn.yikai';
    public const PRODUCT_ID = 'cn.yikai.yikaicms';
    public const MANIFEST_SCHEMA = 1;

    /** @return array{vendor_id:string,product_id:string,product_name:string,vendor_name:string,copyright_holder:string,vendor_url:string,product_url:string,license_id:string} */
    public static function identity(?string $root = null): array
    {
        $config = self::productConfig($root);

        return [
            'vendor_id' => (string) ($config['vendor_id'] ?? self::VENDOR_ID),
            'product_id' => (string) ($config['product_id'] ?? self::PRODUCT_ID),
            'product_name' => (string) ($config['product_name'] ?? 'YikaiCMS'),
            'vendor_name' => (string) ($config['vendor_name'] ?? 'Yikai'),
            'copyright_holder' => (string) ($config['copyright_holder'] ?? 'Yikai'),
            'vendor_url' => (string) ($config['vendor_url'] ?? 'https://yikai.cn'),
            'product_url' => (string) ($config['product_url'] ?? 'https://www.yikaicms.com'),
            'license_id' => (string) ($config['license_id'] ?? 'YIKAI-CMS-LICENSE-1.0'),
        ];
    }

    /** @return list<string> */
    public static function fingerprintFiles(?string $root = null): array
    {
        $config = self::productConfig($root);
        $files = $config['fingerprint_files'] ?? [];
        if (!is_array($files)) {
            return [];
        }

        $normalized = [];
        foreach ($files as $file) {
            $path = str_replace('\\', '/', trim((string) $file));
            if ($path === '' || str_starts_with($path, '/') || str_contains($path, '../')) {
                continue;
            }
            $normalized[$path] = true;
        }
        $paths = array_keys($normalized);
        sort($paths, SORT_STRING);
        return $paths;
    }

    /**
     * @psalm-api
     * @return array{schema:int,vendor_id:string,product_id:string,product_name:string,copyright_holder:string,license_id:string,version:string,build_id:string,built_at:string,source_commit:string,source_dirty:bool,core_tree_sha256:string,core_files:array<string,string>}
     */
    public static function createBuildManifest(
        string $packageRoot,
        string $version,
        string $buildId,
        string $sourceCommit = '',
        bool $sourceDirty = false
    ): array {
        $packageRoot = rtrim($packageRoot, '/\\');
        if ($packageRoot === '' || !is_dir($packageRoot)) {
            throw new InvalidArgumentException('Package root does not exist.');
        }
        if (preg_match('/^\d+\.\d+\.\d+(?:\.\d+)?$/', $version) !== 1) {
            throw new InvalidArgumentException('Invalid product version.');
        }
        if ($buildId === '') {
            throw new InvalidArgumentException('Build ID is required.');
        }

        $identity = self::identity($packageRoot);
        self::assertCanonicalIdentity($identity);
        $hashes = self::hashCoreFiles($packageRoot);

        return [
            'schema' => self::MANIFEST_SCHEMA,
            'vendor_id' => self::VENDOR_ID,
            'product_id' => self::PRODUCT_ID,
            'product_name' => $identity['product_name'],
            'copyright_holder' => $identity['copyright_holder'],
            'license_id' => $identity['license_id'],
            'version' => $version,
            'build_id' => $buildId,
            'built_at' => gmdate('c'),
            'source_commit' => preg_match('/^[a-f0-9]{7,40}$/i', $sourceCommit) === 1 ? strtolower($sourceCommit) : '',
            'source_dirty' => $sourceDirty,
            'core_tree_sha256' => self::treeHash($hashes),
            'core_files' => $hashes,
        ];
    }

    /** @return array{product_id:string,vendor_id:string,build_id:string,version:string,built_at:string,source_commit:string,source_dirty:bool,core_tree_sha256:string,integrity:string,changed_files:list<string>} */
    public static function buildInfo(?string $root = null): array
    {
        $root ??= defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__);
        $root = rtrim($root, '/\\');
        $identity = self::identity($root);
        $version = self::versionForRoot($root);
        $buildId = self::legacyBuildId($root);
        $fallback = [
            'product_id' => $identity['product_id'],
            'vendor_id' => $identity['vendor_id'],
            'build_id' => $buildId !== '' ? $buildId : ($version !== '' ? $version . '-development' : 'development'),
            'version' => $version,
            'built_at' => '',
            'source_commit' => '',
            'source_dirty' => false,
            'core_tree_sha256' => '',
            'integrity' => 'development',
            'changed_files' => [],
        ];

        $manifestFile = $root . '/config/provenance.php';
        if (!is_file($manifestFile)) {
            return $fallback;
        }
        $manifest = require $manifestFile;
        if (!self::validManifest($manifest)) {
            $fallback['integrity'] = 'invalid';
            return $fallback;
        }

        /** @var array<string,mixed> $manifest */
        if (($version !== '' && !hash_equals($version, (string) $manifest['version']))
            || ($buildId !== '' && !hash_equals($buildId, (string) $manifest['build_id']))
        ) {
            $fallback['integrity'] = 'invalid';
            return $fallback;
        }
        $changed = self::changedFiles($root, $manifest['core_files']);
        $treeHash = self::treeHash($manifest['core_files']);
        if (!hash_equals((string) $manifest['core_tree_sha256'], $treeHash)) {
            $changed[] = 'config/provenance.php';
        }

        return [
            'product_id' => (string) $manifest['product_id'],
            'vendor_id' => (string) $manifest['vendor_id'],
            'build_id' => (string) $manifest['build_id'],
            'version' => (string) $manifest['version'],
            'built_at' => (string) $manifest['built_at'],
            'source_commit' => (string) $manifest['source_commit'],
            'source_dirty' => (bool) $manifest['source_dirty'],
            'core_tree_sha256' => (string) $manifest['core_tree_sha256'],
            'integrity' => $changed === []
                ? ((bool) $manifest['source_dirty'] ? 'uncommitted' : 'verified')
                : 'modified',
            'changed_files' => array_values(array_unique($changed)),
        ];
    }

    /** @param array<string,mixed> $identity */
    private static function assertCanonicalIdentity(array $identity): void
    {
        if (($identity['vendor_id'] ?? '') !== self::VENDOR_ID || ($identity['product_id'] ?? '') !== self::PRODUCT_ID) {
            throw new RuntimeException('Product identity does not match the YikaiCMS canonical identity.');
        }
    }

    /** @return array<string,mixed> */
    private static function productConfig(?string $root): array
    {
        $root ??= defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__);
        $file = rtrim($root, '/\\') . '/config/product.php';
        if (!is_file($file)) {
            return [];
        }
        $config = require $file;
        return is_array($config) ? $config : [];
    }

    /** @return array<string,string> */
    private static function hashCoreFiles(string $root): array
    {
        $hashes = [];
        foreach (self::fingerprintFiles($root) as $path) {
            $file = $root . '/' . $path;
            if (!is_file($file)) {
                throw new RuntimeException('Missing fingerprint file: ' . $path);
            }
            $hash = hash_file('sha256', $file);
            if (!is_string($hash)) {
                throw new RuntimeException('Unable to hash fingerprint file: ' . $path);
            }
            $hashes[$path] = $hash;
        }
        if ($hashes === []) {
            throw new RuntimeException('No fingerprint files are configured.');
        }
        return $hashes;
    }

    /** @param array<string,string> $hashes */
    private static function treeHash(array $hashes): string
    {
        ksort($hashes, SORT_STRING);
        $lines = [];
        foreach ($hashes as $path => $hash) {
            $lines[] = $path . "\0" . strtolower($hash);
        }
        return hash('sha256', implode("\n", $lines));
    }

    private static function legacyBuildId(string $root): string
    {
        $file = $root . '/config/build.php';
        if (!is_file($file)) {
            return '';
        }
        $buildId = require $file;
        return is_string($buildId) ? trim($buildId) : '';
    }

    private static function versionForRoot(string $root): string
    {
        $file = $root . '/config/version.php';
        if (is_file($file)) {
            $source = file_get_contents($file);
            if (is_string($source)
                && preg_match("/CMS_VERSION'\s*,\s*'([^']+)'/", $source, $match) === 1
            ) {
                return (string) $match[1];
            }
        }
        $runtimeRoot = defined('ROOT_PATH') ? rtrim((string) ROOT_PATH, '/\\') : '';
        return $runtimeRoot !== '' && $runtimeRoot === $root && defined('CMS_VERSION')
            ? (string) CMS_VERSION
            : '';
    }

    private static function validManifest(mixed $manifest): bool
    {
        if (!is_array($manifest)
            || ($manifest['schema'] ?? null) !== self::MANIFEST_SCHEMA
            || ($manifest['vendor_id'] ?? '') !== self::VENDOR_ID
            || ($manifest['product_id'] ?? '') !== self::PRODUCT_ID
            || !is_array($manifest['core_files'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', (string) ($manifest['core_tree_sha256'] ?? '')) !== 1
        ) {
            return false;
        }
        foreach (['version', 'build_id', 'built_at', 'source_commit'] as $key) {
            if (!array_key_exists($key, $manifest) || !is_string($manifest[$key])) {
                return false;
            }
        }
        foreach ($manifest['core_files'] as $path => $hash) {
            if (!is_string($path)
                || !is_string($hash)
                || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1
                || $path === ''
                || str_starts_with($path, '/')
                || str_contains(str_replace('\\', '/', $path), '../')
            ) {
                return false;
            }
        }
        return array_key_exists('source_dirty', $manifest) && is_bool($manifest['source_dirty']);
    }

    /** @param array<string,mixed> $expected @return list<string> */
    private static function changedFiles(string $root, array $expected): array
    {
        $changed = [];
        foreach ($expected as $path => $hash) {
            if (!is_string($path) || !is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                $changed[] = 'config/provenance.php';
                continue;
            }
            $file = $root . '/' . $path;
            $actual = is_file($file) ? hash_file('sha256', $file) : false;
            if (!is_string($actual) || !hash_equals($hash, $actual)) {
                $changed[] = $path;
            }
        }
        return $changed;
    }
}

/** @return array{vendor_id:string,product_id:string,product_name:string,vendor_name:string,copyright_holder:string,vendor_url:string,product_url:string,license_id:string} */
function yikaiCmsIdentity(): array
{
    return YikaiProductIdentity::identity();
}
