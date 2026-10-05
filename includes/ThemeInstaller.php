<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/ThemeValidator.php';
require_once __DIR__ . '/MarketInstallOrigin.php';

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
    public function install(
        string $zipPath,
        string $expectedSlug = '',
        string $expectedVersion = '',
        string $origin = 'local',
        string $signature = ''
    ): array
    {
        if ($origin !== 'local' && ($expectedSlug === '' || $expectedVersion === '')) {
            return $this->result(false, 'invalid');
        }
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
        $version = $inspection['version'];
        $warnings = $inspection['warnings'];
        if ($slug === 'default') {
            // Do not trust a caller's verified flag. Bind the official signature to these exact bytes.
            require_once __DIR__ . '/ThemeMarket.php';
            require_once __DIR__ . '/License.php';
            $localVersions = ThemeMarket::localVersions($this->themesRoot);
            if ($expectedSlug !== 'default' || $expectedVersion !== $version
                || !isset($localVersions['default'])
                || !ThemeMarket::isRemoteVersionNewer($localVersions, 'default', $version)
                || !ThemeMarket::verifyPackageSignature('default', $version,
                    'sha256:' . (string) hash_file('sha256', $zipPath), $signature, license_pubkey())) {
                $zip->close();
                return $this->result(false, 'default_protected', '', $slug, $name, $warnings);
            }
        }
        if ($expectedSlug !== '' && !hash_equals($expectedSlug, $slug)) {
            $zip->close();
            return $this->result(false, 'slug_mismatch', '', $slug, $name, $warnings);
        }
        if ($expectedVersion !== '' && !hash_equals($expectedVersion, $version)) {
            $zip->close();
            return $this->result(false, 'version_mismatch', '', $slug, $name, $warnings);
        }

        $lock = null;
        try {
            $this->assertOrigin($slug, $origin);
            $lockRoot = $this->storageRoot . '/theme-locks';
            if (is_link($this->storageRoot) || is_link($lockRoot)
                || !(($this->makeDirectory)($lockRoot))) {
                throw new RuntimeException('staging_create');
            }
            $lockPath = $lockRoot . '/' . $slug . '.lock';
            if (is_link($lockPath)) throw new RuntimeException('unsafe');
            $lock = @fopen($lockPath, 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('busy');
            $this->assertOrigin($slug, $origin);
        } catch (RuntimeException $error) {
            $zip->close();
            if (is_resource($lock)) fclose($lock);
            return $this->result(false, $error->getMessage(), '', $slug, $name, $warnings);
        }
        try {
            return $this->installValidated($zip, $inspection, $origin);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function assertOrigin(string $slug, string $origin): void
    {
        MarketInstallOrigin::assertAllowed($this->themesRoot, 'theme', $slug, $origin);
    }

    /**
     * 2.0.5：随官方整站模板装上的主题（目录 sitepack-*，回执里记着市场 slug）用市场新版升级。
     * 包仍是市场原包（调用方已按市场目录验过哈希与签名）；装进原别名目录，照导入时的路径改写再改一遍，
     * 新包里没有的本地文件（模板作者加的页面模板、站长加的文件）保留。站长改过或加过文件时，
     * 不带 $acceptLocalChanges 就返回 local_changes 与文件清单，确认后再装（照常先备份原目录）。
     *
     * @return array{ok:bool,code:string,detail:string,slug:string,name:string,warnings:list<string>,backup:string}
     */
    public function installLinked(string $zipPath, string $directory, string $expectedVersion, bool $acceptLocalChanges = false): array
    {
        $link = MarketInstallOrigin::linked($this->themesRoot, $directory);
        if ($link === null || $expectedVersion === '') return $this->result(false, 'origin_unknown', '', $directory);
        if (!class_exists('ZipArchive')) return $this->result(false, 'no_zip');
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) return $this->result(false, 'open_zip');
        $inspection = $this->inspectArchive($zip);
        if (!$inspection['ok']) {
            $zip->close();
            return $this->result(false, $inspection['code'], $inspection['detail']);
        }
        if (!hash_equals($link['market_slug'], $inspection['slug'])) {
            $zip->close();
            return $this->result(false, 'slug_mismatch', '', $inspection['slug'], $inspection['name'], $inspection['warnings']);
        }
        if (!hash_equals($expectedVersion, $inspection['version'])) {
            $zip->close();
            return $this->result(false, 'version_mismatch', '', $inspection['slug'], $inspection['name'], $inspection['warnings']);
        }
        $lock = null;
        try {
            $lockRoot = $this->storageRoot . '/theme-locks';
            if (is_link($this->storageRoot) || is_link($lockRoot) || !(($this->makeDirectory)($lockRoot))) throw new RuntimeException('staging_create');
            $lockPath = $lockRoot . '/' . $directory . '.lock';
            if (is_link($lockPath)) throw new RuntimeException('unsafe');
            $lock = @fopen($lockPath, 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('busy');
            $changes = $this->localChanges($directory);
            if (!$acceptLocalChanges && $changes !== []) {
                throw new RuntimeException('local_changes');
            }
        } catch (RuntimeException $error) {
            $zip->close();
            if (is_resource($lock)) fclose($lock);
            return $this->result(false, $error->getMessage(), $error->getMessage() === 'local_changes' ? implode("\n", array_slice($changes ?? [], 0, 30)) : '',
                $directory, $inspection['name'], $inspection['warnings']);
        }
        $current = $this->themesRoot . '/' . $directory;
        $prepare = function (string $staged) use ($link, $current): void {
            require_once __DIR__ . '/SiteTemplateArchive.php';
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($staged, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file->isFile() || $file->isLink()) continue;
                if (!in_array(strtolower($file->getExtension()), ['php', 'css', 'js', 'json', 'svg'], true)) continue;
                $bytes = (string) file_get_contents($file->getPathname());
                $rewritten = (string) SiteTemplateArchive::rewrite($bytes, $link['rewrite']);
                if ($rewritten !== $bytes && @file_put_contents($file->getPathname(), $rewritten) !== strlen($rewritten)) throw new RuntimeException('staging_create');
            }
            // 新包里没有的本地文件原样带过去（回执与文件清单除外，安装器会重写）
            foreach (self::fileList($current) as $relative) {
                if (MarketInstallOrigin::isReceiptPath($relative) || file_exists($staged . '/' . $relative)) continue;
                if (!(($this->makeDirectory)(dirname($staged . '/' . $relative)))
                    || !@copy($current . '/' . $relative, $staged . '/' . $relative)) throw new RuntimeException('staging_create');
            }
        };
        try {
            return $this->installValidated($zip, $inspection, 'official', $directory, $prepare, $link);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * 与安装时的文件清单比，站长改过（~）和新加（+）的文件；没有清单（2.0.5 之前装的）时整个目录都算未知（?）。
     * @return list<string>
     */
    public function localChanges(string $directory): array
    {
        $root = $this->themesRoot . '/' . $directory;
        $manifestFile = $root . '/' . MarketInstallOrigin::FILES;
        $manifest = is_file($manifestFile) && !is_link($manifestFile) && (int) @filesize($manifestFile) <= 2_000_000
            ? json_decode((string) @file_get_contents($manifestFile), true) : null;
        if (!is_array($manifest)) return ['? ' . MarketInstallOrigin::FILES];
        $changes = [];
        foreach (self::fileList($root) as $relative) {
            if (MarketInstallOrigin::isReceiptPath($relative)) continue;
            if (!isset($manifest[$relative])) { $changes[] = '+ ' . $relative; continue; }
            if (!hash_equals((string) $manifest[$relative], (string) hash_file('sha256', $root . '/' . $relative))) $changes[] = '~ ' . $relative;
        }
        return $changes;
    }

    /** 写入安装后的文件清单（相对路径 → sha256）。写不进去不影响安装，只是下次升级会当成「未知改动」要求确认。 */
    private function writeFileManifest(string $directory): void
    {
        $hashes = [];
        foreach (self::fileList($directory) as $relative) {
            if (MarketInstallOrigin::isReceiptPath($relative)) continue;
            $hashes[$relative] = (string) hash_file('sha256', $directory . '/' . $relative);
        }
        ksort($hashes);
        $file = $directory . '/' . MarketInstallOrigin::FILES;
        if (!is_link($file)) @file_put_contents($file, json_encode($hashes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    /** @return list<string> 目录下全部普通文件的相对路径（/ 分隔，跳过符号链接） */
    private static function fileList(string $directory): array
    {
        if (!is_dir($directory) || is_link($directory)) return [];
        $out = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->isLink()) continue;
            $out[] = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
        }
        sort($out);
        return $out;
    }

    /**
     * @param ?Closure(string):void $prepare 校验通过后、换入前对暂存目录做的加工（关联升级：路径改写与保留本地文件）
     * @param array{market_slug?:string,rewrite?:array<string,string>} $link 写进回执的市场关联
     */
    private function installValidated(ZipArchive $zip, array $inspection, string $origin, string $targetSlug = '', ?Closure $prepare = null, array $link = []): array
    {
        $slug = $inspection['slug'];
        $name = $inspection['name'];
        $version = $inspection['version'];
        $warnings = $inspection['warnings'];
        $target = $targetSlug !== '' ? $targetSlug : $slug;
        $token = date('Ymd-His') . '-' . bin2hex(random_bytes(5));
        $stagingRoot = $this->storageRoot . '/theme-staging';
        $stagingDir = $stagingRoot . '/' . $token;
        if (is_link($this->themesRoot) || is_link($stagingRoot)
            || !(($this->makeDirectory)($this->themesRoot))
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

        try {
            if ($link !== []) {
                if ((MarketInstallOrigin::linked($this->themesRoot, $target)['market_slug'] ?? '') !== $slug) throw new RuntimeException('origin_unknown');
            } else {
                $this->assertOrigin($slug, $origin);
            }
            if ($prepare !== null) $prepare($stagedTheme);
            if (!MarketInstallOrigin::write($stagedTheme, 'theme', $target, $version, $origin, $link)) {
                throw new RuntimeException('staging_create');
            }
        } catch (RuntimeException $error) {
            ($this->removeDirectory)($stagingDir);
            return $this->result(false, $error->getMessage(), '', $slug, $name, $warnings);
        }
        $slug = $target;
        $targetDir = $this->themesRoot . '/' . $slug;
        $backupDir = '';
        if (is_dir($targetDir)) {
            $backupRoot = $this->storageRoot . '/theme-backup';
            if (is_link($backupRoot) || !(($this->makeDirectory)($backupRoot))) {
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

        $this->writeFileManifest($targetDir);
        return $this->result(true, 'installed', '', $slug, $name, $warnings, $backupDir);
    }

    /**
     * Remove an inactive installed theme without allowing traversal or symlink escapes.
     *
     * @return array{ok:bool,code:string,detail:string,slug:string,name:string,warnings:list<string>,backup:string}
     */
    public function removeInstalled(string $slug, string $activeSlug): array
    {
        if (preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/D', $slug) !== 1) {
            return $this->result(false, 'bad_slug', '', $slug);
        }
        if ($slug === 'default') {
            return $this->result(false, 'default_protected', '', $slug);
        }
        if ($slug === $activeSlug) {
            return $this->result(false, 'active_protected', '', $slug);
        }

        $directory = $this->themesRoot . '/' . $slug;
        $realDirectory = realpath($directory);
        $realRoot = realpath($this->themesRoot);
        if ($realDirectory === false || $realRoot === false || !is_dir($realDirectory)
            || !is_file($realDirectory . '/theme.json')) {
            return $this->result(false, 'not_found', '', $slug);
        }
        $normalizedDirectory = str_replace('\\', '/', $realDirectory) . '/';
        $normalizedRoot = rtrim(str_replace('\\', '/', $realRoot), '/') . '/';
        if (!str_starts_with($normalizedDirectory, $normalizedRoot)) {
            return $this->result(false, 'unsafe', '', $slug);
        }
        if (!(($this->removeDirectory)($realDirectory))) {
            return $this->result(false, 'delete_failed', '', $slug);
        }
        return $this->result(true, 'deleted', '', $slug);
    }

    /**
     * @return array{ok:bool,code:string,detail:string,slug:string,name:string,version:string,warnings:list<string>}
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
                return ['ok' => false, 'code' => 'invalid', 'detail' => 'ZIP contains multiple theme roots', 'slug' => '', 'name' => '', 'version' => '', 'warnings' => []];
            }
            $slug = $match[1];
        }
        if ($slug === '') {
            return ['ok' => false, 'code' => 'no_json', 'detail' => '', 'slug' => '', 'name' => '', 'version' => '', 'warnings' => []];
        }

        $meta = json_decode((string) $zip->getFromName($slug . '/theme.json'), true);
        if (!is_array($meta) || empty($meta['name'])) {
            return ['ok' => false, 'code' => 'bad_json', 'detail' => '', 'slug' => '', 'name' => '', 'version' => '', 'warnings' => []];
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
                'version' => (string) ($meta['version'] ?? ''),
                'warnings' => $validation['warnings'],
            ];
        }

        $unsafe = zipUnsafeEntry($zip);
        for ($i = 0; $unsafe === null && $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            $opsys = $attributes = 0;
            $zip->getExternalAttributesIndex($i, $opsys, $attributes);
            if (MarketInstallOrigin::isReceiptPath($entry)
                || ($opsys === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000)) {
                $unsafe = $entry;
            }
        }
        if ($unsafe !== null) {
            return ['ok' => false, 'code' => 'unsafe', 'detail' => $unsafe, 'slug' => $slug, 'name' => (string) $meta['name'], 'version' => (string) ($meta['version'] ?? ''), 'warnings' => $validation['warnings']];
        }
        $resourceViolation = zipResourceViolation($zip);
        if ($resourceViolation !== null) {
            return ['ok' => false, 'code' => 'resource', 'detail' => $resourceViolation, 'slug' => $slug, 'name' => (string) $meta['name'], 'version' => (string) ($meta['version'] ?? ''), 'warnings' => $validation['warnings']];
        }

        return [
            'ok' => true,
            'code' => '',
            'detail' => '',
            'slug' => $slug,
            'name' => (string) $meta['name'],
            'version' => (string) ($meta['version'] ?? ''),
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
