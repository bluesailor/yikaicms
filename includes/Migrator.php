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
        // 用 information_schema 精确匹配，不走 LIKE。
        //
        // 原写法是 SHOW COLUMNS ... LIKE '{$column}'，列名里的 `_` 会被当通配符：
        // 'deleted_at' 连 'deletedXat' 一起匹配，_columnExists() 就可能对**不存在的列**
        // 返回 true。在迁移里这意味着「误判已应用 → 跳过 → 列没建 → 上线 500」。
        // 而 SHOW 语句又不接受占位符（SHOW COLUMNS ... LIKE ? 在 MySQL 上直接 1064），
        // 想转义就只能拼字符串。information_schema 支持参数化且是精确比较，两个问题一起没了。
        $row = db()->fetchOne(
            'SELECT 1 AS ok FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$tableName, $column]
        );
        return $row !== null;
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
        // UNIQUE KEY `名` (列) → UNIQUE (列)：SQLite 不认命名的 KEY 子句，但支持匿名 UNIQUE，
        // 保住唯一约束。必须排在下面删 KEY 之前——否则 `UNIQUE KEY` 会被当成普通索引整段丢掉。
        $sql = preg_replace('/\bUNIQUE\s+KEY\s+`[^`]+`\s*(\([^)]+\))/i', 'UNIQUE $1', $sql);
        // 普通索引没有内联等价写法，只能丢；建表后如需索引另发 CREATE INDEX。
        $sql = preg_replace('/,\s*KEY\s+`[^`]+`\s*\([^)]+\)/i', '', $sql);
        return $sql;
    }
}

if (!function_exists('_addColumn')) {
    /**
     * 幂等加列，两种驱动都安全。
     *
     * 迁移在 'sqls' 里写的语句会自动过 _sqlToSqlite()，但在 'php' 回调里直接
     * db()->execute() 拼 DDL 的则不会——MySQL 的 COMMENT / UNSIGNED / AFTER
     * 会让 SQLite 站直接报语法错。加列请一律走本函数。
     *
     * @param string $def MySQL 写法的列定义，如 "varchar(10) NOT NULL DEFAULT '' COMMENT '语言'"
     * @return bool true=本次新增，false=已存在
     */
    function _addColumn(string $table, string $column, string $def): bool
    {
        if (_columnExists($table, $column)) {
            return false;
        }
        $sql = 'ALTER TABLE `' . DB_PREFIX . $table . '` ADD COLUMN `' . $column . '` ' . $def;
        db()->execute(db()->isSqlite() ? (_sqlToSqlite($sql) ?? $sql) : $sql);
        return true;
    }
}

if (!function_exists('_addIndex')) {
    /**
     * 幂等建索引。MySQL 用 ALTER ADD INDEX，SQLite 用 CREATE INDEX IF NOT EXISTS。
     * 索引重名等幂等失败视为成功；其余异常照抛，别把真错误吞掉。
     *
     * @param string $cols 列清单，如 "`translation_group_id`"
     */
    function _addIndex(string $table, string $name, string $cols): void
    {
        $full = DB_PREFIX . $table;
        try {
            db()->execute(db()->isSqlite()
                ? "CREATE INDEX IF NOT EXISTS `{$name}` ON `{$full}` ({$cols})"
                : "ALTER TABLE `{$full}` ADD INDEX `{$name}` ({$cols})");
        } catch (\Throwable $e) {
            if (!preg_match('/already exists|Duplicate key name|duplicate/i', $e->getMessage())) {
                throw $e;
            }
        }
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
    /**
     * 迁移的标题/描述按站点语言取值。
     *
     * 迁移文件可另给 title_en / title_ja / desc_en / desc_ja，取不到就回落中文原文。
     * 为什么把译文放在迁移文件里而不是 lang/：迁移是随版本走的一次性资产，
     * 装完就再不会变；把它的文案塞进语言包，会让语言包无限膨胀且永远清理不掉。
     *
     * @param array<string,mixed> $migration
     */
    public static function label(array $migration, string $field = 'title'): string
    {
        $lang = function_exists('siteLang') ? siteLang() : 'zh-CN';
        if ($lang !== 'zh-CN') {
            $suffixed = $migration[$field . '_' . str_replace('-', '_', $lang)] ?? null;
            if (is_string($suffixed) && trim($suffixed) !== '') {
                return $suffixed;
            }
        }
        $base = $migration[$field] ?? '';
        return is_string($base) ? $base : '';
    }

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
