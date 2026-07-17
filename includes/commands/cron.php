<?php
/**
 * 命令组：cron
 *   cron:list  列出已注册任务及上次运行状态
 *   cron:run   运行到点任务；--force 无视间隔全部运行；--task=<name> 只运行一个
 */
declare(strict_types=1);

if (!defined('IK_CLI')) return;

require_once ROOT_PATH . '/includes/Cron.php';

CLI::register('cron:list', '列出定时任务及状态', function (array $args, array $opts): int {
    foreach (Cron::tasks() as $t) {
        $last = $t['last'] > 0 ? date('Y-m-d H:i', $t['last']) : '从未';
        $status = $t['status'] !== '' ? "[{$t['status']}]" : '';
        CLI::out(sprintf("  %-16s %-16s 间隔 %ds  上次 %s %s", $t['name'], $t['label'], $t['interval'], $last, $status));
    }
    return 0;
}, ['usage' => 'cron:list']);

CLI::register('cron:run', '运行到点的定时任务', function (array $args, array $opts): int {
    if (!empty($opts['task']) && is_string($opts['task'])) {
        $r = Cron::runOne($opts['task']);
        if (!$r['ran']) {
            CLI::err($r['msg']);
            return 1;
        }
        $r['ok'] ? CLI::ok("{$r['name']}：{$r['msg']}（{$r['ms']}ms）")
                 : CLI::err("{$r['name']}：{$r['msg']}");
        return $r['ok'] ? 0 : 2;
    }

    $force = !empty($opts['force']);
    $results = Cron::runDue($force);
    $ran = 0;
    $fail = 0;
    foreach ($results as $r) {
        if (!$r['ran']) continue;
        $ran++;
        if ($r['ok']) {
            CLI::out("  → {$r['name']}：{$r['msg']}（{$r['ms']}ms）");
        } else {
            $fail++;
            CLI::err("  → {$r['name']}：{$r['msg']}");
        }
    }
    CLI::ok("定时任务完成：运行 {$ran} 个，失败 {$fail} 个");
    return $fail > 0 ? 2 : 0;
}, ['usage' => 'cron:run [--force] [--task=<name>]']);
