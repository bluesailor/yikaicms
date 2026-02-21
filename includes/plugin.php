<?php
/**
 * Yikai CMS - 插件加载器
 *
 * 扫描 /plugins/ 目录，加载已启用的插件
 * PHP 8.0+
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

/**
 * 读取插件元数据
 */
function getPluginMeta(string $slug): ?array
{
    static $cache = [];
    if (isset($cache[$slug])) {
        return $cache[$slug];
    }
    $file = ROOT_PATH . '/plugins/' . $slug . '/plugin.json';
    if (!file_exists($file)) {
        return null;
    }
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) {
        return null;
    }
    $data['slug'] = $slug;
    $cache[$slug] = $data;
    return $data;
}

/**
 * 获取已启用的插件 slug 列表
 */
function getActivePlugins(): array
{
    try {
        if (!db()->tableExists('plugins')) {
            return [];
        }
        return pluginModel()->getActiveSlugs();
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * 加载所有已启用的插件
 */
function loadActivePlugins(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $slugs = getActivePlugins();
    foreach ($slugs as $slug) {
        if (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $slug)) {
            continue;
        }
        $mainFile = ROOT_PATH . '/plugins/' . $slug . '/main.php';
        if (file_exists($mainFile)) {
            require_once $mainFile;
        }
    }
    do_action('plugins_loaded');
}

/**
 * 获取所有插件（目录扫描 + DB 状态合并），供后台管理页使用
 */
function getAllPlugins(): array
{
    $dir = ROOT_PATH . '/plugins';
    if (!is_dir($dir)) {
        return [];
    }

    $dbPlugins = [];
    try {
        if (db()->tableExists('plugins')) {
            $rows = pluginModel()->all();
            foreach ($rows as $row) {
                $dbPlugins[$row['slug']] = $row;
            }
        }
    } catch (\Throwable $e) {
        // table may not exist yet
    }

    $plugins = [];
    $entries = scandir($dir);
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
            continue;
        }
        if (!is_dir($dir . '/' . $entry)) {
            continue;
        }
        $meta = getPluginMeta($entry);
        if (!$meta) {
            continue;
        }

        $meta['status'] = 0;
        $meta['installed_at'] = 0;
        $meta['activated_at'] = 0;

        if (isset($dbPlugins[$entry])) {
            $meta['status'] = (int)$dbPlugins[$entry]['status'];
            $meta['installed_at'] = (int)$dbPlugins[$entry]['installed_at'];
            $meta['activated_at'] = (int)$dbPlugins[$entry]['activated_at'];
        }

        $plugins[$entry] = $meta;
    }

    // 排序：已启用在前，然后按名称
    uasort($plugins, function ($a, $b) {
        if ($a['status'] !== $b['status']) {
            return $b['status'] - $a['status'];
        }
        return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
    });

    return $plugins;
}

/**
 * 安全删除插件目录
 */
function deletePluginDir(string $slug): bool
{
    $dir = ROOT_PATH . '/plugins/' . $slug;
    if (!is_dir($dir)) {
        return false;
    }
    $realDir = realpath($dir);
    $pluginsDir = realpath(ROOT_PATH . '/plugins');
    if (!$realDir || !$pluginsDir || !str_starts_with($realDir, $pluginsDir)) {
        return false;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    return @rmdir($dir);
}

// 加载已启用的插件
loadActivePlugins();
