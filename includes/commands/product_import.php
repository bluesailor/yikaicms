<?php
/**
 * 命令组：product-import
 *   product-import:run   推进排队中 / 执行中 / 撤销中的产品导入任务；--job=<任务号> 只跑一个；--budget=秒（默认 240，上限 3600）
 *
 * 定时任务 product_import 每分钟也会跑一次（每次 45 秒），建任务后还会立即触发；大批量可以直接挂这条命令。
 * 同一任务同一时刻只有一个执行者：和页面、定时任务同时跑也不会重复写。
 */
declare(strict_types=1);

if (!defined('IK_CLI')) return;

CLI::register('product-import:run', '推进产品导入任务', function (array $args, array $opts): int {
    require_once ROOT_PATH . '/includes/product-import/ProductImportWorker.php';
    $budget = isset($opts['budget']) ? min(3600, max(5, (int) $opts['budget'])) : 240;
    @set_time_limit($budget + 60);
    $jobs = new ProductImportJobs(ROOT_PATH . '/storage/product-import/jobs');
    $worker = new ProductImportWorker($jobs, ROOT_PATH);
    if (!empty($opts['job']) && is_string($opts['job'])) {
        $result = $worker->runJob($opts['job'], $budget, 'cli');
        $job = $result['job'];
        CLI::out(sprintf('  %s  %s  %s', $opts['job'], $result['result'], $job === null ? '' : $job['status'] . ' ' . json_encode($job['counts'] ?? [])));
        return in_array($result['result'], ['gone', 'blocked'], true) ? 1 : 0;
    }
    $result = $worker->drain($budget, 'cli');
    if ($result['jobs'] === 0) {
        CLI::out($result['remaining'] ? '有任务正被别的执行者处理' : '没有排队中的产品导入任务');
        return 0;
    }
    CLI::out("推进了 {$result['jobs']} 个任务" . ($result['remaining'] ? '，还有没做完的，再运行一次接着处理' : ''));
    return 0;
}, ['usage' => 'product-import:run [--job=<id>] [--budget=240]']);
