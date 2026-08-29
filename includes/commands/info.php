<?php
/**
 * 命令组：info
 *   info     概览：CMS 版本 + 运行时 + DB + 缓存 + 插件 + 配方
 */
declare(strict_types=1);

if (!defined('IK_CLI')) return;

CLI::register('info', '系统概览（版本/DB/缓存/插件/配方）', function (array $args, array $opts): int {
    $line = function (string $k, string $v) {
        printf("  %-18s %s\n", $k, $v);
    };

    CLI::out('Yikai CMS');
    $build = YikaiProductIdentity::buildInfo(ROOT_PATH);
    $line('PRODUCT_ID',   $build['product_id']);
    $line('VENDOR_ID',    $build['vendor_id']);
    $line('CMS_VERSION',  defined('CMS_VERSION') ? CMS_VERSION : 'unknown');
    $line('BUILD_ID',     $build['build_id']);
    $line('SOURCE_COMMIT', $build['source_commit'] !== '' ? $build['source_commit'] : '-');
    $line('INTEGRITY',    $build['integrity']);
    $line('PHP',          PHP_VERSION);
    $line('OS',           PHP_OS_FAMILY);
    $line('SAPI',         PHP_SAPI);
    $line('ROOT_PATH',    ROOT_PATH);
    $line('SITE_URL',     defined('SITE_URL') ? SITE_URL : '-');

    CLI::out('');
    CLI::out('Database');
    $line('Driver',       defined('DB_DRIVER') ? DB_DRIVER : 'unknown');
    if (defined('DB_DRIVER') && DB_DRIVER === 'sqlite') {
        $path = defined('DB_PATH') ? DB_PATH : '-';
        $line('Path',    $path);
        $line('Size',    file_exists($path) ? (round(filesize($path) / 1024, 1) . ' KB') : '(missing)');
    } else {
        $line('Host',    (defined('DB_HOST') ? DB_HOST : '-') . ':' . (defined('DB_PORT') ? DB_PORT : '-'));
        $line('Name',    defined('DB_NAME') ? DB_NAME : '-');
    }
    try {
        $tablesCnt = (int)db()->fetchColumn(
            DB_DRIVER === 'sqlite'
                ? "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name LIKE '" . DB_PREFIX . "%'"
                : "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ? AND table_name LIKE ?",
            DB_DRIVER === 'sqlite' ? [] : [DB_NAME, DB_PREFIX . '%']
        );
        $line('Tables',  (string)$tablesCnt);
        $line('Status',  'connected');
    } catch (\Throwable $e) {
        $line('Status',  'ERROR: ' . $e->getMessage());
    }

    CLI::out('');
    CLI::out('Settings');
    try {
        $line('Site lang',   (string)config('site_lang', '-'));
        $line('Admin lang',  (string)config('admin_lang', '-'));
        $line('Demo mode',   ((string)config('demo_mode', '0') === '1' ? 'ON' : 'off'));
        $line('Lang switcher', ((string)config('show_lang_switcher', '0') === '1' ? 'on' : 'off'));
    } catch (\Throwable $e) {
        $line('Settings',    'ERROR: ' . $e->getMessage());
    }

    CLI::out('');
    CLI::out('Cache');
    $cacheDir = ROOT_PATH . '/storage/cache/html';
    if (is_dir($cacheDir)) {
        $files = glob($cacheDir . '/*.html') ?: [];
        $bytes = 0;
        foreach ($files as $f) $bytes += @filesize($f) ?: 0;
        $line('HTML cache',  count($files) . ' files · ' . round($bytes / 1024, 1) . ' KB');
    } else {
        $line('HTML cache',  '(none)');
    }

    CLI::out('');
    CLI::out('Plugins');
    // 直接扫目录 + 查 DB，不加载 includes/plugin.php（那会触发 loadActivePlugins 跑 hook）
    $pluginsDir = ROOT_PATH . '/plugins';
    $totalCnt = 0;
    if (is_dir($pluginsDir)) {
        foreach (scandir($pluginsDir) ?: [] as $e) {
            if ($e === '.' || $e === '..' || $e[0] === '.' || $e[0] === '_') continue;
            if (is_file($pluginsDir . '/' . $e . '/plugin.json')) $totalCnt++;
        }
    }
    $activeCnt = 0;
    try {
        if (db()->tableExists('plugins')) {
            $activeCnt = (int)db()->fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "plugins WHERE status = 1");
        }
    } catch (\Throwable $e) { /* table may not exist */ }
    $line('Total',       (string)$totalCnt);
    $line('Active',      (string)$activeCnt);

    CLI::out('');
    CLI::out('Recipes');
    $recipesDir = ROOT_PATH . '/recipes';
    if (is_dir($recipesDir)) {
        $recipeCount = count(array_filter(glob($recipesDir . '/*/recipe.json') ?: [], 'file_exists'));
        $line('Available',   (string)$recipeCount);
    } else {
        $line('Available',   '0 (/recipes/ not found)');
    }

    return 0;
}, ['usage' => 'info']);
