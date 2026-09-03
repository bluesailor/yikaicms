<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/ThemeValidator.php';

/**
 * Theme package installation with staging, atomic directory replacement and rollback.
 */
final class ThemeInstaller
{
    private string $themesRoot;
    private string $storageRoot;
    private Closure $renamePath;
    private Closure $extractArchive;
    private Closure $validateDirectory;
    private Closure $removeDirectory;
    private Closure $makeDirectory;

    public function __construct(
        string $themesRoot,
        string $storageRoot,
        ?callable $renamePath = null,
        ?callable $extractArchive = null,
        ?callable $validateDirectory = null,
        ?callable $removeDirectory = null,
        ?callable $makeDirectory = null
    ) {
        $this->themesRoot = rtrim($themesRoot, '/\\');
        $this->storageRoot = rtrim($storageRoot, '/\\');
        $this->renamePath = $renamePath !== null
            ? Closure::fromCallable($renamePath)
            : static fn (string $from, string $to): bool => @rename($from, $to);
        $this->extractArchive = $extractArchive !== null
            ? Closure::fromCallable($extractArchive)
            : static fn (ZipArchive $zip, string $destination): bool => $zip->extractTo($destination);
        $this->validateDirectory = $validateDirectory !== null
            ? Closure::fromCallable($validateDirectory)
            : static fn (string $directory, string $slug): array => ThemeValidator::validateDir($directory, $slug);
        $this->removeDirectory = $removeDirectory !== null
            ? Closure::fromCallable($removeDirectory)
            : static fn (string $directory): bool => self::removeDirectoryTree($directory);
        $this->makeDirectory = $makeDirectory !== null
            ? Closure::fromCallable($makeDirectory)
            : static fn (string $directory): bool => is_dir($directory) || @mkdir($directory, 0755, true);
    }

    /**
     * @return array{
     *     ok:bool,
     *     code:string,
     *     detail:string,
     *     slug:string,
     *     name:string,
     *     warnings:list<string>,
     *     backup:string
     * }
     */
    public function install(string $zipPath, string $expectedSlug = ''): array
    {
        if (!class_exists('ZipArchive')) {
            return $this->result(false, 'no_zip');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return $this->result(false, 'open_zip');
        }

        $inspection = $this->inspectArchive($zip);
        if (!$inspection['ok']) {
            $zip->close();
            return $this->result(false, $inspection['code'], $inspection['detail']);
        }

        $slug = $inspection['slug'];
        $name = $inspection['name'];
        $warnings = $inspection['warnings'];
        if ($slug === 'default') {
            $zip->close();
            return $this->result(false, 'default_protected', '', $slug, $name, $warnings);
        }
        if ($expectedSlug !== '' && !hash_equals($expectedSlug, $slug)) {
            $zip->close();
            return $this->result(false, 'slug_mismatch', '', $slug, $name, $warnings);
        }

        $token = date('Ymd-His') . '-' . bin2hex(random_bytes(5));
        $stagingRoot = $this->storageRoot . '/theme-staging';
        $stagingDir = $stagingRoot . '/' . $token;
        if (!(($this->makeDirectory)($this->themesRoot))
            || !(($this->makeDirectory)($stagingRoot))
            || !(($this->makeDirectory)($stagingDir))) {
            $zip->close();
            return $this->result(false, 'staging_create', '', $slug, $name, $warnings);
        }

        try {
            $extracted = ($this->extractArchive)($zip, $stagingDir);
        } catch (Throwable $e) {
            $extracted = false;
        } finally {
            $zip->close();
        }
        if ($extracted !== true) {
            $clean = ($this->removeDirectory)($stagingDir);
            return $this->result(
                false,
                $clean ? 'extract' : 'cleanup',
                '',
                $slug,
                $name,
                $warnings
            );
        }

        $stagedTheme = $stagingDir . '/' . $slug;
        $stagedValidation = $this->validate($stagedTheme, $slug);
        $warnings = array_values(array_unique(array_merge(
            $warnings,
            is_array($stagedValidation['warnings'] ?? null) ? $stagedValidation['warnings'] : []
        )));
        if ($stagedValidation['errors'] !== []) {
            $clean = ($this->removeDirectory)($stagingDir);
            return $this->result(
                false,
                $clean ? 'staging_invalid' : 'cleanup',
                implode('; ', $stagedValidation['errors']),
                $slug,
                $name,
                $warnings
            );
        }

        $targetDir = $this->themesRoot . '/' . $slug;
        $backupDir = '';
        if (is_dir($targetDir)) {
            $backupRoot = $this->storageRoot . '/theme-backup';
            if (!(($this->makeDirectory)($backupRoot))) {
                ($this->removeDirectory)($stagingDir);
                return $this->result(false, 'backup_create', '', $slug, $name, $warnings);
            }
            $backupDir = $backupRoot . '/' . $slug . '-' . $token;
            if (!(($this->renamePath)($targetDir, $backupDir))) {
                ($this->removeDirectory)($stagingDir);
                return $this->result(false, 'backup_move', '', $slug, $name, $warnings);
            }
        }

        if (!(($this->renamePath)($stagedTheme, $targetDir))) {
            $restored = $backupDir === '' || (($this->renamePath)($backupDir, $targetDir));
            ($this->removeDirectory)($stagingDir);
            return $this->result(
                false,
                $restored ? 'activate' : 'rollback_failed',
                '',
                $slug,
                $name,
                $warnings,
                $backupDir
            );
        }

        $finalValidation = $this->validate($targetDir, $slug);
        $finalErrors = $finalValidation['errors'];
        if ($finalErrors !== []) {
            $restored = $this->rollbackTarget($targetDir, $backupDir, $stagingDir);
            ($this->removeDirectory)($stagingDir);
            return $this->result(
                false,
                $restored ? 'final_invalid' : 'rollback_failed',
                implode('; ', $finalErrors),
                $slug,
                $name,
                $warnings,
                $backupDir
            );
        }

        if (!(($this->removeDirectory)($stagingDir))) {
            $restored = $this->rollbackTarget($targetDir, $backupDir, $stagingDir);
            ($this->removeDirectory)($stagingDir);
            return $this->result(
                false,
                $restored ? 'cleanup' : 'rollback_failed',
                '',
                $slug,
                $name,
                $warnings,
                $backupDir
            );
        }

        return $this->result(true, 'installed', '', $slug, $name, $warnings, $backupDir);
    }

