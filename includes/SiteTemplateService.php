<?php
declare(strict_types=1);

require_once __DIR__ . '/SiteTemplateArchive.php';
require_once __DIR__ . '/ThemeInstaller.php';

/** Local, explicit, new-install-only site transfer. Never restores accounts or server configuration. */
final class SiteTemplateService
{
    private string $root;
    private string $store;
    private const GUARD = "<?php http_response_code(404); exit; ?>\n";
    private const PRIVATE_TABLES = ['forms', 'members', 'mail_log', 'content_revisions', 'blox_page_drafts'];

    public function __construct(string $root)
    {
        $resolved = realpath($root);
        if ($resolved === false) throw new RuntimeException('st_storage');
        $this->root = str_replace('\\', '/', $resolved);
        $this->store = $this->root . '/storage/site-templates';
    }

    /** Only the installer calls this, before creating installed.lock. No administrator reset endpoint. */
    public function markFreshInstall(): void
    {
        if (file_exists($this->root . '/installed.lock') || $this->readRecord('baseline') !== null) throw new RuntimeException('st_not_fresh');
        $this->writeRecord('baseline', ['fingerprint' => SiteTemplateData::fingerprint(), 'created_at' => time()]);
    }

    public function canApply(): bool
    {
        $baseline = $this->readRecord('baseline');
        return $baseline !== null && hash_equals((string) $baseline['fingerprint'], SiteTemplateData::fingerprint());
    }

    public function recovery(): ?array
    {
        $record = $this->readRecord('current');
        if ($record === null) return null;
        return ['status' => (string) $record['status'], 'created_at' => (int) $record['created_at'],
            'can_restore' => isset($record['after']) && hash_equals((string) $record['after'], SiteTemplateData::fingerprint())
                && !in_array($record['status'], ['restored', 'aborted'], true)];
    }

    private function supported(): void
    {
        if ((function_exists('configOverrides') && configOverrides() !== []) || !empty($GLOBALS['yikai_config_runtime_overrides'])) throw new RuntimeException('st_overrides');
        if (db()->tableExists('plugins') && (int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'plugins WHERE status = ?', [1]) > 0) throw new RuntimeException('st_plugins');
        $overrides = $this->root . '/overrides';
        if (is_dir($overrides)) {
            $this->assertContained($overrides);
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($overrides, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) if ($file->isFile() && !in_array($file->getFilename(), ['README.md', '.gitkeep', '.htaccess'], true)) throw new RuntimeException('st_overrides');
        }
    }

