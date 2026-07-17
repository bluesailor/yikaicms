<?php
/**
 * 命令组：recycle
 *   recycle:purge   彻底删除回收站中超过 N 天的内容/产品（默认 30 天）
 *                   --days=N 指定天数；--dry-run 只统计不删除
 *
 * 适合挂系统 cron 定期清理回收站，避免软删除行无限堆积。
 */
declare(strict_types=1);

if (!defined('IK_CLI')) return;

CLI::register('recycle:purge', '清理回收站中超过 N 天的项目', function (array $args, array $opts): int {
    $days = isset($opts['days']) ? max(0, (int) $opts['days']) : 30;
    $dryRun = !empty($opts['dry-run']);

    $models = [
        '内容' => contentModel(),
        '产品' => productModel(),
        '相册' => albumModel(),
        '下载' => downloadModel(),
        '招聘' => jobModel(),
    ];

    $total = 0;
    foreach ($models as $label => $model) {
        if ($dryRun) {
            // 只统计：回收站总数（不精确到天，提示用）
            $n = $model->trashedCount();
            CLI::out("  {$label}：回收站共 {$n} 项（dry-run 不删除）");
            continue;
        }
        $n = $model->purgeTrashedOlderThan($days);
        $total += $n;
        CLI::out("  {$label}：清理 {$n} 项");
    }

    if ($dryRun) {
        CLI::info('dry-run 结束，未删除任何数据');
    } else {
        CLI::ok("回收站清理完成：共彻底删除 {$total} 项（超过 {$days} 天）");
    }
    return 0;
}, ['usage' => 'recycle:purge [--days=30] [--dry-run]']);
