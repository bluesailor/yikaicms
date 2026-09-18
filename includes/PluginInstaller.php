<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/MarketInstallOrigin.php';

/** Staged plugin replacement; packages never execute during inspection. */
final class PluginInstaller
{
    private Closure $renamePath;
    private Closure $extractArchive;

    public function __construct(
        private string $pluginsRoot,
        private string $storageRoot,
        ?callable $renamePath = null,
        ?callable $extractArchive = null
    ) {
        $this->pluginsRoot = rtrim($pluginsRoot, '/\\');
        $this->storageRoot = rtrim($storageRoot, '/\\');
        $this->renamePath = $renamePath !== null ? Closure::fromCallable($renamePath)
            : static fn (string $from, string $to): bool => @rename($from, $to);
        $this->extractArchive = $extractArchive !== null ? Closure::fromCallable($extractArchive)
            : static fn (ZipArchive $zip, string $to): bool => $zip->extractTo($to);
    }

    /** Check before downloading; install repeats this under its directory lock. */
    public function assertOrigin(string $slug, string $origin): void
    {
        MarketInstallOrigin::assertAllowed($this->pluginsRoot, 'plugin', $slug, $origin);
    }

    /**
     * $origin must come from the freshly verified server catalog, never POST or ZIP metadata.
     * $register persists the disabled install record, or leaves an existing activation unchanged.
     * @param callable(string):void $register
     * @return array{ok:bool,code:string,slug:string,name:string,backup:string}
     */
    public function install(string $zipPath, callable $register, string $expectedSlug = '', string $expectedVersion = '', string $origin = 'local'): array
    {
        $slug = $name = $backup = $staging = '';
        $zip = null;
        $lock = null;
        $oldMoved = $newMoved = false;
        try {
            if ($origin !== 'local' && ($expectedSlug === '' || $expectedVersion === '')) {
                throw new RuntimeException('invalid');
            }
            if (!class_exists('ZipArchive')) throw new RuntimeException('no_zip');
            $archive = new ZipArchive();
            if ($archive->open($zipPath) !== true) {
                throw new RuntimeException('open_zip');
            }
            $zip = $archive;
            $manifest = $this->inspect($zip, $expectedSlug, $expectedVersion);
            $slug = $manifest['slug'];
            $name = $manifest['name'];
            $this->assertOrigin($slug, $origin);
            foreach ([$this->pluginsRoot, $this->storageRoot, $this->storageRoot . '/plugin-locks',
                $this->storageRoot . '/plugin-staging', $this->storageRoot . '/plugin-backup'] as $dir) {
                if (!self::ensureDirectory($dir)) throw new RuntimeException('staging');
            }
            $lockPath = $this->storageRoot . '/plugin-locks/' . $slug . '.lock';
            if (is_link($lockPath)) throw new RuntimeException('unsafe');
            $lock = @fopen($lockPath, 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('busy');
            $this->assertOrigin($slug, $origin);

            $token = $slug . '-' . bin2hex(random_bytes(12));
            $staging = $this->storageRoot . '/plugin-staging/' . $token;
            if (!@mkdir($staging, 0700)) throw new RuntimeException('staging');
            if (($this->extractArchive)($zip, $staging) !== true) throw new RuntimeException('extract');
            $zip->close();
            $zip = null;
            $staged = $staging . '/' . $slug;
            if (!is_dir($staged) || is_link($staged)
                || @file_get_contents($staged . '/plugin.json') !== $manifest['json']) {
                throw new RuntimeException('invalid');
            }
            if (!MarketInstallOrigin::write($staged, 'plugin', $slug, $manifest['version'], $origin)) {
                throw new RuntimeException('staging');
            }
            // Recheck after extraction, before touching the installed directory.
            $this->assertOrigin($slug, $origin);
            $target = $this->pluginsRoot . '/' . $slug;
            if (is_dir($target)) {
                $backup = $this->storageRoot . '/plugin-backup/' . $token;
                if (!(($this->renamePath)($target, $backup))) throw new RuntimeException('replace');
                $oldMoved = true;
            }
            if (!(($this->renamePath)($staged, $target))) throw new RuntimeException('replace');
            $newMoved = true;
            $register($slug);
            return ['ok' => true, 'code' => 'installed', 'slug' => $slug, 'name' => $name, 'backup' => $backup];
        } catch (Throwable $error) {
            $code = $error instanceof RuntimeException ? $error->getMessage() : 'failed';
            $restored = true;
            if ($newMoved) {
                $restored = ($this->renamePath)($this->pluginsRoot . '/' . $slug, $staging . '/failed-plugin');
            }
            if ($oldMoved && $restored) {
                $restored = ($this->renamePath)($backup, $this->pluginsRoot . '/' . $slug);
            }
            if (!$restored) $code = 'rollback_failed';
            return ['ok' => false, 'code' => $code, 'slug' => $slug, 'name' => $name, 'backup' => $backup];
        } finally {
            if ($zip instanceof ZipArchive) $zip->close();
            if ($staging !== '') self::removeTree($staging);
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** @return array{slug:string,name:string,version:string,json:string} */
    private function inspect(ZipArchive $zip, string $expectedSlug, string $expectedVersion): array
    {
        if (zipUnsafeEntry($zip) !== null) throw new RuntimeException('unsafe');
        if (zipResourceViolation($zip) !== null) throw new RuntimeException('resource');
        $slug = '';
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            $opsys = $attributes = 0;
            $zip->getExternalAttributesIndex($i, $opsys, $attributes);
            $fileType = ($attributes >> 16) & 0170000;
            if (preg_match('/[\\\\:\x00-\x1f]/', $entry) || str_contains($entry, '//')
                || ($opsys === ZipArchive::OPSYS_UNIX && !in_array($fileType, [0, 0100000, 0040000], true))) {
                throw new RuntimeException('unsafe');
            }
            $parts = explode('/', $entry);
            $root = $parts[0];
            if (!self::validSlug($root) || ($slug !== '' && $slug !== $root)
                || count($parts) < 2 || MarketInstallOrigin::isReceiptPath($entry)) {
                throw new RuntimeException('invalid');
            }
            // Reject Windows path aliases and case-insensitive duplicate entries.
            foreach ($parts as $part) {
                if ($part === '.' || str_ends_with($part, '.') || str_ends_with($part, ' ')
                    || preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(?:\.|$)/i', $part)) {
                    throw new RuntimeException('unsafe');
                }
            }
            $key = strtolower($entry);
            if (isset($names[$key])) throw new RuntimeException('invalid');
            $names[$key] = true;
            $slug = $root;
        }
        if ($slug === '') throw new RuntimeException('invalid');
        if ($expectedSlug !== '' && !hash_equals($expectedSlug, $slug)) throw new RuntimeException('mismatch');
        $stat = $zip->statName($slug . '/plugin.json');
        if ($stat === false || $stat['size'] > 65536) throw new RuntimeException('invalid');
        $json = $zip->getFromName($slug . '/plugin.json');
        $meta = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($meta) || !is_string($meta['name'] ?? null) || trim($meta['name']) === ''
            || !is_string($meta['version'] ?? null)
            || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,49}$/D', $meta['version']) !== 1
            || (isset($meta['slug']) && $meta['slug'] !== $slug)) throw new RuntimeException('invalid');
        if ($expectedVersion !== '' && !hash_equals($expectedVersion, $meta['version'])) {
            throw new RuntimeException('mismatch');
        }
        return ['slug' => $slug, 'name' => $meta['name'], 'version' => $meta['version'], 'json' => $json];
    }

    private static function validSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?$/D', $slug) === 1;
    }

    private static function ensureDirectory(string $directory): bool
    {
        return !is_link($directory) && (is_dir($directory) || @mkdir($directory, 0755, true));
    }

    private static function removeTree(string $directory): void
    {
        if (is_link($directory) || is_file($directory)) { @unlink($directory); return; }
        if (!is_dir($directory)) return;
        try {
            foreach (new DirectoryIterator($directory) as $entry) {
                if (!$entry->isDot()) self::removeTree($entry->getPathname());
            }
            @rmdir($directory);
        } catch (UnexpectedValueException) {
            // Cleanup failure must not mask the install or recovery result.
        }
    }
}
