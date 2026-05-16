<?php
/**
 * 命令：backup
 *   备份所有 yikai_ 前缀表到 storage/backups/，或 --out=<file>。
 *   选项：
 *     --tables=t1,t2          只备份指定表（不含前缀和带前缀都可）
 *     --no-data               只备结构
 *     --no-structure          只备数据
 *     --out=<path>            自定义输出路径（绝对或相对当前目录）
 *     --stdout                直接打到 stdout（适合 `bin/yikai backup --stdout > x.sql`）
 */
declare(strict_types=1);

if (!defined('IK_CLI')) return;

require_once ROOT_PATH . '/includes/Backup.php';

CLI::register('backup', '备份数据库（默认所有 yikai_ 前缀表）', function (array $args, array $opts): int {
    // 表清单
    if (isset($opts['tables']) && is_string($opts['tables']) && $opts['tables'] !== '') {
        $tables = array_filter(array_map('trim', explode(',', $opts['tables'])));
        // 自动补前缀
        $tables = array_map(fn($t) => str_starts_with($t, DB_PREFIX) ? $t : DB_PREFIX . $t, $tables);
    } else {
        $tables = Backup::listPrefixedTables();
    }

    if (empty($tables)) {
        CLI::err('未找到任何表');
        return 1;
    }

    $structure = empty($opts['no-structure']);
    $data      = empty($opts['no-data']);

    CLI::info('开始备份 ' . count($tables) . ' 张表'
        . ' (structure=' . ($structure ? 'yes' : 'no')
        . ', data=' . ($data ? 'yes' : 'no') . ')...');

    $t0 = microtime(true);
    $sql = Backup::generateSql($tables, $structure, $data);
    $elapsed = round(microtime(true) - $t0, 2);

    if (!empty($opts['stdout'])) {
        echo $sql;
        return 0;
    }

    // 写文件
    if (isset($opts['out']) && is_string($opts['out']) && $opts['out'] !== '') {
        $path = $opts['out'];
        // 相对路径解析为相对 CWD
        if (!preg_match('#^([A-Za-z]:)?[\\\\/]#', $path)) {
            $path = getcwd() . '/' . $path;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (file_put_contents($path, $sql) === false) {
            CLI::err("写入失败：{$path}");
            return 1;
        }
    } else {
        $path = Backup::writeToBackupsDir($sql);
    }

    CLI::ok(sprintf(
        '备份完成：%s（%s KB，%d 张表，%ss）',
        $path,
        round(strlen($sql) / 1024, 1),
        count($tables),
        $elapsed
    ));
    return 0;
}, ['usage' => 'backup [--tables=t1,t2] [--no-data] [--no-structure] [--out=path.sql] [--stdout]']);