    /**
     * @return array{ok:bool,code:string,detail:string,slug:string,name:string,warnings:list<string>}
     */
    private function inspectArchive(ZipArchive $zip): array
    {
        $slug = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (preg_match('#^([a-z0-9]([a-z0-9\-]*[a-z0-9])?)/theme\.json$#D', $name, $match) !== 1) {
                continue;
            }
            if ($slug !== '' && $slug !== $match[1]) {
                return ['ok' => false, 'code' => 'invalid', 'detail' => 'ZIP contains multiple theme roots', 'slug' => '', 'name' => '', 'warnings' => []];
            }
            $slug = $match[1];
        }
        if ($slug === '') {
            return ['ok' => false, 'code' => 'no_json', 'detail' => '', 'slug' => '', 'name' => '', 'warnings' => []];
        }

        $meta = json_decode((string) $zip->getFromName($slug . '/theme.json'), true);
        if (!is_array($meta) || empty($meta['name'])) {
            return ['ok' => false, 'code' => 'bad_json', 'detail' => '', 'slug' => '', 'name' => '', 'warnings' => []];
        }
        $validation = ThemeValidator::validateMeta($meta, $slug);
        foreach (ThemeValidator::REQUIRED_FILES as $requiredFile) {
            if ($zip->locateName($slug . '/' . $requiredFile) === false) {
                $validation['errors'][] = "Missing {$requiredFile}";
            }
        }
        if ($validation['errors'] !== []) {
            return [
                'ok' => false,
                'code' => 'invalid',
                'detail' => implode('; ', $validation['errors']),
                'slug' => $slug,
                'name' => (string) $meta['name'],
                'warnings' => $validation['warnings'],
            ];
        }

        $unsafe = zipUnsafeEntry($zip);
        if ($unsafe !== null) {
            return ['ok' => false, 'code' => 'unsafe', 'detail' => $unsafe, 'slug' => $slug, 'name' => (string) $meta['name'], 'warnings' => $validation['warnings']];
        }
        $resourceViolation = zipResourceViolation($zip);
        if ($resourceViolation !== null) {
            return ['ok' => false, 'code' => 'resource', 'detail' => $resourceViolation, 'slug' => $slug, 'name' => (string) $meta['name'], 'warnings' => $validation['warnings']];
        }

        return [
            'ok' => true,
            'code' => '',
            'detail' => '',
            'slug' => $slug,
            'name' => (string) $meta['name'],
            'warnings' => $validation['warnings'],
        ];
    }

    private function rollbackTarget(string $targetDir, string $backupDir, string $stagingDir): bool
    {
        if (is_dir($targetDir)) {
            $quarantine = $stagingDir . '/failed-theme';
            if (!(($this->renamePath)($targetDir, $quarantine))
                && !(($this->removeDirectory)($targetDir))) {
                return false;
            }
        }
        return $backupDir === '' || (($this->renamePath)($backupDir, $targetDir));
    }

    /** @return array{errors:list<string>,warnings:list<string>} */
    private function validate(string $directory, string $slug): array
    {
        try {
            $validation = ($this->validateDirectory)($directory, $slug);
        } catch (Throwable $e) {
            return ['errors' => ['Theme validation failed: ' . $e->getMessage()], 'warnings' => []];
        }
        if (!is_array($validation) || !is_array($validation['errors'] ?? null)) {
            return ['errors' => ['Theme validator returned an invalid result'], 'warnings' => []];
        }
        $errors = array_values(array_map('strval', $validation['errors']));
        $warnings = is_array($validation['warnings'] ?? null)
            ? array_values(array_map('strval', $validation['warnings']))
            : [];
        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @param list<string> $warnings
     * @return array{ok:bool,code:string,detail:string,slug:string,name:string,warnings:list<string>,backup:string}
     */
    private function result(
        bool $ok,
        string $code,
        string $detail = '',
        string $slug = '',
        string $name = '',
        array $warnings = [],
        string $backup = ''
    ): array {
        return compact('ok', 'code', 'detail', 'slug', 'name', 'warnings', 'backup');
    }

    private static function removeDirectoryTree(string $directory): bool
    {
        if (!file_exists($directory) && !is_link($directory)) {
            return true;
        }
        if (is_file($directory) || is_link($directory)) {
            return @unlink($directory);
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            $ok = true;
            foreach ($iterator as $item) {
                $removed = $item->isDir() && !$item->isLink()
                    ? @rmdir($item->getPathname())
                    : @unlink($item->getPathname());
                $ok = $removed && $ok;
            }
            return @rmdir($directory) && $ok;
        } catch (UnexpectedValueException $e) {
            return false;
        }
    }
}
