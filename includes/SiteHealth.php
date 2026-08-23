<?php
/**
 * YikaiCMS site health checks shared by admin and CLI.
 */
declare(strict_types=1);

final class SiteHealth
{
    public const CRITICAL = 'critical';
    public const RECOMMENDED = 'recommended';
    public const GOOD = 'good';
    public const UNKNOWN = 'unknown';

    private const PHP_PROBE_MARKER = 'YIKAI_SITE_HEALTH_PHP_PROBE';

    /** @return list<array<string,mixed>> */
    public static function runDirect(?string $root = null): array
    {
        $root ??= ROOT_PATH;
        $checks = [
            self::checkDebugMode(),
            self::checkInstallLock($root),
            self::checkLegacyInstallScripts($root),
            self::checkPhpVersion(),
            self::checkExtensions(),
            self::checkDatabase(),
            self::checkWritableDirectories($root),
            self::checkConfigPermissions($root),
            self::checkDiskSpace($root),
            self::checkHttps(),
            self::checkAdminPolicy(),
            self::checkUploadPolicy(),
            self::checkFormPolicy(),
            self::checkSessionCookie(),
            self::checkPasswordHashes(),
            self::checkPendingMigrations($root),
            self::checkRecentBackup($root),
        ];

        if (function_exists('apply_filters')) {
            $filtered = apply_filters('site_health_tests', $checks);
            if (is_array($filtered)) {
                $checks = self::normalizeResults($filtered);
            }
        }

        return $checks;
    }

