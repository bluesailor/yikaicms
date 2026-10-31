<?php

declare(strict_types=1);

/** 已安装站点的私有配置桥接；只供受信后台准备，不是 HTTP 入口。 */
final class AiAccessBootstrapConfig
{
    private const RELATIVE_PATH = '/storage/security/ai-access/bootstrap.json';
    private const DATABASE_KEYS = ['DB_DRIVER', 'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_CHARSET', 'DB_PATH', 'DB_PREFIX'];
    private const POLICY_KEYS = ['ai_access_enabled', 'demo_mode', 'support_access_user_id'];
    /** @var array{root:string,sources:array<string,string|null>}|null */
    private static ?array $preparation = null;

    /** 正常准备入口在加载私有配置前调用；避免把旧常量绑定到后来改变的文件。 */
    public static function beginPreparation(string $root): void
    {
        foreach (self::DATABASE_KEYS as $key) {
            if (defined($key)) throw new RuntimeException('ai_access_prepare_order_invalid', 503);
        }
        $root = self::root($root);
        self::$preparation = ['root' => $root, 'sources' => self::fingerprints($root)];
    }

    public static function prepare(string $root): void
    {
        $root = self::root($root);
        $source = realpath($root . '/config/config.php');
        $included = array_map('realpath', get_included_files());
        if (!defined('ROOT_PATH') || self::root(ROOT_PATH) !== $root || $source === false || !in_array($source, $included, true)
            || self::$preparation === null || self::$preparation['root'] !== $root) {
            throw new RuntimeException('ai_access_prepare_context_required', 503);
        }
        if (self::$preparation['sources'] !== self::fingerprints($root)) throw new RuntimeException('ai_access_prepare_source_changed', 503);
        $database = [];
        foreach (self::DATABASE_KEYS as $key) {
            if (!defined($key)) throw new RuntimeException('ai_access_config_incomplete', 503);
            $value = constant($key);
            $database[$key] = $key === 'DB_PORT' && is_int($value) ? (string) $value : $value;
        }
        self::validateDatabase($database);
        $overrides = [];
        if (is_file($root . '/config/overrides.php')) {
            if (!function_exists('configOverrides')) throw new RuntimeException('ai_access_overrides_not_loaded', 503);
            $all = configOverrides();
            if (!is_array($all)) throw new RuntimeException('ai_access_config_invalid', 503);
            $overrides = array_intersect_key($all, array_flip(self::POLICY_KEYS));
        }
        self::validateOverrides($overrides);
        $data = [
            'version' => 1, 'root' => $root, 'sources' => self::$preparation['sources'],
            'database' => $database, 'overrides' => $overrides,
            'demo' => (defined('DEMO_MODE') && (bool) DEMO_MODE) || (defined('DEMO_SANDBOX') && (bool) DEMO_SANDBOX),
        ];
        $path = $root . self::RELATIVE_PATH;
        $directory = dirname($path);
        $oldMask = umask(0077);
        $temporary = null;
        try {
            if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('ai_access_config_unwritable', 503);
            }
            $resolved = realpath($directory);
            if ($resolved === false || !str_starts_with(str_replace('\\', '/', $resolved) . '/', $root . '/')) {
                throw new RuntimeException('ai_access_config_location_invalid', 503);
            }
            $temporary = $directory . '/.bootstrap-' . bin2hex(random_bytes(12)) . '.tmp';
            $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $handle = @fopen($temporary, 'x+b');
            if ($handle === false) throw new RuntimeException('ai_access_config_unwritable', 503);
            try {
                if (!@chmod($temporary, 0600) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                    throw new RuntimeException('ai_access_config_unwritable', 503);
                }
            } finally {
                fclose($handle);
            }
            if (self::$preparation['sources'] !== self::fingerprints($root)) throw new RuntimeException('ai_access_prepare_source_changed', 503);
            if (!@rename($temporary, $path)) throw new RuntimeException('ai_access_config_unwritable', 503);
        } finally {
            if ($temporary !== null && is_file($temporary)) @unlink($temporary);
            umask($oldMask);
        }
    }

    /** @return array{database:array<string,string>,overrides:array<string,string>,demo:bool} */
    public static function read(string $root): array
    {
        $root = self::root($root);
        $path = $root . self::RELATIVE_PATH;
        $size = @filesize($path);
        if ($size === false || $size < 1 || $size > 65536 || is_link($path)) {
            throw new RuntimeException('ai_access_config_not_prepared', 503);
        }
        $raw = @file_get_contents($path);
        try {
            $data = $raw === false ? null : json_decode($raw, true, 12, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('ai_access_config_invalid', 503);
        }
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || ($data['root'] ?? null) !== $root
            || ($data['sources'] ?? null) !== self::fingerprints($root) || !is_bool($data['demo'] ?? null)
            || !is_array($data['database'] ?? null) || !is_array($data['overrides'] ?? null)) {
            throw new RuntimeException('ai_access_config_stale_or_invalid', 503);
        }
        self::validateDatabase($data['database']);
        self::validateOverrides($data['overrides']);
        return ['database' => $data['database'], 'overrides' => $data['overrides'], 'demo' => $data['demo']];
    }

    private static function root(string $root): string
    {
        $resolved = realpath($root);
        if ($resolved === false || !is_dir($resolved)) throw new RuntimeException('ai_access_root_invalid', 503);
        return str_replace('\\', '/', $resolved);
    }

    /** @return array<string,string|null> */
    private static function fingerprints(string $root): array
    {
        $out = [];
        foreach (['config/config.php', 'config/overrides.php'] as $file) {
            $exists = is_file($root . '/' . $file);
            $hash = $exists ? @hash_file('sha256', $root . '/' . $file) : null;
            if (($file === 'config/config.php' && !$exists) || $hash === false) {
                throw new RuntimeException('ai_access_config_unreadable', 503);
            }
            $out[$file] = $hash;
        }
        return $out;
    }

    private static function validateDatabase(array $database): void
    {
        $keys = array_keys($database);
        sort($keys);
        $expected = self::DATABASE_KEYS;
        sort($expected);
        if ($keys !== $expected) throw new RuntimeException('ai_access_config_invalid', 503);
        foreach ($database as $value) {
            if (!is_string($value) || strlen($value) > 8192 || str_contains($value, "\0")) {
                throw new RuntimeException('ai_access_config_invalid', 503);
            }
        }
        if (!in_array($database['DB_DRIVER'], ['mysql', 'sqlite'], true)
            || preg_match('/^[a-zA-Z0-9_]{0,40}$/D', $database['DB_PREFIX']) !== 1
            || preg_match('/^[a-zA-Z0-9_]+$/D', $database['DB_CHARSET']) !== 1
            || !ctype_digit((string) $database['DB_PORT']) || (int) $database['DB_PORT'] < 1 || (int) $database['DB_PORT'] > 65535
            || ($database['DB_DRIVER'] === 'sqlite' && $database['DB_PATH'] === '')) {
            throw new RuntimeException('ai_access_config_invalid', 503);
        }
    }

    private static function validateOverrides(array $overrides): void
    {
        foreach ($overrides as $key => $value) {
            if (!in_array($key, self::POLICY_KEYS, true) || !is_string($value)) {
                throw new RuntimeException('ai_access_config_invalid', 503);
            }
        }
    }
}
