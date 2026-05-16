<?php
/**
 * 命令组：demo
 *   demo:on / demo:off / demo:status
 *   写 yikai_settings.demo_mode；Compatibility::initDemoMode() 在每次请求时读取。
 */
declare(strict_types=1);

if (!defined('IK_CLI')) return;

CLI::register('demo:on', '开启演示模式（后台 POST 写操作被拦截）', function (array $args, array $opts): int {
    settingModel()->set('demo_mode', '1');
    CLI::ok('演示模式已开启');
    CLI::info('提示：仅 /admin/upgrade.php 和 /admin/setting_demo.php 例外，其它写操作会被拦截');
    return 0;
}, ['usage' => 'demo:on']);

CLI::register('demo:off', '关闭演示模式', function (array $args, array $opts): int {
    settingModel()->set('demo_mode', '0');
    CLI::ok('演示模式已关闭');
    return 0;
}, ['usage' => 'demo:off']);

CLI::register('demo:status', '查看当前演示模式状态', function (array $args, array $opts): int {
    $v = (string)config('demo_mode', '0');
    if ($v === '1') {
        CLI::out('演示模式：' . (CLI_color_supported() ? "\033[33m已开启\033[0m" : '已开启') . '（写操作被拦截）');
    } else {
        CLI::out('演示模式：' . (CLI_color_supported() ? "\033[32m已关闭\033[0m" : '已关闭'));
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
