<?php
/**
 * 命令组：recipe（配方系统）
 *   recipe:list                            列出 /recipes/ 下所有配方
 *   recipe:apply <slug> [--update-existing]
 *   recipe:export [--with-contents] [--out=file.json]
 */
declare(strict_types=1);

if (!defined('IK_CLI')) return;

require_once ROOT_PATH . '/includes/RecipeService.php';

CLI::register('recipe:list', '列出可用配方', function (array $args, array $opts): int {
    $svc = new RecipeService();
    $list = $svc->list();
    if (empty($list)) {
        CLI::info('没有可用配方。在 /recipes/{slug}/ 下放 recipe.json 即可。');
        return 0;
    }
    foreach ($list as $slug => $r) {
        $name = (string)($r['name'] ?? $slug);
        CLI::out("• " . $slug . "  —  " . $name);
        CLI::out("    " . (string)($r['description'] ?? ''));
        CLI::out(sprintf("    channels=%d  extfields=%d  contents=%d  settings=%d",
            count($r['channels'] ?? []),
            count($r['extfields'] ?? []),
            count($r['contents'] ?? []),
            count($r['settings'] ?? [])
        ));
    }
    return 0;
}, ['usage' => 'recipe:list']);

CLI::register('recipe:apply', '应用配方', function (array $args, array $opts): int {
    $slug = $args[0] ?? '';
    if ($slug === '') {
        CLI::err('请指定配方 slug，例如：bin/yikai recipe:apply blog-basic');
        return 1;
    }
    $svc = new RecipeService();
    $manifest = $svc->load($slug);
    if ($manifest === null) {
        CLI::err("配方不存在或 manifest 无效：{$slug}");
        return 1;
    }
    CLI::out("即将应用配方：" . (string)($manifest['name'] ?? $slug));
    CLI::out("  channels  : " . count($manifest['channels'] ?? []));
    CLI::out("  extfields : " . count($manifest['extfields'] ?? []));
    CLI::out("  contents  : " . count($manifest['contents'] ?? []));
    CLI::out("  settings  : " . count($manifest['settings'] ?? []));

    if (empty($opts['yes']) && !CLI::confirm('确认应用？', false)) {
        CLI::info('已取消');
        return 0;
    }

    try {
        $report = $svc->apply($slug, [
            'update_existing' => !empty($opts['update-existing']),
        ]);
        CLI::ok(sprintf(
            '应用完成：channels +%d/~%d, extfields +%d/~%d, contents +%d/-%d, settings %d',
            $report['channels_created'],  $report['channels_updated'],
            $report['extfields_created'], $report['extfields_updated'],
            $report['contents_created'],  $report['contents_skipped'],
            $report['settings_set']
        ));
        if (!empty($report['errors'])) {
            foreach ($report['errors'] as $e) CLI::warn($e);
        }
        return 0;
    } catch (\Throwable $e) {
        CLI::err('应用失败：' . $e->getMessage());
        return 2;
    }
}, ['usage' => 'recipe:apply <slug> [--update-existing] [--yes]']);

CLI::register('recipe:export', '导出当前站点为配方 JSON', function (array $args, array $opts): int {
    $svc = new RecipeService();
    $manifest = $svc->exportCurrent([
        'include_contents' => !empty($opts['with-contents']),
        'name'             => is_string($opts['name'] ?? null) ? $opts['name'] : ('Exported ' . date('Y-m-d H:i')),
    ]);
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $out = is_string($opts['out'] ?? null) ? $opts['out'] : '';
    if ($out !== '') {
        if (file_put_contents($out, $json) === false) {
            CLI::err("写入失败：{$out}");
            return 1;
        }
        CLI::ok("已导出到 {$out}（" . round(strlen($json) / 1024, 1) . " KB）");
    } else {
        echo $json . "\n";
    }
    return 0;
}, ['usage' => 'recipe:export [--with-contents] [--name="My Site"] [--out=site.json]']);