    /** Writes to a caller-owned temporary file. Only referenced public uploads are bundled. */
    public function export(string $destination): array
    {
        $this->supported();
        $before = SiteTemplateData::fingerprint();
        $theme = (string) config('current_theme', 'default');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $theme)) throw new RuntimeException('st_theme');
        $themeRoot = $this->root . '/themes/' . $theme;
        $this->assertContained($themeRoot);
        if (!is_dir($themeRoot)) throw new RuntimeException('st_theme');
        $files = [];
        $size = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($themeRoot, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            $relative = substr($path, strlen($themeRoot) + 1);
            if (preg_match('~(?:^|/)\.~', $relative)) continue;
            $this->assertContained($path);
            if (!$file->isFile() || !SiteTemplateArchive::safePath($relative)) throw new RuntimeException('st_unsafe');
            $files['theme/' . $relative] = $this->boundedRead($path, $size);
        }
        $data = SiteTemplateData::snapshot(true);
        // Normalize the author's own origin, not third-party links.
        $origin = rtrim((string) config('site_url', ''), '/');
        if ($origin !== '' && preg_match('~^https?://[^/]+$~iD', $origin)) {
            $data = SiteTemplateArchive::rewrite($data, [$origin . '/' => '/']);
            foreach ($files as $path => $bytes) if ($this->textFile($path)) $files[$path] = SiteTemplateArchive::rewrite($bytes, [$origin . '/' => '/']);
        }
        $media = $data['tables']['media'];
        $data['tables']['media'] = [];
        $refs = [];
        $this->collectUploads($data, $refs);
        foreach ($files as $path => $bytes) if ($this->textFile($path)) $this->collectUploads($bytes, $refs);
        foreach (array_keys($refs) as $relative) {
            if (!SiteTemplateArchive::safePath($relative)) throw new RuntimeException('st_unsafe');
            $source = $this->root . '/uploads/' . $relative;
            $this->assertContained($source);
            if (!is_file($source)) throw new RuntimeException('st_missing_media');
            $files['media/' . $relative] = $this->boundedRead($source, $size);
        }
        foreach ($media as $row) {
            $rowRefs = [];
            $this->collectUploads([$row['url'] ?? '', $row['path'] ?? ''], $rowRefs);
            $publicRefs = array_intersect_key($refs, $rowRefs);
            if ($publicRefs) {
                $relative = (string) array_key_first($publicRefs);
                $row['path'] = 'uploads/' . $relative;
                $row['url'] = '/uploads/' . $relative;
                $data['tables']['media'][] = $row;
            }
        }
        SiteTemplateData::validate($data);
        if (!hash_equals($before, SiteTemplateData::fingerprint())) throw new RuntimeException('st_stale');
        $manifest = ['format' => 'yikaicms-site-template', 'version' => 1, 'cms' => CMS_VERSION,
            'schema' => SiteTemplateData::schema(), 'theme' => $theme, 'created_at' => gmdate('c'), 'data' => $data];
        SiteTemplateArchive::write($destination, $manifest, $files);
        SiteTemplateArchive::read($destination);
        return $this->summary($manifest, $files);
    }

    public function prepare(string $archive, int $adminId): array
    {
        $this->supported();
        if (!$this->canApply()) throw new RuntimeException('st_not_fresh');
        $package = SiteTemplateArchive::read($archive);
        $token = bin2hex(random_bytes(16));
        // A single pending preview bounds disk use; another preview explicitly invalidates the old token.
        $this->writeRecord('plan', ['token' => $token, 'owner' => $adminId, 'expires' => time() + 600,
            'fingerprint' => SiteTemplateData::fingerprint(), 'zip' => base64_encode((string) file_get_contents($archive))]);
        return ['token' => $token, 'summary' => $this->summary($package['manifest'], $package['files'])];
    }

    public function apply(string $token, int $adminId, array $brand, bool $trusted): void
    {
        if (!$trusted) throw new RuntimeException('st_trust');
        $this->withLock(function () use ($token, $adminId, $brand): void {
            $this->supported();
            $plan = $this->readRecord('plan');
            if ($plan === null || !hash_equals((string) $plan['token'], $token) || $plan['owner'] !== $adminId || $plan['expires'] < time()) throw new RuntimeException('st_stale');
            if (!$this->canApply() || !hash_equals((string) $plan['fingerprint'], SiteTemplateData::fingerprint())) throw new RuntimeException('st_not_fresh');
            $archive = $this->temporaryFile();
            try {
                if (file_put_contents($archive, base64_decode($plan['zip'], true)) === false) throw new RuntimeException('st_storage');
                $package = SiteTemplateArchive::read($archive);
            } finally { @unlink($archive); }
            $alias = 'sitepack-' . bin2hex(random_bytes(8));
            $map = ['/themes/' . $package['manifest']['theme'] . '/' => '/themes/' . $alias . '/', '/uploads/' => '/uploads/' . $alias . '/'];
            $data = SiteTemplateArchive::rewrite($package['manifest']['data'], $map);
            foreach ($data['tables']['media'] as &$row) {
                if (isset($row['path']) && str_starts_with($row['path'], 'uploads/')) $row['path'] = $this->root . '/uploads/' . $alias . '/' . substr($row['path'], 8);
            }
            unset($row);
            $oldTheme = $package['manifest']['theme'];
            $styles = json_decode($data['settings']['theme_style_settings'] ?? '', true);
            if (is_array($styles['themes'][$oldTheme] ?? null)) {
                $styles['themes'] = [$alias => $styles['themes'][$oldTheme]];
                $data['settings']['theme_style_settings'] = json_encode($styles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
            if (isset($data['settings']['theme_content_' . $oldTheme])) {
                $data['settings']['theme_content_' . $alias] = $data['settings']['theme_content_' . $oldTheme];
                unset($data['settings']['theme_content_' . $oldTheme]);
            }
            $data['settings']['current_theme'] = $alias;
            foreach (['site_name', 'contact_phone', 'contact_email', 'contact_address'] as $key) {
                $value = trim((string) ($brand[$key] ?? ''));
                if (strlen($value) > 500 || ($key === 'site_name' && $value === '')) throw new RuntimeException('st_brand');
                $data['settings'][$key] = $value;
                foreach (['zh-CN', 'en', 'ja'] as $language) {
                    if (array_key_exists($key . '_' . $language, $data['settings'])) $data['settings'][$key . '_' . $language] = $value;
                }
            }
            if ($data['settings']['contact_email'] !== '' && filter_var($data['settings']['contact_email'], FILTER_VALIDATE_EMAIL) === false) throw new RuntimeException('st_brand');
            $this->beginLockedTransaction();
            $committed = false;
            try {
                if (!$this->canApply() || !hash_equals((string) $plan['fingerprint'], SiteTemplateData::fingerprint())) throw new RuntimeException('st_stale');
                $journal = ['status' => 'preparing', 'created_at' => time(), 'before' => SiteTemplateData::fingerprint(),
                    'snapshot' => SiteTemplateData::snapshot(), 'alias' => $alias];
                $this->writeRecord('current', $journal);
                $this->installFiles($package['files'], $alias, $map);
                SiteTemplateData::replace($data);
                $journal['after'] = SiteTemplateData::fingerprint();
                $journal['status'] = 'prepared_commit';
                $this->writeRecord('current', $journal);
                db()->commit();
                $committed = true;
                $journal['status'] = 'committed';
                $this->writeRecord('current', $journal);
                $this->writeRecord('plan', ['token' => '', 'owner' => 0, 'expires' => 0]);
            } catch (Throwable $e) {
                if (!$committed && db()->getPdo()->inTransaction()) db()->rollback();
                settingModel()->clearCache();
                // 事务已回滚 ⇒ 没有任何记录引用本次别名（别名是本次新生成的随机值，
                // installFiles 又拒绝写入已存在的目录），因此清理是安全的。
                // restore() 里"绝不删除"的理由是那些文件可能已被编辑器引用——失败路径不适用。
                if (!$committed) $this->discardImportedFiles($alias);
                throw $e;
            }
        });
    }

    public function restore(): void
    {
        $this->withLock(function (): void {
            $this->supported();
            $journal = $this->readRecord('current');
            if ($journal === null || !isset($journal['after']) || in_array($journal['status'], ['restored', 'aborted'], true)) throw new RuntimeException('st_no_restore');
            $this->beginLockedTransaction();
            try {
                if (!hash_equals((string) $journal['after'], SiteTemplateData::fingerprint())) throw new RuntimeException('st_restore_changed');
                // A local backup must reproduce the original state, including pre-existing dangling seed references.
                // Uploaded packages still undergo full reference validation in Archive::read and replace().
                SiteTemplateData::replace($journal['snapshot'], false);
                if (!hash_equals((string) $journal['before'], SiteTemplateData::fingerprint())) throw new RuntimeException('st_restore_changed');
                db()->commit();
            } catch (Throwable $e) {
                if (db()->getPdo()->inTransaction()) db()->rollback();
                settingModel()->clearCache();
                throw $e;
            }
            $journal['status'] = 'restored';
            $this->writeRecord('current', $journal);
            // Imported files remain inactive. Never delete a file another editor may have started using.
        });
    }

    /**
     * 清理一次失败导入留下的文件。只接受本类生成的随机别名形态，避免任何误删可能；
     * 删除失败只记日志，不能掩盖触发回滚的原始异常。
     */
    private function discardImportedFiles(string $alias): void
    {
        if (preg_match('/^sitepack-[0-9a-f]{16}$/D', $alias) !== 1) return;
        foreach ([$this->root . '/themes/' . $alias, $this->root . '/uploads/' . $alias] as $directory) {
            try {
                if (!is_dir($directory)) continue;
                $this->assertContained($directory);
                $entries = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST
                );
                foreach ($entries as $entry) {
                    if ($entry->isLink() || $entry->isFile()) @unlink($entry->getPathname());
                    elseif ($entry->isDir()) @rmdir($entry->getPathname());
                }
                @rmdir($directory);
            } catch (Throwable $cleanup) {
                error_log('[SiteTemplateService] orphan cleanup skipped for ' . $alias . ': ' . $cleanup->getMessage());
            }
        }
    }

    private function beginLockedTransaction(): void
    {
        $tables = array_merge(SiteTemplateData::TABLES, ['settings'], self::PRIVATE_TABLES);
        if (!db()->isSqlite()) foreach ($tables as $table) {
            if (!db()->tableExists($table)) continue;
            $engine = db()->fetchColumn('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [DB_PREFIX . $table]);
            if (strtolower((string) $engine) !== 'innodb') throw new RuntimeException('st_engine');
        }
        // Full-range locking also protects empty tables against concurrent submissions.
        if (!db()->isSqlite()) db()->execute('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        db()->beginTransaction();
        try {
            if (!db()->isSqlite()) foreach ($tables as $table) {
                if (db()->tableExists($table)) db()->fetchAll('SELECT * FROM ' . DB_PREFIX . $table . ' FOR UPDATE');
            }
        } catch (Throwable $e) { db()->rollback(); throw $e; }
    }

    private function installFiles(array $files, string $alias, array $map): void
    {
        if (file_exists($this->root . '/themes/' . $alias) || file_exists($this->root . '/uploads/' . $alias)) throw new RuntimeException('st_storage');
        $temp = $this->temporaryFile();
        try {
            $zip = new ZipArchive();
            if ($zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('st_storage');
            try {
                foreach ($files as $path => $bytes) {
                    if (!str_starts_with($path, 'theme/')) continue;
                    if ($this->textFile($path)) $bytes = SiteTemplateArchive::rewrite($bytes, $map);
                    if (!$zip->addFromString($alias . '/' . substr($path, 6), $bytes)) throw new RuntimeException('st_storage');
                }
            } finally { $zip->close(); }
            $this->assertContained($this->root . '/themes');
            $result = (new ThemeInstaller($this->root . '/themes', $this->root . '/storage'))->install($temp, $alias);
            if (!$result['ok']) throw new RuntimeException('st_theme');
        } finally { @unlink($temp); }
        foreach ($files as $path => $bytes) {
            if (!str_starts_with($path, 'media/')) continue;
            $target = $this->root . '/uploads/' . $alias . '/' . substr($path, 6);
            $this->ensureDirectory(dirname($target));
            $this->assertContained($target);
            $handle = fopen($target, 'xb');
            if ($handle === false) throw new RuntimeException('st_storage');
            try { if (fwrite($handle, $bytes) !== strlen($bytes)) throw new RuntimeException('st_storage'); }
            finally { fclose($handle); }
        }
    }

    private function summary(array $manifest, array $files): array
    {
        return ['theme' => $manifest['theme'], 'cms' => $manifest['cms'],
            'channels' => count($manifest['data']['tables']['channels']), 'contents' => count($manifest['data']['tables']['contents']),
            'products' => count($manifest['data']['tables']['products']), 'forms' => count($manifest['data']['tables']['form_templates']),
            'media' => count(array_filter(array_keys($files), static fn(string $key): bool => str_starts_with($key, 'media/')))];
    }

    private function collectUploads(mixed $value, array &$refs): void
    {
        if (is_array($value)) { foreach ($value as $item) $this->collectUploads($item, $refs); return; }
        if (!is_string($value)) return;
        $decoded = json_decode($value, true);
        if (is_array($decoded)) { $this->collectUploads($decoded, $refs); return; }
        preg_match_all('~(?<![a-zA-Z0-9/:.])/?uploads/([^\s"\'<>?#)]+)~u', html_entity_decode($value, ENT_QUOTES, 'UTF-8'), $matches);
        foreach ($matches[1] as $path) $refs[rawurldecode($path)] = true;
    }

    private function textFile(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['php', 'css', 'js', 'json', 'svg'], true);
    }

    private function boundedRead(string $path, int &$total): string
    {
        $length = filesize($path);
        if ($length === false || $length > SiteTemplateArchive::MAX_FILE || $total + $length > SiteTemplateArchive::MAX_TOTAL) throw new RuntimeException('st_limit');
        $bytes = file_get_contents($path);
        if ($bytes === false) throw new RuntimeException('st_storage');
        $total += strlen($bytes);
        return $bytes;
    }

    private function temporaryFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'yk-site-');
        if ($path === false) throw new RuntimeException('st_storage');
        return $path;
    }

    /** Reject links at every existing component, including Windows junctions resolving outside the root. */
    private function assertContained(string $path): void
    {
        $path = str_replace('\\', '/', $path);
        if (!str_starts_with($path, $this->root . '/')) throw new RuntimeException('st_unsafe');
        $cursor = $path;
        while ($cursor !== $this->root) {
            if (is_link($cursor)) throw new RuntimeException('st_unsafe');
            if (file_exists($cursor)) {
                $real = realpath($cursor);
                if ($real === false || strtolower(str_replace('\\', '/', $real)) !== strtolower($cursor)) throw new RuntimeException('st_unsafe');
            }
            $parent = str_replace('\\', '/', dirname($cursor));
            if ($parent === $cursor) throw new RuntimeException('st_unsafe');
            $cursor = $parent;
        }
    }

    private function ensureDirectory(string $path): void
    {
        $this->assertContained($path);
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) throw new RuntimeException('st_storage');
    }

    private function recordPath(string $name): string
    {
        if (!in_array($name, ['baseline', 'plan', 'current'], true)) throw new RuntimeException('st_unsafe');
        $path = $this->store . '/' . $name . '.php';
        $this->assertContained($path);
        return $path;
    }

    private function readRecord(string $name): ?array
    {
        $path = $this->recordPath($name);
        if (!is_file($path)) return null;
        $raw = file_get_contents($path);
        if (!is_string($raw) || !str_starts_with($raw, self::GUARD)) throw new RuntimeException('st_storage');
        $value = json_decode(substr($raw, strlen(self::GUARD)), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new RuntimeException('st_storage');
        return $value;
    }

    private function writeRecord(string $name, array $data): void
    {
        $this->ensureDirectory($this->store);
        $target = $this->recordPath($name);
        $temporary = $this->store . '/write-' . bin2hex(random_bytes(16)) . '.php';
        $bytes = self::GUARD . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        try {
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes) || !rename($temporary, $target)) throw new RuntimeException('st_storage');
        } finally { if (is_file($temporary)) @unlink($temporary); }
    }

    private function withLock(callable $action): void
    {
        $this->ensureDirectory($this->store);
        $path = $this->store . '/operation.lock';
        $this->assertContained($path);
        $handle = fopen($path, 'c');
        if ($handle === false) throw new RuntimeException('st_storage');
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) throw new RuntimeException('st_busy');
            $action();
        } finally { flock($handle, LOCK_UN); fclose($handle); }
    }
}
