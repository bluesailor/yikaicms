<?php
/**
 * 命令组：migrate
 *   migrate:list   列出所有迁移及状态（已应用 / 待跑）
 *   migrate:run    跑所有 check=false 的迁移；--yes 跳过确认；--id=<id> 只跑指定一条
 */
declare(strict_types=1);

if (!defined('IK_CLI')) return;

require_once ROOT_PATH . '/includes/Migrator.php';

CLI::register('migrate:list', '列出迁移文件及应用状态', function (array $args, array $opts): int {
    $all = Migrator::loadAll();
    if (empty($all)) {
        CLI::info('没有迁移文件（migrations/*.php）');
        return 0;
    }
    $applied = 0;
    $pending = 0;
    foreach ($all as $m) {
        $is = Migrator::isApplied($m);
        if ($is) $applied++; else $pending++;
        $tag = $is ? '[applied]' : '[pending]';
        CLI::out(sprintf("  %-9s  %-40s  %s", $tag, $m['id'], $m['title'] ?? ''));
    }
    CLI::out('');
    CLI::out("汇总：{$applied} 已应用 / {$pending} 待跑（共 " . count($all) . " 条）");
    return 0;
}, ['usage' => 'migrate:list']);

CLI::register('migrate:run', '执行待跑的迁移', function (array $args, array $opts): int {
    $all = Migrator::loadAll();
    if (empty($all)) {
        CLI::info('没有迁移文件');
        return 0;
    }

    $idFilter = isset($opts['id']) && is_string($opts['id']) ? $opts['id'] : '';
    $pending = [];
    foreach ($all as $m) {
        if ($idFilter !== '' && $m['id'] !== $idFilter) continue;
        if (Migrator::isApplied($m)) continue;
        $pending[] = $m;
    }

    if (empty($pending)) {
        CLI::ok($idFilter !== '' ? "迁移 {$idFilter} 已应用或不存在" : '没有待跑的迁移');
        return 0;
    }

    CLI::out('即将执行 ' . count($pending) . ' 条迁移：');
    foreach ($pending as $m) {
        CLI::out("  • {$m['id']}  {$m['title']}");
    }
    CLI::out('');

    if (empty($opts['yes']) && !CLI::confirm('继续？', false)) {
        CLI::info('已取消');
        return 0;
    }

    $okCount = 0;
    $failCount = 0;
    foreach ($pending as $m) {
        printf("  → %-40s ", $m['id']);
        $r = Migrator::runOne($m);
        if ($r['ok']) {
            echo "\033[32mOK\033[0m  ({$r['ran_sqls']} sqls" . ($r['message'] !== '完成' ? ' / ' . $r['message'] : '') . ")\n";
            $okCount++;
        } else {
            echo "\033[31mFAIL\033[0m  " . $r['message'] . "\n";
            $failCount++;
            if (empty($opts['continue-on-error'])) {
                CLI::err('已中止（加 --continue-on-error 可跳过失败继续）');
                return 2;
            }
        }
    }
    CLI::out('');
    CLI::ok("完成：成功 {$okCount}，失败 {$failCount}");
    return $failCount > 0 ? 2 : 0;
}, ['usage' => 'migrate:run [--id=<migration_id>] [--yes] [--continue-on-error]']);
