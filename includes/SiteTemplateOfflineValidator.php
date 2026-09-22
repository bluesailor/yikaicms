<?php
declare(strict_types=1);

require_once __DIR__ . '/SiteTemplateArchive.php';
require_once __DIR__ . '/UploadReferences.php';
require_once dirname(__DIR__) . '/config/version.php';

/**
 * Release-side validation that deliberately does not bootstrap a site database.
 *
 * @psalm-suppress UnusedClass Used by tools/prepare-site-template-market.php, which is outside the runtime scan set.
 */
final class SiteTemplateOfflineValidator
{
    private const STATIC_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'pdf', 'woff', 'woff2', 'ttf', 'mp4', 'webm'];

    /** @return array{manifest:array<string,mixed>,theme_meta:array<string,mixed>,sha256:string,size:int} */
    public static function inspect(string $path): array
    {
        $size = is_file($path) ? filesize($path) : false;
        if (!is_int($size) || $size < 1 || $size > SiteTemplateArchive::MAX_ZIP || is_link($path)) {
            throw new RuntimeException('Invalid package size');
        }
        $initialHash = hash_file('sha256', $path);
        if (!is_string($initialHash) || preg_match('/^[a-f0-9]{64}$/D', $initialHash) !== 1) throw new RuntimeException('Cannot hash package');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('Invalid ZIP');
        try {
            if ($zip->numFiles < 4 || $zip->numFiles > 3000) throw new RuntimeException('Invalid ZIP entry count');
            $names = [];
            $folded = [];
            $total = 0;
            $pluginDataTotal = 0;
            $small = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
                $entrySize = is_array($stat) ? (int) ($stat['size'] ?? -1) : -1;
                $os = $attr = 0;
                $zip->getExternalAttributesIndex($i, $os, $attr);
                $key = strtolower($name);
                if (!SiteTemplateArchive::safePath($name) || isset($folded[$key]) || $entrySize < 0
                    || $entrySize > SiteTemplateArchive::MAX_FILE
                    || ($os === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000)) {
                    throw new RuntimeException('Unsafe ZIP entry: ' . $name);
                }
                $total += $entrySize;
                if ($total > SiteTemplateArchive::MAX_TOTAL) throw new RuntimeException('Expanded package is too large');
                $pluginDataPath = preg_match('#^plugin-data/[a-z0-9][a-z0-9-]{0,79}\.json$#D', $name) === 1;
                if ($pluginDataPath) {
                    $pluginDataTotal += max(0, $entrySize);
                    if ($entrySize > SiteTemplatePluginData::MAX_FILE_BYTES
                        || $pluginDataTotal > SiteTemplatePluginData::MAX_TOTAL_BYTES) {
                        throw new RuntimeException('Plugin data is too large');
                    }
                }
                if ($name !== 'site.json' && !str_starts_with($name, 'theme/') && !str_starts_with($name, 'media/') && !$pluginDataPath) {
                    throw new RuntimeException('Unexpected ZIP path: ' . $name);
                }
                if ($name !== 'site.json') {
                    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    $allowedFileType = str_starts_with($name, 'theme/')
                        ? SiteTemplateArchive::themeFileAllowed(substr($name, strlen('theme/')))
                        : in_array($extension, $pluginDataPath ? ['json'] : self::STATIC_EXTENSIONS, true);
                    if (!$allowedFileType
                        || (str_starts_with($name, 'media/') && preg_match('/\.(?:php[0-9]?|phtml|phar)(?:\.|$)/i', $name) === 1)) {
                        throw new RuntimeException('Unsafe ZIP file type: ' . $name);
                    }
                }
                $folded[$key] = true;
                $names[$name] = $i;
                if ($name === 'site.json' || $name === 'theme/theme.json') {
                    $bytes = $zip->getFromIndex($i);
                    if (!is_string($bytes)) throw new RuntimeException('Cannot read required ZIP entry');
                    $small[$name] = $bytes;
                }
            }

            $manifest = json_decode($small['site.json'] ?? '', true, 64, JSON_THROW_ON_ERROR);
            $meta = json_decode($small['theme/theme.json'] ?? '', true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || !is_array($meta)
                || ($manifest['format'] ?? '') !== 'yikaicms-site-template'
                || !in_array($manifest['version'] ?? 0, SiteTemplateArchive::SUPPORTED_VERSIONS, true)
                || !is_string($manifest['cms'] ?? null) || preg_match('/^\d+\.\d+\.\d+$/D', $manifest['cms']) !== 1
                || $manifest['cms'] !== CMS_VERSION
                || !is_string($manifest['theme'] ?? null) || preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $manifest['theme']) !== 1
                || !is_array($manifest['schema'] ?? null) || !is_array($manifest['data'] ?? null)
                || !is_array($manifest['data']['tables'] ?? null) || !is_array($manifest['data']['settings'] ?? null)) {
                throw new RuntimeException('Invalid site manifest');
            }
            if (($manifest['version'] === 2) !== !empty($manifest['plugin_data'])) throw new RuntimeException('Invalid plugin-data format contract');
            if (!is_array($manifest['plugins'] ?? null) || count($manifest['plugins']) > 100) throw new RuntimeException('Invalid plugin list');
            self::validatePlugins($manifest['plugins']);
            self::validateData($manifest['schema'], $manifest['data']);
            $themeResult = ThemeValidator::validateMeta($meta, $manifest['theme']);
            if ($themeResult['errors'] !== []) throw new RuntimeException('Invalid theme metadata');

            $declared = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
            $actualNames = array_diff(array_keys($names), ['site.json']);
            if (array_diff($actualNames, array_keys($declared)) || array_diff(array_keys($declared), $actualNames)) {
                throw new RuntimeException('Manifest file list mismatch');
            }
            foreach (ThemeValidator::REQUIRED_FILES as $required) {
                if (!isset($declared['theme/' . $required])) throw new RuntimeException('Missing required theme file: ' . $required);
            }
            $themeReferences = [];
            $pluginFiles = [];
            foreach ($declared as $name => $digest) {
                if (!is_string($name) || !is_string($digest) || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                    throw new RuntimeException('Invalid file digest: ' . (string) $name);
                }
                $stream = $zip->getStream($name);
                if (!is_resource($stream)) throw new RuntimeException('Cannot read ZIP entry: ' . $name);
                $context = hash_init('sha256');
                $text = '';
                $collectText = str_starts_with($name, 'theme/') && preg_match('/\.(?:php|css|js|json|svg)$/iD', $name) === 1;
                $capture = $collectText || str_starts_with($name, 'plugin-data/');
                while (!feof($stream)) {
                    $chunk = fread($stream, 8192);
                    if (!is_string($chunk) || ($chunk === '' && !feof($stream))) { fclose($stream); throw new RuntimeException('Cannot stream ZIP entry'); }
                    hash_update($context, $chunk);
                    if ($capture) $text .= $chunk;
                }
                fclose($stream);
                if (!hash_equals($digest, hash_final($context))) throw new RuntimeException('Digest mismatch: ' . $name);
                if ($collectText) {
                    foreach (UploadReferences::collect($text) as $relative => $count) {
                        $themeReferences[$relative] = ($themeReferences[$relative] ?? 0) + $count;
                    }
                }
                if (str_starts_with($name, 'plugin-data/')) $pluginFiles[$name] = $text;
            }
            SiteTemplatePluginData::validateArchive($manifest, $actualNames, $pluginFiles);
            $pluginData = SiteTemplatePluginData::decode($manifest['plugin_data'] ?? [], $manifest['plugins'], $pluginFiles, $declared);
            SiteTemplateArchive::validateMediaReferences($manifest, $pluginData, $themeReferences);
            clearstatcache(true, $path);
            $finalSize = is_file($path) && !is_link($path) ? filesize($path) : false;
            $finalHash = is_int($finalSize) ? hash_file('sha256', $path) : false;
            if ($finalSize !== $size || !is_string($finalHash) || !hash_equals($initialHash, $finalHash)) {
                throw new RuntimeException('Package changed during inspection');
            }
            return ['manifest' => $manifest, 'theme_meta' => $meta, 'sha256' => $finalHash, 'size' => $size];
        } finally {
            $zip->close();
        }
    }

    /**
     * @param list<string> $allowedFiles
     * @param list<string> $requiredFiles
     * @psalm-suppress PossiblyUnusedMethod Release tooling calls this method outside Psalm's project files.
     */
    public static function assertPublicStaging(string $root, array $allowedFiles, array $requiredFiles = []): void
    {
        $resolved = self::resolvedDirectory($root, 'Public staging directory must already exist and must not be a symlink');
        $allowed = [];
        $directories = [];
        foreach ($allowedFiles as $relative) {
            if (!SiteTemplateArchive::safePath($relative) || isset($allowed[$relative])) {
                throw new RuntimeException('Invalid staging allowlist path');
            }
            $allowed[$relative] = true;
            $parent = dirname($relative);
            while ($parent !== '.' && $parent !== '') {
                $directories[str_replace('\\', '/', $parent)] = true;
                $parent = dirname($parent);
            }
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) continue;
            $path = str_replace('\\', '/', $entry->getPathname());
            $relative = substr($path, strlen(str_replace('\\', '/', $resolved)) + 1);
            $entryResolved = realpath($entry->getPathname());
            if ($entry->isLink() || $entryResolved === false
                || !self::samePath($path, str_replace('\\', '/', $entryResolved))
                || !self::containedPath($resolved, $entryResolved)
                || ($entry->isDir() ? !isset($directories[$relative]) : !isset($allowed[$relative]))) {
                throw new RuntimeException('Unknown or unsafe public staging entry: ' . $relative);
            }
        }
        foreach ($requiredFiles as $relative) {
            $path = $resolved . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $fileResolved = realpath($path);
            if (!isset($allowed[$relative]) || !is_file($path) || is_link($path) || $fileResolved === false
                || !self::samePath(str_replace('\\', '/', $path), str_replace('\\', '/', $fileResolved))
                || !self::containedPath($resolved, $fileResolved)) {
                throw new RuntimeException('Missing required staging file: ' . $relative);
            }
        }
    }

    /**
     * Resolve a future output path while creating only ordinary directories beneath the trusted root.
     *
     * @return array{path:string,created:list<string>}
     */
    public static function prepareContainedTarget(string $root, string $relative): array
    {
        if (!SiteTemplateArchive::safePath($relative)) throw new RuntimeException('Invalid staging target path');
        $resolved = self::resolvedDirectory($root, 'Invalid staging target root');
        $parts = explode('/', $relative);
        array_pop($parts);
        $current = $resolved;
        $created = [];
        try {
            foreach ($parts as $part) {
                $current .= DIRECTORY_SEPARATOR . $part;
                if (!file_exists($current)) {
                    if (!mkdir($current, 0755)) throw new RuntimeException('Cannot create staging directory');
                    $created[] = $current;
                }
                $directoryResolved = realpath($current);
                if ($directoryResolved === false || !is_dir($current) || is_link($current)
                    || !self::samePath(str_replace('\\', '/', $current), str_replace('\\', '/', $directoryResolved))
                    || !self::containedPath($resolved, $directoryResolved)) {
                    throw new RuntimeException('Unsafe staging target directory');
                }
            }
            $target = self::assertContainedTarget($resolved, $relative);
        } catch (Throwable $error) {
            foreach (array_reverse($created) as $directory) {
                if (is_dir($directory) && !is_link($directory)) @rmdir($directory);
            }
            throw $error;
        }
        return ['path' => $target, 'created' => $created];
    }

    /**
     * Re-resolve every parent immediately around a live write. This does not make string-path writes
     * crash-atomic, but it prevents a persistent symlink/junction substitution from escaping the root.
     */
    public static function assertContainedTarget(string $root, string $relative, bool $mustExist = false): string
    {
        if (!SiteTemplateArchive::safePath($relative)) throw new RuntimeException('Invalid staging target path');
        $resolved = self::resolvedDirectory($root, 'Invalid staging target root');
        $parts = explode('/', $relative);
        array_pop($parts);
        $current = $resolved;
        foreach ($parts as $part) {
            $current .= DIRECTORY_SEPARATOR . $part;
            $directoryResolved = realpath($current);
            if ($directoryResolved === false || !is_dir($current) || is_link($current)
                || !self::samePath(str_replace('\\', '/', $current), str_replace('\\', '/', $directoryResolved))
                || !self::containedPath($resolved, $directoryResolved)) {
                throw new RuntimeException('Unsafe staging target directory');
            }
        }
        $target = $resolved . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (file_exists($target) || is_link($target)) {
            $targetResolved = realpath($target);
            if ($targetResolved === false || !is_file($target) || is_link($target)
                || !self::samePath(str_replace('\\', '/', $target), str_replace('\\', '/', $targetResolved))
                || !self::containedPath($resolved, $targetResolved)) {
                throw new RuntimeException('Unsafe staging target file');
            }
        } elseif ($mustExist) {
            throw new RuntimeException('Missing staging target file');
        }
        return $target;
    }

    /**
     * Final package-byte check, intentionally run after covers and catalogs are written. This closes
     * the preparation window in which a package could otherwise diverge from its catalog digest.
     *
     * @param array<string,array{size:int,sha256:string}> $expected relative path => verified bytes
     * @psalm-suppress PossiblyUnusedMethod Release tooling calls this method outside Psalm's project files.
     */
    public static function assertPackageDigests(string $root, array $expected): void
    {
        $resolved = self::resolvedDirectory($root, 'Invalid package staging root');
        $prefix = rtrim(str_replace('\\', '/', $resolved), '/') . '/';
        foreach ($expected as $relative => $contract) {
            if (!is_string($relative) || !SiteTemplateArchive::safePath($relative)
                || !is_array($contract) || !is_int($contract['size'] ?? null)
                || !is_string($contract['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $contract['sha256']) !== 1) {
                throw new RuntimeException('Invalid package digest contract');
            }
            $path = $resolved . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $real = realpath($path);
            $size = is_file($path) && !is_link($path) ? filesize($path) : false;
            if ($real === false || !self::samePath(str_replace('\\', '/', $path), str_replace('\\', '/', $real))
                || !str_starts_with(str_replace('\\', '/', $real), $prefix)
                || !is_int($size) || $size !== $contract['size']
                || !hash_equals($contract['sha256'], (string) hash_file('sha256', $path))) {
                throw new RuntimeException('Prepared package bytes changed: ' . $relative);
            }
        }
    }

    private static function resolvedDirectory(string $path, string $message): string
    {
        $trimmed = rtrim($path, "/\\");
        $resolved = realpath($trimmed);
        $parent = realpath(dirname($trimmed));
        $lexical = $parent === false ? false : $parent . DIRECTORY_SEPARATOR . basename($trimmed);
        if ($resolved === false || $parent === false || !is_dir($resolved) || is_link($trimmed)
            || $lexical === false
            || !self::samePath(str_replace('\\', '/', $lexical), str_replace('\\', '/', $resolved))) {
            throw new RuntimeException($message);
        }
        return $resolved;
    }

    private static function containedPath(string $root, string $path): bool
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);
        if (DIRECTORY_SEPARATOR === '\\') {
            $root = strtolower($root);
            $path = strtolower($path);
        }
        return $path === $root || str_starts_with($path, $root . '/');
    }

    private static function samePath(string $left, string $right): bool
    {
        $left = rtrim($left, '/');
        $right = rtrim($right, '/');
        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($left, $right) === 0 : $left === $right;
    }

    /**
     * @param array<string,array{version:string,sha256:string}> $expected
     * @psalm-suppress PossiblyUnusedMethod Release tooling calls this method outside Psalm's project files.
     */
    public static function validateImportReport(string $path, array $expected): void
    {
        $size = is_file($path) ? filesize($path) : false;
        if (!is_int($size) || $size < 2 || $size > 1048576) throw new RuntimeException('Invalid import report');
        $decoded = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $rows = is_array($decoded) && is_array($decoded['sites'] ?? null) ? $decoded['sites'] : $decoded;
        if (!is_array($rows)) throw new RuntimeException('Invalid import report');
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)) throw new RuntimeException('Invalid import report row');
            $slug = (string) ($row['theme'] ?? $row['slug'] ?? '');
            $version = (string) ($row['version'] ?? $row['new_version'] ?? '');
            $hash = strtolower((string) ($row['package_sha256'] ?? ''));
            $status = strtolower((string) ($row['status'] ?? $row['import_status'] ?? ''));
            if (!isset($expected[$slug]) || isset($seen[$slug]) || !in_array($status, ['passed', 'success', 'verified', 'ok'], true)
                || !hash_equals($expected[$slug]['version'], $version) || !hash_equals($expected[$slug]['sha256'], $hash)) {
                throw new RuntimeException('Import report does not match verified package: ' . $slug);
            }
            $seen[$slug] = true;
        }
        if (array_diff_key($expected, $seen) || array_diff_key($seen, $expected)) throw new RuntimeException('Import report coverage mismatch');
    }

    /** @param array<mixed> $plugins */
    private static function validatePlugins(array $plugins): void
    {
        $seen = [];
        foreach ($plugins as $plugin) {
            if (!is_array($plugin) || array_keys($plugin) !== ['slug', 'version']) throw new RuntimeException('Invalid plugin list');
            $slug = $plugin['slug'] ?? null;
            $version = $plugin['version'] ?? null;
            if (!is_string($slug) || preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $slug) !== 1 || isset($seen[$slug])
                || !is_string($version) || preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,39}$/D', $version) !== 1) {
                throw new RuntimeException('Invalid plugin list');
            }
            $seen[$slug] = true;
        }
    }

    /** @param array<string,mixed> $schema @param array<string,mixed> $data */
    private static function validateData(array $schema, array $data): void
    {
        if ($schema !== SiteTemplateData::contractSchema()) throw new RuntimeException('Invalid portable schema contract');
        $tables = $data['tables'];
        if (array_keys($tables) !== SiteTemplateData::TABLES || array_keys($schema) !== SiteTemplateData::TABLES) {
            throw new RuntimeException('Invalid portable data tables');
        }
        $ids = [];
        foreach ($schema as $table => $columns) {
            if (!is_array($columns) || $columns === [] || count($columns) !== count(array_unique($columns))) {
                throw new RuntimeException('Invalid portable schema');
            }
            foreach ($columns as $column) {
                if (!is_string($column) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/D', $column) !== 1) throw new RuntimeException('Invalid portable schema');
            }
            $rows = $tables[$table];
            if (!is_array($rows) || count($rows) > 10000) throw new RuntimeException('Invalid portable data rows');
            foreach ($rows as $row) {
                if (!is_array($row) || array_diff(array_keys($row), $columns)) throw new RuntimeException('Portable row does not match embedded schema');
                foreach ($row as $value) if (!is_scalar($value) && $value !== null) throw new RuntimeException('Invalid portable row value');
                $id = $table === 'product_tag_map'
                    ? (string) ($row['product_id'] ?? 0) . ':' . (string) ($row['tag_id'] ?? 0)
                    : (string) (int) ($row['id'] ?? 0);
                if ($table === 'product_tag_map'
                    ? ((int) ($row['product_id'] ?? 0) <= 0 || (int) ($row['tag_id'] ?? 0) <= 0)
                    : (int) $id <= 0) throw new RuntimeException('Invalid portable row identity');
                if (isset($ids[$table][$id])) throw new RuntimeException('Duplicate portable row identity');
                $ids[$table][$id] = true;
            }
        }
        $relations = [
            'channels' => ['parent_id' => 'channels', 'album_id' => 'albums'], 'contents' => ['channel_id' => 'channels'],
            'product_categories' => ['parent_id' => 'product_categories'], 'products' => ['category_id' => 'product_categories', 'brand_id' => 'brands'],
            'product_tag_map' => ['product_id' => 'products', 'tag_id' => 'product_tags'], 'album_photos' => ['album_id' => 'albums'],
            'downloads' => ['category_id' => 'download_categories'],
        ];
        foreach ($relations as $table => $fields) foreach ($tables[$table] as $row) foreach ($fields as $field => $target) {
            $value = (int) ($row[$field] ?? 0);
            if ($value !== 0 && !isset($ids[$target][(string) $value])) throw new RuntimeException('Dangling portable data reference');
        }
        foreach ($data['settings'] as $key => $value) {
            if (!is_string($key) || !is_string($value) || !SiteTemplateData::settingAllowed($key)) throw new RuntimeException('Invalid portable setting');
        }
        if (!in_array($data['settings']['site_lang'] ?? 'zh-CN', ['zh-CN', 'en', 'ja'], true)) throw new RuntimeException('Invalid portable language');
        $languages = json_decode($data['settings']['enabled_languages'] ?? '["zh-CN"]', true);
        if (!is_array($languages) || $languages === [] || array_diff($languages, ['zh-CN', 'en', 'ja'])) throw new RuntimeException('Invalid portable languages');
    }
}
