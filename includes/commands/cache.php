<?php
/**
 * 命令组：cache
 *   cache:clear  清空 storage/cache/html/
 *   cache:stats  统计缓存命中数 / 大小 / oldest
 */
declare(strict_types=1);

if (!defined('IK_CLI')) return;

CLI::register('cache:clear', '清空 HTML 缓存（storage/cache/html/）', function (array $args, array $opts): int {
    $dir = ROOT_PATH . '/storage/cache/html';
    if (!is_dir($dir)) {
        CLI::info("缓存目录不存在：{$dir}");
        return 0;
    }
    $count = 0;
    $bytes = 0;
    foreach (glob($dir . '/*.html') ?: [] as $f) {
        $bytes += @filesize($f) ?: 0;
        if (@unlink($f)) $count++;
    }
    CLI::ok("已清理 {$count} 个 HTML 缓存文件（" . round($bytes / 1024, 1) . " KB）");
    return 0;
}, ['usage' => 'cache:clear']);

CLI::register('cache:stats', '显示缓存统计', function (array $args, array $opts): int {
    $dir = ROOT_PATH . '/storage/cache/html';
    if (!is_dir($dir)) {
        CLI::info("缓存目录不存在：{$dir}");
        return 0;
    }
    $files = glob($dir . '/*.html') ?: [];
    $count = count($files);
    $bytes = 0;
    $oldest = PHP_INT_MAX;
    $newest = 0;
    foreach ($files as $f) {
        $bytes += @filesize($f) ?: 0;
        $m = @filemtime($f) ?: 0;
        if ($m < $oldest) $oldest = $m;
        if ($m > $newest) $newest = $m;
    }
    CLI::out("缓存目录：" . $dir);
    CLI::out("文件数  ：" . $count);
    CLI::out("总大小  ：" . round($bytes / 1024, 1) . " KB");
    if ($count > 0) {
        CLI::out("最早    ：" . date('Y-m-d H:i:s', $oldest));
        CLI::out("最新    ：" . date('Y-m-d H:i:s', $newest));
    }
    return 0;
}, ['usage' => 'cache:stats']);
