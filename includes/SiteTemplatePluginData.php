<?php
declare(strict_types=1);

/**
 * Generic coordinator for plugin-owned site-template data.
 *
 * The core never names plugin tables, settings or fields. An active plugin must
 * explicitly opt in through site_template_plugin_export; the untouched default
 * [] means "export nothing". Only the portable schema + payload are packaged.
 * The local state digest is kept in the protected import journal and never
 * leaves the target site.
 */
final class SiteTemplatePluginData
{
    public const FILE_FORMAT = 'yikaicms-site-template-plugin-data';
    public const CONTRACT_VERSION = 1;
    public const MAX_FILE_BYTES = 4194304;
    public const MAX_TOTAL_BYTES = 8388608;
    private const MAX_DEPTH = 16;
    private const MAX_NODES = 200000;

    /**
     * @param list<array{slug:string,version:string}> $plugins
     * @return array<string,array{contract:int,schema:array{id:string,version:int,sha256:string},payload:array,state:array{sha256:string,replaceable:bool}}>
     */
    public static function snapshot(array $plugins): array
    {
        if ($plugins === [] || !function_exists('apply_filters')) return [];
        $snapshot = [];
        foreach ($plugins as $plugin) {
            $slug = (string) ($plugin['slug'] ?? '');
            if (!self::slugValid($slug)) throw new RuntimeException('st_plugin_manifest');
            $entry = apply_filters('site_template_plugin_export', [], $slug);
            if ($entry === []) continue;
            if (!is_array($entry)) throw new RuntimeException('st_plugin_adapter');
            $snapshot[$slug] = self::normalizeLocal($entry);
        }
        ksort($snapshot);
        return $snapshot;
    }

    /**
     * @param array<string,array{contract:int,schema:array{id:string,version:int,sha256:string},payload:array,state:array{sha256:string,replaceable:bool}}> $snapshot
     * @return array{manifest:array<string,string>,files:array<string,string>}
     */
    public static function package(array $snapshot): array
    {
        // Keep the exporter inside the same aggregate node budget enforced by
        // decode(); otherwise several individually valid adapters could create
        // a package that the importer immediately rejects.
        $nodes = 0;
        self::normalizeJson($snapshot, 0, $nodes);
        $manifest = [];
        $files = [];
        $total = 0;
        foreach ($snapshot as $slug => $entry) {
            if (!self::slugValid($slug)) throw new RuntimeException('st_plugin_adapter');
            $entry = self::normalizeLocal($entry);
            $path = 'plugin-data/' . $slug . '.json';
            $bytes = self::encodePackage($slug, $entry);
            $total += strlen($bytes);
            if (strlen($bytes) > self::MAX_FILE_BYTES || $total > self::MAX_TOTAL_BYTES) throw new RuntimeException('st_limit');
            $manifest[$slug] = $path;
            $files[$path] = $bytes;
        }
        return ['manifest' => $manifest, 'files' => $files];
    }

