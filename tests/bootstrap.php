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
    function siteLang(): string { return defined('SITE_LANG') ? SITE_LANG : 'zh-CN'; }
}
if (!function_exists('isMultiLangEnabled')) {
    // Tests run against a single-language schema; disable the lang filter.
    function isMultiLangEnabled(string $context = ''): bool { return false; }
}
if (!function_exists('config')) {
    function config(string $key, mixed $default = ''): mixed {
        return $GLOBALS['_test_config'][$key] ?? $default;
    }
}
if (!function_exists('__')) {
    function __(string $key, string $default = ''): string { return $default !== '' ? $default : $key; }
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
