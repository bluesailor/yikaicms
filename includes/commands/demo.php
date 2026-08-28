<?php
/**
 * 命令组：demo
 *   demo:on / demo:off / demo:status      只读演示模式（demo_mode=1，后台 POST 全拦）
 *   demo:sandbox                          演示沙盒（demo_mode=2，可写，按快照重置）
 *   demo:snapshot                         把当前库 + uploads 存为快照
 *   demo:reset                            从快照恢复（也可 cron.php?token=..&task=demo_reset）
 *   写 yikai_settings.demo_mode；Compatibility::initDemoMode() 在每次请求时读取。
 */
declare(strict_types=1);

if (!defined('IK_CLI')) return;

require_once ROOT_PATH . '/includes/Cron.php';
require_once ROOT_PATH . '/includes/DemoSandbox.php';

CLI::register('demo:on', '开启只读演示模式（后台 POST 写操作被拦截）', function (array $args, array $opts): int {
    settingModel()->set('demo_mode', DemoSandbox::MODE_READONLY);
    CLI::ok('只读演示模式已开启');
    CLI::info('提示：仅 /admin/upgrade.php 和 /admin/setting_demo.php 例外，其它写操作会被拦截；要让访客能动手改，用 demo:sandbox');
    return 0;
}, ['usage' => 'demo:on']);

CLI::register('demo:off', '关闭演示模式（只读与沙盒都关）', function (array $args, array $opts): int {
    settingModel()->set('demo_mode', DemoSandbox::MODE_OFF);
    CLI::ok('演示模式已关闭');
    return 0;
}, ['usage' => 'demo:off']);

CLI::register('demo:sandbox', '开启演示沙盒（可写，按快照定时重置）', function (array $args, array $opts): int {
    if (!DemoSandbox::hasSnapshot()) {
        if (empty($opts['no-snapshot'])) {
            CLI::info('尚无快照，先以当前站点内容建快照…');
            $m = DemoSandbox::snapshot();
            CLI::ok(sprintf('快照已建：%d 张表 / %s KB / %d 个上传文件', $m['tables'], number_format($m['sql_bytes'] / 1024, 1), $m['files']));
        } else {
            CLI::err('尚无快照且指定了 --no-snapshot；沙盒无快照无法重置');
            return 1;
        }
    }
    settingModel()->set('demo_mode', DemoSandbox::MODE_SANDBOX);
    if (isset($opts['interval']) && is_string($opts['interval']) && ctype_digit($opts['interval'])) {
        settingModel()->set('demo_reset_interval', (string) max(DemoSandbox::MIN_INTERVAL, (int) $opts['interval']));
    }
    CLI::ok('演示沙盒已开启，重置间隔 ' . DemoSandbox::interval() . ' 秒');
    CLI::info('一键重置链接：' . rtrim((string) config('site_url', ''), '/') . '/cron.php?token=' . Cron::token() . '&task=demo_reset');
    CLI::info('定时重置需要计划任务每 5 分钟请求 cron.php?token=<token>（或 CLI cron:run）');
    return 0;
}, ['usage' => 'demo:sandbox [--interval=秒] [--no-snapshot]']);

CLI::register('demo:snapshot', '把当前库 + uploads 存为沙盒快照（覆盖旧快照）', function (array $args, array $opts): int {
    $t0 = microtime(true);
    $m = DemoSandbox::snapshot();
    CLI::ok(sprintf(
        '快照已更新：%d 张表 / %s KB / %d 个上传文件（%ss）→ %s',
        $m['tables'],
        number_format($m['sql_bytes'] / 1024, 1),
        $m['files'],
        round(microtime(true) - $t0, 2),
        DemoSandbox::dir()
    ));
    return 0;
}, ['usage' => 'demo:snapshot']);

CLI::register('demo:reset', '从快照恢复演示站（库 + uploads + 缓存）', function (array $args, array $opts): int {
    if (!DemoSandbox::isSandbox() && empty($opts['force'])) {
        CLI::err('当前不是沙盒模式；确认要用快照覆盖现有数据请加 --force');
        return 1;
    }
    try {
        $r = DemoSandbox::reset('cli');
    } catch (\Throwable $e) {
        CLI::err('重置失败：' . $e->getMessage());
        return 2;
    }
    CLI::ok(sprintf('重置完成：%d 条 SQL / %d 个文件 / 清缓存 %d（%dms）', $r['statements'], $r['files'], $r['cache'], $r['ms']));
    return 0;
}, ['usage' => 'demo:reset [--force]']);

/** @psalm-suppress UnusedClosureParam CLI::register 的固定签名，本命令不吃参数 */
CLI::register('demo:status', '查看当前演示模式状态', function (array $args, array $opts): int {
    $mode = DemoSandbox::mode();
    $color = CLI_color_supported();
    $label = match ($mode) {
        DemoSandbox::MODE_READONLY => $color ? "\033[33m只读演示\033[0m" : '只读演示',
        DemoSandbox::MODE_SANDBOX => $color ? "\033[36m演示沙盒\033[0m" : '演示沙盒',
        default => $color ? "\033[32m已关闭\033[0m" : '已关闭',
    };
    CLI::out('演示模式：' . $label);

    $m = DemoSandbox::manifest();
    CLI::out('快照：' . ($m === null ? '无' : sprintf('%s（%d 张表 / %s KB / %d 文件）', $m['created_at'] ?? '?', (int) ($m['tables'] ?? 0), number_format(((int) ($m['sql_bytes'] ?? 0)) / 1024, 1), (int) ($m['files'] ?? 0))));
    $l = DemoSandbox::lastReset();
    CLI::out('上次重置：' . ($l === null ? '从未' : sprintf('%s（%s，%dms）', $l['at'] ?? '?', $l['trigger'] ?? '?', (int) ($l['ms'] ?? 0))));
    if ($mode === DemoSandbox::MODE_SANDBOX) {
        CLI::out('重置间隔：' . DemoSandbox::interval() . ' 秒');
        CLI::out('一键重置：' . rtrim((string) config('site_url', ''), '/') . '/cron.php?token=' . Cron::token() . '&task=demo_reset');
    }
    return 0;
}, ['usage' => 'demo:status']);

// 颜色辅助：只在 TTY 上色
if (!function_exists('CLI_color_supported')) {
    function CLI_color_supported(): bool
    {
        return function_exists('stream_isatty') && @stream_isatty(STDOUT);
    }
}