    /**
     * Decode already-read plugin JSON files. Hashes are checked here as well so
     * inspect() can compare target schemas before it copies or stages anything.
     *
     * @param mixed $declarations manifest.plugin_data
     * @param list<array{slug:string,version:string}> $plugins
     * @param array<string,string> $files
     * @param array<string,string> $hashes
     * @return array<string,array{contract:int,schema:array{id:string,version:int,sha256:string},payload:array}>
     */
    public static function decode(mixed $declarations, array $plugins, array $files, array $hashes): array
    {
        if (!is_array($declarations) || count($declarations) > 100) throw new RuntimeException('st_invalid');
        $required = [];
        foreach ($plugins as $plugin) $required[(string) ($plugin['slug'] ?? '')] = true;
        $decoded = [];
        $paths = [];
        $total = 0;
        $nodes = 0;
        foreach ($declarations as $slug => $path) {
            if (!is_string($slug) || !self::slugValid($slug) || !isset($required[$slug]) || !is_string($path)
                || $path !== 'plugin-data/' . $slug . '.json' || isset($paths[$path])) throw new RuntimeException('st_invalid');
            $paths[$path] = true;
            $bytes = $files[$path] ?? null;
            $digest = $hashes[$path] ?? null;
            if (!is_string($bytes) || strlen($bytes) > self::MAX_FILE_BYTES || !is_string($digest)
                || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1 || !hash_equals($digest, hash('sha256', $bytes))) {
                throw new RuntimeException('st_invalid');
            }
            $total += strlen($bytes);
            if ($total > self::MAX_TOTAL_BYTES) throw new RuntimeException('st_limit');
            try {
                $entry = json_decode($bytes, true, self::MAX_DEPTH, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new RuntimeException('st_invalid', 0, $error);
            }
            if (!is_array($entry) || array_keys($entry) !== ['format', 'version', 'slug', 'schema', 'payload']
                || ($entry['format'] ?? null) !== self::FILE_FORMAT || ($entry['version'] ?? null) !== self::CONTRACT_VERSION
                || ($entry['slug'] ?? null) !== $slug || !is_array($entry['schema'] ?? null) || !is_array($entry['payload'] ?? null)) {
                throw new RuntimeException('st_invalid');
            }
            try {
                $decoded[$slug] = [
                    'contract' => self::CONTRACT_VERSION,
                    'schema' => self::normalizeSchema($entry['schema']),
                    'payload' => self::normalizeJson($entry['payload'], 0, $nodes),
                ];
            } catch (RuntimeException $error) {
                if ($error->getMessage() === 'st_limit') throw $error;
                throw new RuntimeException('st_invalid', 0, $error);
            }
        }
        ksort($decoded);
        return $decoded;
    }

    /** @param array<string,mixed> $manifest @param list<string> $names @param array<string,string> $small */
    public static function validateArchive(array $manifest, array $names, array $small): void
    {
        $declarations = $manifest['plugin_data'] ?? [];
        $declaredPaths = is_array($declarations) ? array_values($declarations) : [];
        $actualPaths = array_values(array_filter($names, static fn(string $name): bool => str_starts_with($name, 'plugin-data/')));
        sort($declaredPaths);
        sort($actualPaths);
        if ($declaredPaths !== $actualPaths) throw new RuntimeException('st_invalid');
        self::decode($declarations, $manifest['plugins'] ?? [], $small, is_array($manifest['files'] ?? null) ? $manifest['files'] : []);
    }

    /**
     * Compare source contracts with the currently active target adapters.
     * Missing dependencies are omitted by the caller and may be skipped only
     * after the existing trusted + confirm flow. A present but incompatible
     * adapter is never bypassed.
     *
     * @param array<string,array{contract:int,schema:array{id:string,version:int,sha256:string},payload:array}> $source
     * @param list<array{slug:string,version:string}> $available
     * @return array<string,array{contract:int,schema:array{id:string,version:int,sha256:string},payload:array,state:array{sha256:string,replaceable:bool}}>
     */
    public static function targetSnapshot(array $source, array $available): array
    {
        $wanted = array_intersect_key($source, array_fill_keys(array_column($available, 'slug'), true));
        if ($wanted === []) return [];
        $local = self::snapshot($available);
        $target = [];
        foreach ($wanted as $slug => $entry) {
            if (!isset($local[$slug])) throw new RuntimeException('st_plugin_adapter');
            if ($local[$slug]['schema'] !== $entry['schema']) throw new RuntimeException('st_schema');
            if (!$local[$slug]['state']['replaceable']) throw new RuntimeException('st_not_fresh');
            $target[$slug] = $local[$slug];
        }
        return $target;
    }

    /**
     * Dispatch imports in stable order and prove that every action produced the
     * exact public state requested by the package. A missing/no-op handler thus
     * fails inside the caller-owned transaction instead of silently dropping data.
     *
     * @param array<string,array{contract:int,schema:array{id:string,version:int,sha256:string},payload:array}> $source
     * @param list<array{slug:string,version:string}> $available
     * @return array<string,array{contract:int,schema:array{id:string,version:int,sha256:string},payload:array,state:array{sha256:string,replaceable:bool}}>
     */
    public static function apply(array $source, array $available): array
    {
        if ($source === []) return [];
        if (!function_exists('do_action')) throw new RuntimeException('st_plugin_adapter');
        ksort($source);
        foreach ($source as $slug => $entry) do_action('site_template_plugin_import', $entry['payload'], $slug);
        $after = self::snapshot($available);
        foreach ($source as $slug => $entry) {
            if (!isset($after[$slug]) || $after[$slug]['schema'] !== $entry['schema']
                || $after[$slug]['payload'] !== $entry['payload'] || !$after[$slug]['state']['replaceable']) {
                throw new RuntimeException('st_plugin_import');
            }
        }
        return array_intersect_key($after, $source);
    }

    /** @param array<string,array<string,mixed>> $snapshot */
    public static function fingerprint(array $snapshot): string
    {
        ksort($snapshot);
        $nodes = 0;
        $normalized = self::normalizeJson($snapshot, 0, $nodes);
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * Package comparison excludes target-only state; it is used to detect a
     * source changing while export walks its theme and media.
     * @param array<string,array<string,mixed>> $snapshot
     */
    public static function portableFingerprint(array $snapshot): string
    {
        $portable = [];
        foreach ($snapshot as $slug => $entry) $portable[$slug] = [
            'contract' => $entry['contract'], 'schema' => $entry['schema'], 'payload' => $entry['payload'],
        ];
        return self::fingerprint($portable);
    }

    /** @param array<string,array<string,mixed>> $snapshot @return array<string,array<string,mixed>> */
    public static function rewrite(array $snapshot, array $map): array
    {
        foreach ($snapshot as &$entry) $entry['payload'] = SiteTemplateArchive::rewrite($entry['payload'], $map);
        unset($entry);
        return $snapshot;
    }

    /** @param array<string,array<string,mixed>> $source @param list<string> $missingSlugs @return array<string,array<string,mixed>> */
    public static function withoutMissing(array $source, array $missingSlugs): array
    {
        return array_diff_key($source, array_fill_keys($missingSlugs, true));
    }

    /** @param array<string,mixed> $entry @return array{contract:int,schema:array{id:string,version:int,sha256:string},payload:array,state:array{sha256:string,replaceable:bool}} */
    private static function normalizeLocal(array $entry): array
    {
        if (count($entry) !== 4 || array_diff(array_keys($entry), ['contract', 'schema', 'payload', 'state']) !== []
            || ($entry['contract'] ?? null) !== self::CONTRACT_VERSION || !is_array($entry['schema'] ?? null)
            || !is_array($entry['payload'] ?? null) || !is_array($entry['state'] ?? null)
            || count($entry['state']) !== 2 || array_diff(array_keys($entry['state']), ['sha256', 'replaceable']) !== []
            || !is_string($entry['state']['sha256'] ?? null) || preg_match('/^sha256:[a-f0-9]{64}$/D', $entry['state']['sha256']) !== 1
            || !is_bool($entry['state']['replaceable'] ?? null)) throw new RuntimeException('st_plugin_adapter');
        $nodes = 0;
        return [
            'contract' => self::CONTRACT_VERSION,
            'schema' => self::normalizeSchema($entry['schema']),
            'payload' => self::normalizeJson($entry['payload'], 0, $nodes),
            'state' => ['sha256' => $entry['state']['sha256'], 'replaceable' => $entry['state']['replaceable']],
        ];
    }

    /** @param array<string,mixed> $schema @return array{id:string,version:int,sha256:string} */
    private static function normalizeSchema(array $schema): array
    {
        if (count($schema) !== 3 || array_diff(array_keys($schema), ['id', 'version', 'sha256']) !== []
            || !is_string($schema['id'] ?? null) || preg_match('#^[a-z0-9][a-z0-9._/-]{0,119}$#D', $schema['id']) !== 1
            || !is_int($schema['version'] ?? null) || $schema['version'] < 1 || $schema['version'] > 1000000
            || !is_string($schema['sha256'] ?? null) || preg_match('/^sha256:[a-f0-9]{64}$/D', $schema['sha256']) !== 1) {
            throw new RuntimeException('st_plugin_adapter');
        }
        return ['id' => $schema['id'], 'version' => $schema['version'], 'sha256' => $schema['sha256']];
    }

    private static function encodePackage(string $slug, array $entry): string
    {
        return json_encode([
            'format' => self::FILE_FORMAT,
            'version' => self::CONTRACT_VERSION,
            'slug' => $slug,
            'schema' => $entry['schema'],
            'payload' => $entry['payload'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function normalizeJson(mixed $value, int $depth, int &$nodes): mixed
    {
        $nodes++;
        if ($depth > self::MAX_DEPTH || $nodes > self::MAX_NODES) throw new RuntimeException('st_limit');
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)) return $value;
        if (!is_array($value)) throw new RuntimeException('st_plugin_adapter');
        $list = $value === [] || array_keys($value) === range(0, count($value) - 1);
        if (!$list) {
            foreach (array_keys($value) as $key) {
                if (!is_string($key) || $key === '' || strlen($key) > 200 || preg_match('/[\x00-\x1f\x7f]/', $key)) throw new RuntimeException('st_plugin_adapter');
            }
            ksort($value);
        }
        foreach ($value as $key => $item) $value[$key] = self::normalizeJson($item, $depth + 1, $nodes);
        return $value;
    }

    private static function slugValid(string $slug): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $slug) === 1;
    }
}
