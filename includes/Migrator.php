<?php
/**
 * Yikai CMS - 迁移执行器
 *
 * 从 admin/upgrade.php 抽出迁移相关工具，方便 admin/CLI 共享。
 *
 * 迁移文件格式（参考 migrations/README.md）：
 *   return [
 *       'id'    => '20260511_xxx',
 *       'title' => '简短标题',
 *       'desc'  => '详细描述',
 *       'check' => function (): bool { ... },  // true=已应用，跳过
 *       'sqls'  => ['ALTER TABLE ...', 'INSERT ...'],  // 顺序执行
 *       'php'   => function (): string { ... }, // 可选；返回成功消息
 *   ];
 */
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

// ─────────────────────────────────────────────
// 全局辅助函数：迁移文件的 check 闭包里常用
// admin/upgrade.php 已定义过同名函数则跳过（不冲突）。
// ─────────────────────────────────────────────
if (!function_exists('_columnExists')) {
    function _columnExists(string $table, string $column): bool
    {
        $tableName = DB_PREFIX . $table;
        if (db()->isSqlite()) {
            $cols = db()->fetchAll("PRAGMA table_info('{$tableName}')");
            foreach ($cols as $col) {
                if ($col['name'] === $column) return true;
            }
            return false;
        }
        $cols = db()->fetchAll("SHOW COLUMNS FROM `{$tableName}` LIKE '{$column}'");
        return !empty($cols);
    }
}

if (!function_exists('_sqlToSqlite')) {
    /**
     * 把 MySQL DDL 转为 SQLite 兼容语法。返回 null 表示该语句应跳过。
     */
    function _sqlToSqlite(string $sql): ?string
    {
        if (preg_match('/ALTER\s+TABLE\s+.*\s+ADD\s+(KEY|INDEX)\s+/i', $sql)) {
            return null;
        }
        $sql = preg_replace('/\)\s*ENGINE=.*$/i', ')', $sql);
        $sql = preg_replace('/\s+COMMENT\s+\'[^\']*\'/i', '', $sql);
        $sql = preg_replace('/\bUNSIGNED\b/i', '', $sql);
        $sql = preg_replace('/\bAUTO_INCREMENT\b/i', 'AUTOINCREMENT', $sql);
        $sql = preg_replace('/\bint\(\d+\)/i', 'INTEGER', $sql);
        $sql = preg_replace('/\bINTEGER\s+NOT\s+NULL\s+AUTOINCREMENT/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
        $sql = preg_replace('/\s+AFTER\s+`[^`]+`/i', '', $sql);
        $sql = preg_replace('/\bINSERT\s+IGNORE\b/i', 'INSERT OR IGNORE', $sql);
        if (stripos($sql, 'ON DUPLICATE KEY UPDATE') !== false) {
            $sql = preg_replace('/\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+.*$/is', '', $sql);
            $sql = preg_replace('/^\s*INSERT\s+INTO\s+/i', 'INSERT OR REPLACE INTO ', $sql, 1);
        }
        if (stripos($sql, 'AUTOINCREMENT') !== false) {
            $sql = preg_replace('/,\s*PRIMARY\s+KEY\s*\(`id`\)/i', '', $sql);
        }
        $sql = preg_replace('/,\s*KEY\s+`[^`]+`\s*\([^)]+\)/i', '', $sql);
        return $sql;
    }
}

class Migrator
{
    /**
     * 加载所有迁移定义 —— 后台「数据库升级」与 CLI migrate 的唯一来源。
     *
     * 合并两处（与 admin/upgrade.php 历史语义一致）：
     *   1. migrations/_inline_upgrades.php —— 遗留内联迁移包（return 一个迁移数组）；
     *   2. migrations/*.php —— 每文件一条独立迁移。
     * 同 id 时独立文件覆盖 inline 版（便于把 inline 条目逐步迁成文件而不改 id）。
     * inline 保持其内部顺序在前，文件新增条目追加其后。
     *
     * @return array<int, array>
     */
    public static function loadAll(): array
    {
        $dir = ROOT_PATH . '/migrations';
        if (!is_dir($dir)) return [];

        $byId = [];

        // 1. 遗留内联迁移包（整包 return 一个 list，不同于单条迁移文件）
        $bundle = $dir . '/_inline_upgrades.php';
        if (is_file($bundle)) {
            $arr = require $bundle;
            if (is_array($arr)) {
                foreach ($arr as $m) {
                    if (!is_array($m) || empty($m['id']) || empty($m['check'])) {
                        error_log('[migrator] invalid entry in _inline_upgrades.php');
                        continue;
                    }
                    $m['_file'] = '_inline_upgrades.php';
                    $byId[$m['id']] = $m;
                }
            }
        }

        // 2. 独立迁移文件（每文件一条），同 id 覆盖 inline
        $files = glob($dir . '/*.php') ?: [];
        sort($files);
        foreach ($files as $f) {
            if (basename($f) === '_inline_upgrades.php') continue;  // 已按整包处理
            $m = require $f;
            if (!is_array($m) || empty($m['id']) || empty($m['check'])) {
                error_log("[migrator] missing required keys: $f");
                continue;
            }
            $m['_file'] = basename($f);
            $byId[$m['id']] = $m;
        }

        return array_values($byId);
    }

    /**
     * 判断单个迁移是否已应用。
     */
    public static function isApplied(array $migration): bool
    {
        $check = $migration['check'] ?? null;
        if (!is_callable($check)) return false;
        try {
            return (bool)$check();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 执行单条迁移。
     * @return array{ok:bool, message:string, ran_sqls:int}
     */
    public static function runOne(array $migration): array
    {
        $isSqlite = db()->isSqlite();
        $ranSqls = 0;

        // sqls
        foreach (($migration['sqls'] ?? []) as $sql) {
            if (!is_string($sql) || trim($sql) === '') continue;
            $exec = $isSqlite ? _sqlToSqlite($sql) : $sql;
            if ($exec === null) continue;
            try {
                db()->execute($exec);
                $ranSqls++;
            } catch (\Throwable $e) {
                // 已存在的列/索引等幂等失败 → 忽略
                $msg = $e->getMessage();
                if (preg_match('/Duplicate column|duplicate column|already exists|duplicate entry/i', $msg)) {
                    continue;
                }
                return ['ok' => false, 'message' => 'SQL 失败：' . $msg, 'ran_sqls' => $ranSqls];
            }
        }

        // php callback
        $phpMsg = '';
        if (isset($migration['php']) && is_callable($migration['php'])) {
            try {
                $phpMsg = (string)call_user_func($migration['php']);
            } catch (\Throwable $e) {
                return ['ok' => false, 'message' => 'PHP 失败：' . $e->getMessage(), 'ran_sqls' => $ranSqls];
            }
        }

        return ['ok' => true, 'message' => $phpMsg ?: '完成', 'ran_sqls' => $ranSqls];
    }
}
