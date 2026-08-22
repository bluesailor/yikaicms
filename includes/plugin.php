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
/**
 * 插件元数据按当前语言取值：plugin.json 里的 name_en / description_ja 等，取不到回落中文原文。
 *
 * 抽成函数而不是各处内联：admin/plugin.php 的列表、admin/plugin_page.php 的页面标题
 * 都要用。同一段逻辑抄两份必然漂移——今天刚在 channel.php 的核心版/主题版两份副本上
 * 吃过这个亏（主题版修了、核心版没修，英文站标题被切成 Ne|ws）。
 *
 * @param array<string,mixed> $meta plugin.json 解析结果
 */
function pluginMetaLabel(array $meta, string $field = 'name', string $fallback = ''): string
{
    $lang = function_exists('getLang') ? getLang() : 'zh-CN';
    if ($lang !== 'zh-CN') {
        $suffixed = $meta[$field . '_' . str_replace('-', '_', $lang)] ?? null;
        if (is_string($suffixed) && trim($suffixed) !== '') {
            return $suffixed;
        }
    }
    $base = $meta[$field] ?? '';
    return (is_string($base) && $base !== '') ? $base : $fallback;
}

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
 * 插件是否「真正可用」＝ 数据库标记为启用 **且** 目录还在。
 *
 * getActivePlugins() 只回答「记录里启用了什么」，这对加载器够用（它逐个
 * file_exists 兜底）。但把它当作「功能可用」来渲染入口就会出事：插件目录
 * 被删/未随包发布时，记录仍在，于是渲染出指向 404 的链接。判断要不要显示
 * 某个插件的入口，一律用本函数。
 */
function isPluginAvailable(string $slug): bool
{
    if (preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $slug) !== 1) {
        return false;
    }
    return is_dir(ROOT_PATH . '/plugins/' . $slug) && in_array($slug, getActivePlugins(), true);
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
    // Pass 1: 加载 register.php（早期注册，先于 main.php）
    foreach ($slugs as $slug) {
        if (!preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $slug)) {
            continue;
        }
        // 语言包先于插件代码加载，插件里任何 __() 才拿得到自己的文案
        if (function_exists('loadPluginLang')) {
            loadPluginLang($slug);
        }
        $registerFile = ROOT_PATH . '/plugins/' . $slug . '/register.php';
        if (file_exists($registerFile)) {
            require_once $registerFile;
        }
    }
    // Pass 2: 加载 main.php
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
