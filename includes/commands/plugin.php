<?php
/**
 * 命令组：plugin
 *   plugin:list                    扫 /plugins/ 目录 + DB，显示状态
 *   plugin:enable <slug>           激活
 *   plugin:disable <slug>          停用
 */
declare(strict_types=1);

if (!defined('IK_CLI')) return;

/**
 * 内部：列出 plugins/ 下所有有效插件目录（含 plugin.json）。
 * @return array<string, array> slug => manifest
 */
function _ikai_cli_scanPlugins(): array
{
    $dir = ROOT_PATH . '/plugins';
    if (!is_dir($dir)) return [];
    $out = [];
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..' || $e[0] === '.' || $e[0] === '_') continue;
        $manifest = $dir . '/' . $e . '/plugin.json';
        if (!is_file($manifest)) continue;
        $meta = json_decode(file_get_contents($manifest), true);
        if (!is_array($meta)) continue;
        $out[$e] = $meta;
    }
    ksort($out);
    return $out;
}

CLI::register('plugin:list', '列出插件（目录 + 状态）', function (array $args, array $opts): int {
    $plugins = _ikai_cli_scanPlugins();
    if (empty($plugins)) {
        CLI::info('没有任何插件（/plugins/ 为空或缺 plugin.json）');
        return 0;
    }
    // 查 DB 拿 active 状态
    $active = [];
    try {
        if (db()->tableExists('plugins')) {
            $active = array_flip(pluginModel()->getActiveSlugs());
        }
    } catch (\Throwable $e) { /* table may not exist */ }

    printf("%-22s %-9s %-10s %s\n", 'SLUG', 'STATUS', 'VERSION', 'NAME');
    printf("%-22s %-9s %-10s %s\n", '----', '------', '-------', '----');
    foreach ($plugins as $slug => $m) {
        $status = isset($active[$slug]) ? "\033[32mactive\033[0m" : "\033[90minactive\033[0m";
        $statusPlain = isset($active[$slug]) ? 'active' : 'inactive';
        $useColor = function_exists('stream_isatty') && @stream_isatty(STDOUT);
        printf("%-22s %-9s %-10s %s\n",
            mb_substr($slug, 0, 22),
            $useColor ? $status : $statusPlain,
            mb_substr((string)($m['version'] ?? '-'), 0, 10),
            (string)($m['name'] ?? '')
        );
    }
    return 0;
}, ['usage' => 'plugin:list']);

CLI::register('plugin:enable', '激活插件', function (array $args, array $opts): int {
    $slug = $args[0] ?? '';
    if ($slug === '') {
        CLI::err('请指定插件 slug，例如：bin/yikai plugin:enable cookie-consent');
        return 1;
    }
    $plugins = _ikai_cli_scanPlugins();
    if (!isset($plugins[$slug])) {
        CLI::err("插件目录不存在或缺 plugin.json：plugins/{$slug}/");
        return 1;
    }
    pluginModel()->activate($slug);
    CLI::ok("已激活：{$slug}（" . (string)($plugins[$slug]['name'] ?? '') . '）');
    return 0;
}, ['usage' => 'plugin:enable <slug>']);

CLI::register('plugin:disable', '停用插件', function (array $args, array $opts): int {
    $slug = $args[0] ?? '';
    if ($slug === '') {
        CLI::err('请指定插件 slug，例如：bin/yikai plugin:disable cookie-consent');
        return 1;
    }
    pluginModel()->deactivate($slug);
    CLI::ok("已停用：{$slug}");
    return 0;
}, ['usage' => 'plugin:disable <slug>']);