    /** @param array<int,mixed> $results @return list<array<string,mixed>> */
    public static function normalizeResults(array $results): array
    {
        $normalized = [];
        $seen = [];
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }
            $id = trim((string) preg_replace('/[^a-z0-9_.-]+/', '-', strtolower(trim((string) ($result['id'] ?? '')))), '-');
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $status = (string) ($result['status'] ?? self::UNKNOWN);
            if (!in_array($status, [self::CRITICAL, self::RECOMMENDED, self::GOOD, self::UNKNOWN], true)) {
                $status = self::UNKNOWN;
            }
            $seen[$id] = true;
            $normalized[] = [
                'id' => $id,
                'title' => self::truncate(trim((string) ($result['title'] ?? $id)), 160),
                'status' => $status,
                'category' => preg_replace('/[^a-z0-9_.-]/', '', strtolower((string) ($result['category'] ?? 'environment'))) ?: 'environment',
                'description' => self::truncate(trim((string) ($result['description'] ?? '')), 800),
                'action_url' => self::safeAdminUrl((string) ($result['action_url'] ?? '')),
            ];
        }
        return $normalized;
    }

    /** @param list<array<string,mixed>> $results @return array{critical:int,recommended:int,good:int,unknown:int,total:int} */
    public static function summary(array $results): array
    {
        $summary = ['critical' => 0, 'recommended' => 0, 'good' => 0, 'unknown' => 0, 'total' => 0];
        foreach (self::normalizeResults($results) as $result) {
            $summary[$result['status']]++;
            $summary['total']++;
        }
        return $summary;
    }

    /** @return array{nonce:string,probes:list<array{id:string,url:string,method:string}>,checks:list<array<string,mixed>>,storage_file:string,storage_token:string} */
    public static function createBrowserProbes(string $storagePath): array
    {
        self::pruneOldStorageProbes($storagePath);
        $nonce = bin2hex(random_bytes(16));
        $storageToken = bin2hex(random_bytes(16));
        $storageName = 'site-health-probe-' . bin2hex(random_bytes(8)) . '.txt';
        $storageFile = rtrim($storagePath, '/\\') . DIRECTORY_SEPARATOR . $storageName;
        $checks = [];
        $probes = [
            ['id' => 'config_php_web', 'url' => '/config/site-health-probe.php', 'method' => 'GET'],
            ['id' => 'includes_php_web', 'url' => '/includes/site-health-probe.php', 'method' => 'GET'],
            ['id' => 'loopback', 'url' => '/', 'method' => 'GET'],
        ];

        if (@file_put_contents($storageFile, 'YIKAI_STORAGE_CANARY:' . $storageToken, LOCK_EX) === false) {
            $storageFile = '';
            $checks[] = self::result(
                'storage_web', self::UNKNOWN, 'security',
                'health_storage_web_title', 'health_storage_web_unavailable', '/admin/system.php'
            );
        } else {
            $probes[] = ['id' => 'storage_web', 'url' => '/storage/' . rawurlencode($storageName), 'method' => 'GET'];
        }

        return [
            'nonce' => $nonce,
            'probes' => $probes,
            'checks' => $checks,
            'storage_file' => $storageFile,
            'storage_token' => $storageToken,
        ];
    }

    /**
     * @param array<int,mixed> $observations
     * @return list<array<string,mixed>>
     */
    public static function evaluateBrowserProbes(array $observations, string $storageToken): array
    {
        $byId = [];
        foreach ($observations as $observation) {
            if (!is_array($observation)) {
                continue;
            }
            $id = (string) ($observation['id'] ?? '');
            if (!in_array($id, ['config_php_web', 'includes_php_web', 'storage_web', 'loopback'], true)) {
                continue;
            }
            $byId[$id] = [
                'status' => max(0, min(599, (int) ($observation['status'] ?? 0))),
                'body' => substr((string) ($observation['body'] ?? ''), 0, 1024),
                'error' => !empty($observation['error']),
            ];
        }

        $results = [];
        foreach (['config_php_web', 'includes_php_web'] as $id) {
            $title = $id === 'config_php_web' ? 'health_config_web_title' : 'health_includes_web_title';
            $observation = $byId[$id] ?? null;
            if ($observation === null || $observation['error']) {
                $results[] = self::result($id, self::UNKNOWN, 'security', $title, 'health_php_web_unknown');
            } elseif (str_contains($observation['body'], self::PHP_PROBE_MARKER)) {
                $results[] = self::result($id, self::CRITICAL, 'security', $title, 'health_php_web_exposed');
            } elseif (in_array($observation['status'], [403, 404], true)) {
                $results[] = self::result($id, self::GOOD, 'security', $title, 'health_php_web_good');
            } else {
                $results[] = self::result($id, self::RECOMMENDED, 'security', $title, 'health_php_web_unexpected');
            }
        }

        if (isset($byId['storage_web'])) {
            $storage = $byId['storage_web'];
            if ($storage['error']) {
                $results[] = self::result('storage_web', self::UNKNOWN, 'security', 'health_storage_web_title', 'health_storage_web_unknown');
            } elseif ($storageToken !== '' && str_contains($storage['body'], $storageToken)) {
                $results[] = self::result('storage_web', self::CRITICAL, 'security', 'health_storage_web_title', 'health_storage_web_exposed');
            } elseif (in_array($storage['status'], [403, 404], true)) {
                $results[] = self::result('storage_web', self::GOOD, 'security', 'health_storage_web_title', 'health_storage_web_good');
            } else {
                $results[] = self::result('storage_web', self::RECOMMENDED, 'security', 'health_storage_web_title', 'health_storage_web_unexpected');
            }
        }

        $loopback = $byId['loopback'] ?? null;
        if ($loopback === null || $loopback['error'] || $loopback['status'] === 0) {
            $results[] = self::result('loopback', self::UNKNOWN, 'environment', 'health_loopback_title', 'health_loopback_unknown');
        } elseif ($loopback['status'] >= 200 && $loopback['status'] < 400) {
            $results[] = self::result('loopback', self::GOOD, 'environment', 'health_loopback_title', 'health_loopback_good');
        } else {
            $results[] = self::result('loopback', self::RECOMMENDED, 'environment', 'health_loopback_title', 'health_loopback_bad');
        }

        return $results;
    }

    public static function cleanupBrowserProbe(string $storageFile, string $storagePath): void
    {
        if ($storageFile === '') {
            return;
        }
        $base = realpath($storagePath);
        $file = realpath($storageFile);
        if ($base !== false && $file !== false
            && str_starts_with($file, rtrim($base, '/\\') . DIRECTORY_SEPARATOR)
            && preg_match('/^site-health-probe-[a-f0-9]{16}\.txt$/', basename($file)) === 1) {
            @unlink($file);
        }
    }

    /** @return array<string,mixed> */
    public static function checkUpdateService(): array
    {
        $version = defined('CMS_VERSION') ? (string) CMS_VERSION : '0.0.0';
        $query = http_build_query([
            'version' => $version,
            'domain' => (string) ($_SERVER['HTTP_HOST'] ?? ''),
            'site_name' => function_exists('config') ? (string) config('site_name', '') : '',
            'php' => PHP_VERSION,
            't' => time(),
        ]);
        $url = 'https://update.yikaicms.com/api/update/check.php?' . $query;
        $context = stream_context_create([
            'http' => ['timeout' => 8, 'ignore_errors' => true, 'follow_location' => 0, 'header' => "Accept: application/json\r\n"],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $context);
        $statusCode = self::httpStatusCode($http_response_header ?? []);
        if ($body === false || $statusCode < 200 || $statusCode >= 300) {
            $body = false;
        }
        if ($body === false && function_exists('curl_init')) {
            $curl = curl_init($url);
            if ($curl !== false) {
                curl_setopt_array($curl, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 8,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_HTTPHEADER => ['Accept: application/json'],
                ]);
                $response = curl_exec($curl);
                $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
                $body = is_string($response) && $statusCode >= 200 && $statusCode < 300 ? $response : false;
                curl_close($curl);
            }
        }
        $data = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($data)) {
            return self::result('updates', self::UNKNOWN, 'updates', 'health_updates_title', 'health_updates_unknown', '/admin/upgrade_online.php');
        }
        if (!empty($data['has_update'])) {
            $level = strtolower((string) ($data['level'] ?? ''));
            return self::result(
                'updates',
                in_array($level, ['security', 'critical'], true) ? self::CRITICAL : self::RECOMMENDED,
                'updates',
                'health_updates_title',
                'health_updates_available',
                '/admin/upgrade_online.php',
                ['version' => (string) ($data['latest_version'] ?? '')]
            );
        }
        return self::result('updates', self::GOOD, 'updates', 'health_updates_title', 'health_updates_good', '/admin/upgrade_online.php');
    }

    /** @return array<string,mixed> */
    public static function diagnosticInfo(?string $root = null): array
    {
        $root ??= ROOT_PATH;
        $disk = function_exists('disk_free_space') ? @disk_free_space($root) : false;
        $databaseVersion = '';
        try {
            if (defined('DB_DRIVER') && DB_DRIVER === 'mysql') {
                $databaseVersion = (string) (db()->fetchColumn('SELECT VERSION()') ?? '');
            } elseif (defined('DB_DRIVER')) {
                $sqliteVersion = class_exists('SQLite3') ? (string) (SQLite3::version()['versionString'] ?? '') : '';
                $databaseVersion = trim('SQLite ' . $sqliteVersion);
            }
        } catch (Throwable) {
            $databaseVersion = self::t('health_info_unavailable');
        }

        return [
            'cms_version' => defined('CMS_VERSION') ? CMS_VERSION : '',
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'server' => self::truncate((string) ($_SERVER['SERVER_SOFTWARE'] ?? ''), 120),
            'database_driver' => defined('DB_DRIVER') ? DB_DRIVER : '',
            'database_version' => self::truncate($databaseVersion, 120),
            // 站点地址：设置项常年留空（安装器不强制填），真正可信的是 config.php 里
            // 那个常量。只读设置项会让这一行在大多数站上是空的。
            'site_url' => (function (): string {
                $configured = function_exists('config') ? trim((string) config('site_url', '')) : '';
                if ($configured !== '') {
                    return $configured;
                }
                return defined('SITE_URL') ? (string) SITE_URL : '';
            })(),
            'debug' => defined('DEBUG') && DEBUG,
            'disk_free_bytes' => $disk === false ? null : (int) $disk,
            'required_extensions' => self::extensionMap(['pdo', 'json', 'mbstring', 'fileinfo', 'dom', 'simplexml']),
            'recommended_extensions' => self::extensionMap(['gd', 'openssl', 'curl', 'zip']),
        ];
    }

    /** @return array<string,mixed> */
    private static function checkDebugMode(): array
    {
        $display = strtolower(trim((string) ini_get('display_errors')));
        $displayOn = !in_array($display, ['', '0', 'off', 'false'], true);
        $bad = (defined('DEBUG') && DEBUG) || $displayOn;
        return self::result('debug_mode', $bad ? self::CRITICAL : self::GOOD, 'security',
            'health_debug_title', $bad ? 'health_debug_bad' : 'health_debug_good', '/admin/system.php');
    }

    /** @return array<string,mixed> */
    private static function checkInstallLock(string $root): array
    {
        $ok = is_file($root . '/installed.lock');
        return self::result('install_lock', $ok ? self::GOOD : self::CRITICAL, 'security',
            'health_install_lock_title', $ok ? 'health_install_lock_good' : 'health_install_lock_bad');
    }

    /** @return array<string,mixed> */
    private static function checkLegacyInstallScripts(string $root): array
    {
        $found = array_values(array_filter(
            ['install/upgrade.php', 'install/run_upgrade.php'],
            static fn(string $path): bool => is_file($root . '/' . $path)
        ));
        return self::result('legacy_install_scripts', $found === [] ? self::GOOD : self::CRITICAL, 'security',
            'health_legacy_title', $found === [] ? 'health_legacy_good' : 'health_legacy_bad', '',
            ['files' => implode(', ', $found)]);
    }

    /** @return array<string,mixed> */
    private static function checkPhpVersion(): array
    {
        $ok = version_compare(PHP_VERSION, '8.2.0', '>=');
        return self::result('php_version', $ok ? self::GOOD : self::CRITICAL, 'environment',
            'health_php_title', $ok ? 'health_php_good' : 'health_php_bad', '', ['version' => PHP_VERSION]);
    }

    /** @return array<string,mixed> */
    private static function checkExtensions(): array
    {
        $required = ['pdo', 'json', 'mbstring', 'fileinfo', 'dom', 'simplexml'];
        $missing = array_values(array_filter($required, static fn(string $extension): bool => !extension_loaded($extension)));
        return self::result('required_extensions', $missing === [] ? self::GOOD : self::CRITICAL, 'environment',
            'health_extensions_title', $missing === [] ? 'health_extensions_good' : 'health_extensions_bad', '',
            ['extensions' => implode(', ', $missing)]);
    }

    /** @return array<string,mixed> */
    private static function checkDatabase(): array
    {
        try {
            if (db()->isSqlite()) {
                db()->fetchColumn('SELECT 1');
                return self::result('database', self::GOOD, 'environment', 'health_database_title', 'health_database_sqlite');
            }
            $version = (string) db()->fetchColumn('SELECT VERSION()');
            $number = preg_match('/(\d+\.\d+(?:\.\d+)?)/', $version, $match) === 1 ? $match[1] : '0';
            $isMaria = stripos($version, 'mariadb') !== false;
            $supported = $isMaria ? version_compare($number, '10.0', '>=') : version_compare($number, '5.7', '>=');
            return self::result('database', $supported ? self::GOOD : self::RECOMMENDED, 'environment',
                'health_database_title', $supported ? 'health_database_good' : 'health_database_old', '', ['version' => $version]);
        } catch (Throwable) {
            return self::result('database', self::CRITICAL, 'environment', 'health_database_title', 'health_database_bad');
        }
    }

    /** @return array<string,mixed> */
    private static function checkWritableDirectories(string $root): array
    {
        $bad = [];
        foreach (['storage', 'storage/cache', 'storage/logs', 'uploads'] as $relative) {
            $path = $root . '/' . $relative;
            if (!is_dir($path) || !is_writable($path)) {
                $bad[] = $relative;
            }
        }
        return self::result('writable_directories', $bad === [] ? self::GOOD : self::CRITICAL, 'environment',
            'health_writable_title', $bad === [] ? 'health_writable_good' : 'health_writable_bad', '/admin/system.php',
            ['directories' => implode(', ', $bad)]);
    }

    /** @return array<string,mixed> */
    private static function checkConfigPermissions(string $root): array
    {
        $path = $root . '/config/config.php';
        $writable = is_file($path) && is_writable($path);
        return self::result('config_permissions', $writable ? self::RECOMMENDED : self::GOOD, 'security',
            'health_config_permissions_title', $writable ? 'health_config_permissions_bad' : 'health_config_permissions_good');
    }

    /** @return array<string,mixed> */
    private static function checkDiskSpace(string $root): array
    {
        $free = function_exists('disk_free_space') ? @disk_free_space($root) : false;
        if ($free === false) {
            return self::result('disk_space', self::UNKNOWN, 'environment', 'health_disk_title', 'health_disk_unknown');
        }
        $status = $free < 100 * 1024 * 1024 ? self::CRITICAL : ($free < 500 * 1024 * 1024 ? self::RECOMMENDED : self::GOOD);
        return self::result('disk_space', $status, 'environment', 'health_disk_title',
            $status === self::GOOD ? 'health_disk_good' : 'health_disk_low', '', ['space' => self::formatBytes((int) $free)]);
    }

    /** @return array<string,mixed> */
    private static function checkHttps(): array
    {
        $url = function_exists('config') ? trim((string) config('site_url', '')) : '';
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($url === '' || $host === '') {
            return self::result('https', self::UNKNOWN, 'security', 'health_https_title', 'health_https_unknown', '/admin/setting.php');
        }
        $local = $host === 'localhost' || str_ends_with($host, '.test') || str_ends_with($host, '.local')
            || str_ends_with($host, '.yikai') || filter_var($host, FILTER_VALIDATE_IP) !== false;
        $https = strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
        return self::result('https', ($https || $local) ? self::GOOD : self::RECOMMENDED, 'security',
            'health_https_title', ($https || $local) ? 'health_https_good' : 'health_https_bad', '/admin/setting.php');
    }

    /** @return array<string,mixed> */
    private static function checkAdminPolicy(): array
    {
        $attempts = (int) config('login_max_attempts', '5');
        $password = (int) config('password_min_length', '6');
        $timeout = (int) config('session_timeout', '30');
        $ok = $attempts > 0 && $attempts <= 5 && $password >= 10 && $timeout > 0 && $timeout <= 120;
        return self::result('admin_policy', $ok ? self::GOOD : self::RECOMMENDED, 'security',
            'health_admin_policy_title', $ok ? 'health_admin_policy_good' : 'health_admin_policy_bad', '/admin/setting_security.php',
            ['attempts' => (string) $attempts, 'password' => (string) $password, 'timeout' => (string) $timeout]);
    }

    /** @return array<string,mixed> */
    private static function checkUploadPolicy(): array
    {
        $types = strtolower((string) config('upload_image_types', '') . ',' . (string) config('upload_file_types', ''));
        $allowed = array_filter(array_map('trim', explode(',', $types)));
        $dangerous = array_values(array_intersect($allowed, ['php', 'php3', 'php4', 'php5', 'phtml', 'pht', 'phar', 'cgi', 'pl', 'py', 'sh', 'shtml', 'html', 'htm', 'js']));
        return self::result('upload_policy', $dangerous === [] ? self::GOOD : self::CRITICAL, 'security',
            'health_upload_title', $dangerous === [] ? 'health_upload_good' : 'health_upload_bad', '/admin/setting_security.php?tab=upload',
            ['extensions' => implode(', ', $dangerous)]);
    }

    /** @return array<string,mixed> */
    private static function checkFormPolicy(): array
    {
        $version = (string) config('form_security_version', '1');
        $maxAge = (int) config('form_signature_max_age', '0');
        $static = (string) config('static_html_enabled', '0') === '1';
        if ($static && $maxAge > 0) {
            return self::result('form_policy', self::CRITICAL, 'security', 'health_form_title', 'health_form_static_expiry', '/admin/setting_security.php');
        }
        return self::result('form_policy', $version === '2' ? self::GOOD : self::RECOMMENDED, 'security',
            'health_form_title', $version === '2' ? 'health_form_good' : 'health_form_compat', '/admin/setting_security.php');
    }

    /** @return array<string,mixed> */
    private static function checkSessionCookie(): array
    {
        if (PHP_SAPI === 'cli') {
            return self::result('session_cookie', self::UNKNOWN, 'security', 'health_cookie_title', 'health_cookie_cli');
        }
        $params = session_get_cookie_params();
        $https = strtolower((string) parse_url((string) config('site_url', ''), PHP_URL_SCHEME)) === 'https';
        if (empty($params['httponly'])) {
            return self::result('session_cookie', self::CRITICAL, 'security', 'health_cookie_title', 'health_cookie_httponly_bad');
        }
        if ($https && empty($params['secure'])) {
            return self::result('session_cookie', self::RECOMMENDED, 'security', 'health_cookie_title', 'health_cookie_secure_bad');
        }
        return self::result('session_cookie', self::GOOD, 'security', 'health_cookie_title', 'health_cookie_good');
    }

    /** @return array<string,mixed> */
    private static function checkPasswordHashes(): array
    {
        try {
            $hashes = db()->fetchAll('SELECT password FROM ' . DB_PREFIX . 'users');
            $stale = 0;
            foreach ($hashes as $row) {
                $hash = (string) ($row['password'] ?? '');
                if ($hash === '' || password_needs_rehash($hash, PASSWORD_DEFAULT)) {
                    $stale++;
                }
            }
            return self::result('password_hashes', $stale === 0 ? self::GOOD : self::RECOMMENDED, 'security',
                'health_password_hash_title', $stale === 0 ? 'health_password_hash_good' : 'health_password_hash_bad', '/admin/user.php',
                ['count' => (string) $stale]);
        } catch (Throwable) {
            return self::result('password_hashes', self::UNKNOWN, 'security', 'health_password_hash_title', 'health_password_hash_unknown');
        }
    }

    /** @return array<string,mixed> */
    private static function checkPendingMigrations(string $root): array
    {
        try {
            require_once $root . '/includes/Migrator.php';
            $pending = 0;
            foreach (Migrator::loadAll() as $migration) {
                if (!Migrator::isApplied($migration)) {
                    $pending++;
                }
            }
            return self::result('pending_migrations', $pending === 0 ? self::GOOD : self::CRITICAL, 'updates',
                'health_migrations_title', $pending === 0 ? 'health_migrations_good' : 'health_migrations_bad', '/admin/upgrade.php',
                ['count' => (string) $pending]);
        } catch (Throwable) {
            return self::result('pending_migrations', self::UNKNOWN, 'updates', 'health_migrations_title', 'health_migrations_unknown', '/admin/upgrade.php');
        }
    }

    /** @return array<string,mixed> */
    private static function checkRecentBackup(string $root): array
    {
        $files = array_merge(
            glob($root . '/storage/backups/*.sql') ?: [],
            glob($root . '/storage/backups/*/database.sql') ?: []
        );
        $latest = 0;
        foreach ($files as $file) {
            $latest = max($latest, (int) @filemtime($file));
        }
        $recent = $latest > time() - 14 * 86400;
        return self::result('recent_backup', $recent ? self::GOOD : self::RECOMMENDED, 'operations',
            'health_backup_title', $recent ? 'health_backup_good' : 'health_backup_bad', '/admin/database.php?tab=backup');
    }

    /**
     * @param array<string,string> $params
     * @return array<string,mixed>
     */
    private static function result(
        string $id,
        string $status,
        string $category,
        string $titleKey,
        string $descriptionKey,
        string $actionUrl = '',
        array $params = []
    ): array {
        return [
            'id' => $id,
            'title' => self::t($titleKey),
            'status' => $status,
            'category' => $category,
            'description' => self::t($descriptionKey, $params),
            'action_url' => self::safeAdminUrl($actionUrl),
        ];
    }

    /**
     * 把 diagnosticInfo() 的一项渲染成人能读的一行。
     *
     * 页面与「复制给技术支持」共用这一个实现——两处各写一份，早晚有一处继续吐
     * `{"pdo":true,...}` 这种给机器看的东西。技术支持要的是「哪个缺了」，不是 JSON。
     */
    public static function formatDiagnosticValue(string $key, mixed $value): string
    {
        if (is_array($value)) {
            $on  = array_keys(array_filter($value));
            $off = array_keys(array_filter($value, static fn($enabled): bool => !$enabled));
            $parts = [];
            if ($on !== []) {
                $parts[] = self::t('health_info_ext_enabled', ['list' => implode('、', $on)]);
            }
            if ($off !== []) {
                $parts[] = self::t('health_info_ext_missing', ['list' => implode('、', $off)]);
            }
            return $parts === [] ? self::t('health_info_unavailable') : implode('　', $parts);
        }
        if (is_bool($value)) {
            return self::t($value ? 'yes' : 'no');
        }
        if ($key === 'disk_free_bytes' && is_int($value)) {
            return self::formatBytes($value);
        }
        $text = trim((string) ($value ?? ''));
        return $text === '' ? self::t('health_info_unavailable') : $text;
    }

    /** @param array<string,string> $params */
    private static function t(string $key, array $params = []): string
    {
        return function_exists('__') ? (string) __($key, $params) : $key;
    }

    private static function safeAdminUrl(string $url): string
    {
        return preg_match('#^/admin/[a-z0-9_.-]+\.php(?:\?[a-z0-9_.=&-]+)?$#i', $url) === 1 ? $url : '';
    }

    /** @param list<string> $headers */
    private static function httpStatusCode(array $headers): int
    {
        $status = 0;
        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }
        return $status;
    }

    private static function truncate(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
    }

    /** @param list<string> $extensions @return array<string,bool> */
    private static function extensionMap(array $extensions): array
    {
        $map = [];
        foreach ($extensions as $extension) {
            $map[$extension] = extension_loaded($extension);
        }
        return $map;
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        foreach ($units as $unit) {
            if ($value < 1024 || $unit === 'TB') {
                return number_format($value, $value >= 10 ? 0 : 1) . ' ' . $unit;
            }
            $value /= 1024;
        }
        return $bytes . ' B';
    }

    private static function pruneOldStorageProbes(string $storagePath): void
    {
        if (!is_dir($storagePath)) {
            return;
        }
        $cutoff = time() - 3600;
        $scanned = 0;
        foreach (new DirectoryIterator($storagePath) as $entry) {
            if ($entry->isFile()
                && preg_match('/^site-health-probe-[a-f0-9]{16}\.txt$/', $entry->getFilename()) === 1) {
                if ($entry->getMTime() < $cutoff) {
                    @unlink($entry->getPathname());
                }
                $scanned++;
                if ($scanned >= 100) {
                    break;
                }
            }
        }
    }
}
