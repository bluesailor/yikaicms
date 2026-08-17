<?php
/**
 * Yikai CMS — PHPUnit bootstrap.
 *
 * Boots a SQLite in-memory database that mimics the production schema
 * (without DB_PREFIX, kept empty for tests). Defines the constants the
 * Database singleton expects, then loads the Composer autoloader so test
 * classes under Yikai\Tests\* and the production model classes resolve.
 *
 * The Database is a singleton — once getInstance() runs in this process
 * it caches the in-memory PDO, so every test in the run shares the same
 * fresh DB. Each test that needs isolation should TRUNCATE in setUp().
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

// In-memory SQLite — fast, isolated, no cleanup needed.
if (!defined('DB_DRIVER'))  define('DB_DRIVER', 'sqlite');
if (!defined('DB_PATH'))    define('DB_PATH', ':memory:');
if (!defined('DB_PREFIX'))  define('DB_PREFIX', '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');
if (!defined('DEBUG'))      define('DEBUG', true);

// Composer autoloader (Yikai\Tests\* + dev deps).
require_once ROOT_PATH . '/vendor/autoload.php';

// The CMS uses non-namespaced classes loaded via require_once. Pull the
// foundational ones in here so test classes can reference them directly.
require_once ROOT_PATH . '/config/database.php';
require_once ROOT_PATH . '/includes/models/Model.php';
require_once ROOT_PATH . '/includes/models/autoload.php';

/*
 * Test-side stubs for the few production helpers the domain models call
 * (those normally live in includes/functions.php, which pulls a much
 * heavier transitive graph: config, sessions, hooks, plugins, …).
 *
 * Each is namespaced to global scope and only declared if not already
 * defined, so a test that needs the real helper can still load it.
 */
if (!function_exists('siteLang')) {
    // 镜像 includes/functions.php 的 siteLang()：SITE_LANG 常量优先，否则回落 config('site_lang')。
    // 早期桩硬编码 'zh-CN'，令依赖当前语言的读取（configJsonLang 等）无法在测试里切换语言。
    function siteLang(): string { return defined('SITE_LANG') ? SITE_LANG : (string) config('site_lang', 'zh-CN'); }
}
if (!function_exists('isMultiLangEnabled')) {
    // Tests run against a single-language schema; disable the lang filter.
    function isMultiLangEnabled(string $context = ''): bool { return false; }
}
if (!function_exists('config')) {
    function config(string $key, mixed $default = ''): mixed {
        $runtimeOverrides = $GLOBALS['yikai_config_runtime_overrides'] ?? [];
        if (is_array($runtimeOverrides) && array_key_exists($key, $runtimeOverrides)) {
            return $runtimeOverrides[$key];
        }
        return $GLOBALS['_test_config'][$key] ?? $default;
    }
}
if (!function_exists('configJsonLang')) {
    // 镜像 includes/functions.php 的 configJsonLang()：非默认语言优先读 {key}_{siteLang}，
    // 空则回落 base。首页自定义区块（home_custom_<N>）的按语言分流渲染依赖它。
    function configJsonLang(string $configKey): string {
        $langVal = config($configKey . '_' . siteLang(), '');
        if ($langVal !== '') return $langVal;
        return config($configKey, '') ?: '';
    }
}
if (!function_exists('configLang')) {
    function configLang(string $configKey, string $langKey = ''): string {
        $langKey = $langKey !== '' ? $langKey : $configKey;
        $langValue = config($configKey . '_' . siteLang(), '');
        return $langValue !== '' ? (string) $langValue : (string) (config($configKey, '') ?: __($langKey));
    }
}
if (!function_exists('__')) {
    // 与产线 __() 同构：第二参是参数数组（str_replace :param）。旧桩曾是 string $default，
    // 会掩盖「把默认值当第二参传」的 TypeError（历史上导致过整页 500）。
    // 键名上没有占位符的参数以「 [k=v]」追加，保证测试仍能断言参数值。
    function __(string $key, array $params = []): string {
        $text = $key;
        foreach ($params as $name => $value) {
            $placeholder = ':' . $name;
            if (str_contains($text, $placeholder)) {
                $text = str_replace($placeholder, (string) $value, $text);
            } else {
                $text .= ' [' . $name . '=' . $value . ']';
            }
        }
        return $text;
    }
}
if (!function_exists('getDefaults')) {
    // 镜像 includes/functions.php 的 getDefaults()：从 config/defaults.php 读默认设置。
    function getDefaults(string $group = ''): array {
        static $all = null;
        if ($all === null) {
            $all = require ROOT_PATH . '/config/defaults.php';
        }
        return $group ? ($all[$group] ?? []) : $all;
    }
}
