<?php

/**
 * 只读检查安装/测试演示数据中的 Unicode 替换字符 U+FFFD。
 *
 * 用法：
 *   php tools/scan_demo_mojibake.php
 *   php tools/scan_demo_mojibake.php --files-only
 *
 * 文件扫描覆盖发布包和测试夹具；数据库扫描只执行 SELECT，
 * 仅检查中英日三种语言的常见演示内容字段，不提供写入选项。
 */

declare(strict_types=1);

const REPLACEMENT_CHAR = "ï¿½";

$root = dirname(__DIR__);
define('ROOT_PATH', $root);
$filesOnly = in_array('--files-only', $argv, true);
$issues = [];
ob_start();

$filePaths = [
    $root . '/install/sql/mysql.sql',
    $root . '/install/sql/sqlite.sql',
    $root . '/tests/fixtures/schema-baseline-sqlite.sql',
];
foreach (glob($root . '/install/seed_data_*.json') ?: [] as $path) {
    $filePaths[] = $path;
}
foreach (glob($root . '/lang/*.php') ?: [] as $path) {
    $filePaths[] = $path;
}

foreach (array_unique($filePaths) as $path) {
    if (!is_file($path)) {
        continue;
    }
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        $issues[] = '无法读取文件：' . str_replace($root . '/', '', $path);
        continue;
    }
    if (!str_contains($contents, REPLACEMENT_CHAR)) {
        continue;
    }

    $relative = str_replace($root . '/', '', str_replace(DIRECTORY_SEPARATOR, '/', $path));
    $line = substr_count(substr($contents, 0, (int) strpos($contents, REPLACEMENT_CHAR)), "\n") + 1;
    $issues[] = sprintf('文件 %s:%d 包含 U+FFFD', $relative, $line);
}

echo "文件扫描：检查 " . count(array_unique($filePaths)) . " 个发布/演示数据文件\n";

if (!$filesOnly) {
    $configPath = $root . '/config/config.php';
    if (!is_file($configPath)) {
        echo "数据库扫描：跳过（候选树没有 config/config.php）\n";
    } else {
        require_once $configPath;
        require_once $root . '/config/database.php';

        $db = Database::getInstance();
        $tables = [
            'contents',
            'channels',
            'products',
            'product_categories',
            'banners',
            'jobs',
            'downloads',
            'download_categories',
            'albums',
            'album_photos',
        ];
        $textColumns = [
            'title',
            'subtitle',
            'summary',
            'content',
            'description',
            'name',
            'slug',
            'keywords',
            'seo_title',
            'seo_keywords',
            'seo_description',
            'education',
            'experience',
            'requirements',
            'question',
            'answer',
        ];
        $languages = ['zh-CN', 'en', 'ja'];
        $scannedTables = 0;

        foreach ($tables as $table) {
            if (!$db->tableExists($table)) {
                continue;
            }
            $columns = replacementScanColumns($db, $table);
            $columns = array_values(array_intersect($textColumns, $columns));
            if ($columns === []) {
                continue;
            }

            $scannedTables++;
            $tableName = DB_PREFIX . $table;
            $select = ['id'];
            if (in_array('lang', replacementScanColumns($db, $table), true)) {
                $select[] = 'lang';
            }
            foreach ($columns as $column) {
                $select[] = $column;
            }

            $conditions = [];
            $params = [];
            foreach ($columns as $column) {
                $conditions[] = $column . ' LIKE ?';
                $params[] = '%' . REPLACEMENT_CHAR . '%';
            }
            $langClause = '';
            if (in_array('lang', $select, true)) {
                $langClause = ' AND lang IN (?, ?, ?)';
                array_push($params, ...$languages);
            }

            $sql = 'SELECT ' . implode(', ', $select)
                . ' FROM ' . $tableName
                . ' WHERE (' . implode(' OR ', $conditions) . ')' . $langClause;
            foreach ($db->fetchAll($sql, $params) as $row) {
                $id = (string) ($row['id'] ?? '?');
                $lang = (string) ($row['lang'] ?? '-');
                foreach ($columns as $column) {
                    $value = (string) ($row[$column] ?? '');
                    if (!str_contains($value, REPLACEMENT_CHAR)) {
                        continue;
                    }
                    $position = (int) strpos($value, REPLACEMENT_CHAR);
                    $start = max(0, $position - 18);
                    $snippet = trim(preg_replace('/\s+/u', ' ', substr($value, $start, 54)) ?? '');
                    $issues[] = sprintf(
                        '数据库 %s id=%s lang=%s 字段=%s：…%s…',
                        $table,
                        $id,
                        $lang,
                        $column,
                        $snippet
                    );
                }
            }
        }

        echo "数据库扫描：检查 {$scannedTables} 张三语演示数据表（只读 SELECT）\n";
    }
}

if ($issues === []) {
    echo "✓ 未发现 U+FFFD 替换字符。\n";
    ob_end_flush();
    exit(0);
}

echo "✗ 发现 " . count($issues) . " 项 U+FFFD：\n";
foreach ($issues as $issue) {
    echo "  - {$issue}\n";
}
ob_end_flush();
exit(1);

/**
 * @return list<string>
 */
function replacementScanColumns(Database $db, string $table): array
{
    if ($db->isSqlite()) {
        $rows = $db->fetchAll('PRAGMA table_info("' . $table . '")');
        return array_values(array_filter(array_map(
            static fn(array $row): string => (string) ($row['name'] ?? ''),
            $rows
        )));
    }

    $rows = $db->fetchAll('SHOW COLUMNS FROM ' . DB_PREFIX . $table);
    return array_values(array_filter(array_map(
        static fn(array $row): string => (string) ($row['Field'] ?? ''),
        $rows
    )));
}
