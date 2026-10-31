<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$sourceRoot = dirname(__DIR__, 2);
$root = $argv[2] ?? '';
$mode = $argv[1] ?? '';
require_once $sourceRoot . '/includes/ai-access/AiAccessRuntime.php';
try {
    if (str_starts_with($mode, 'prepare')) {
        if ($mode !== 'prepare-without-capture') AiAccessBootstrapConfig::beginPreparation($root);
        define('ROOT_PATH', $root);
        ini_set('session.save_path', $root . '/sessions');
        require $root . '/config/config.php';
        if ($mode === 'prepare-changed') file_put_contents($root . '/config/config.php', "\n// changed during preparation\n", FILE_APPEND);
        if (is_file($root . '/config/overrides.php')) {
            function configOverrides(): array { return require ROOT_PATH . '/config/overrides.php'; }
        }
        AiAccessBootstrapConfig::prepare($root);
        $prefix = DB_PREFIX;
        db()->execute('CREATE TABLE ' . $prefix . 'users (id INTEGER PRIMARY KEY, username TEXT, role_id INTEGER, status INTEGER)');
        db()->execute('CREATE TABLE ' . $prefix . 'roles (id INTEGER PRIMARY KEY, permissions TEXT, status INTEGER)');
        db()->execute('CREATE TABLE ' . $prefix . 'settings (`key` TEXT PRIMARY KEY, `value` TEXT)');
        db()->execute('INSERT INTO ' . $prefix . 'users VALUES (7, ?, 2, 1)', ['fixture-editor']);
        db()->execute('INSERT INTO ' . $prefix . 'roles VALUES (2, ?, 1)', ['["ai_access","edit_article"]']);
        db()->execute('INSERT INTO ' . $prefix . 'settings VALUES (?, ?)', ['ai_access_enabled', '1']);
        $out = ['success' => true, 'normal_session_active' => session_status() === PHP_SESSION_ACTIVE];
        session_write_close();
    } else {
        if ($mode === 'contaminated') define('DB_DRIVER', 'mysql');
        if ($mode === 'active-session') {
            ini_set('session.save_path', $root . '/sessions');
            session_start();
        }
        if ($mode === 'read') {
            AiAccessBootstrapConfig::read($root);
        } else {
            AiAccessRuntime::boot($root);
        }
        $out = ['success' => true, 'session_status' => session_status(), 'session_id' => session_id(),
            'functions_loaded' => function_exists('config'), 'plugins_loaded' => function_exists('loadActivePlugins'),
            'scheduled_publish_loaded' => class_exists('ScheduledPublish', false)];
    }
} catch (RuntimeException $error) {
    $out = ['success' => false, 'status' => $error->getCode(), 'error' => $error->getMessage()];
}
echo json_encode($out, JSON_THROW_ON_ERROR), PHP_EOL;
