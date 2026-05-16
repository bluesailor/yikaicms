<?php
/**
 * 命令组：lang
 *   lang:switcher on / off / status     切换前台语言切换器开关
 *
 * 设置项 yikai_settings.show_lang_switcher：
 *   '1' = 显示，'0' = 隐藏（默认）。
 *   开关由 includes/init.php 读取，影响 themes 里的语言切换器渲染、URL/cookie 检测。
 */
declare(strict_types=1);

if (!defined('IK_CLI')) return;

CLI::register('lang:switcher:on', '开启前台语言切换器（显示下拉）', function (array $args, array $opts): int {
    settingModel()->set('show_lang_switcher', '1');
    CLI::ok('前台语言切换器：已开启');
    CLI::info('提示：访客可通过 URL ?_lang=ja 或下拉切换；选择保存在 cookie site_lang。');
    return 0;
}, ['usage' => 'lang:switcher:on']);

CLI::register('lang:switcher:off', '关闭前台语言切换器', function (array $args, array $opts): int {
    settingModel()->set('show_lang_switcher', '0');
    CLI::ok('前台语言切换器：已关闭');
    return 0;
}, ['usage' => 'lang:switcher:off']);

CLI::register('lang:switcher:status', '查看语言切换器状态', function (array $args, array $opts): int {
    $on = (string)config('show_lang_switcher', '0') === '1';
    $useColor = function_exists('stream_isatty') && @stream_isatty(STDOUT);
    $tag = $useColor
        ? ($on ? "\033[32m已开启\033[0m" : "\033[90m已关闭\033[0m")
        : ($on ? '已开启' : '已关闭');
    CLI::out('前台语言切换器：' . $tag);
    CLI::out('当前 site_lang  ：' . (string)config('site_lang', 'zh-CN'));
    CLI::out('当前 admin_lang ：' . (string)config('admin_lang', 'zh-CN'));
    return 0;
}, ['usage' => 'lang:switcher:status']);
