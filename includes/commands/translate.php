<?php
/**
 * 命令组：translate
 *   translate:run   推进所有「进行中」的批量翻译任务；--budget=秒（默认 240，上限 3600）
 *
 * 定时任务 translation_batches 每分钟也会跑一次（每次 45 秒）；大批量可以直接挂这条命令。
 * 内存吃到 memory_limit 的 85% 时提前结束，下一次接着跑，已完成的项不会重做。
 */
declare(strict_types=1);

if (!defined('IK_CLI')) return;

CLI::register('translate:run', '推进进行中的批量翻译任务', function (array $args, array $opts): int {
    if (!db()->tableExists('translation_batch_events')) {
        CLI::err('批量翻译的数据表未升级，请先完成数据库升级（php bin/yikai.php migrate:run）');
        return 1;
    }
    require_once ROOT_PATH . '/includes/TranslationBatches.php';
    $budget = isset($opts['budget']) ? min(3600, max(5, (int) $opts['budget'])) : 240;
    @set_time_limit($budget + 60);
    $service = new TranslationBatches(db());
    $done = $service->drain($budget);
    // 结果保留期限：与定时任务同一口径，命令行跑时也清掉过期的已完成 / 已取消批次
    $purged = $service->purge(time(), (int) config('translate_batch_keep_days', TranslationBatches::KEEP_DAYS) ?: TranslationBatches::KEEP_DAYS);
    if ($purged > 0) CLI::out("已清理 {$purged} 个过期的批次");
    if ($done === []) {
        CLI::out('没有进行中的批量翻译任务');
        return 0;
    }
    foreach ($done as $id => $counts) {
        $parts = [];
        foreach ($counts as $status => $n) if ($n > 0) $parts[] = "{$status} {$n}";
        CLI::out("  #{$id}  " . implode(', ', $parts));
    }
    if (TranslationBatches::memoryTight()) CLI::out('内存接近上限，已提前结束；再运行一次接着处理');
    return 0;
}, ['usage' => 'translate:run [--budget=240]']);
