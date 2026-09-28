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
    /** @var resource|null 本进程持有的执行锁 */
    private static $runLock = null;

    /** @return array{0:string,1:string} [锁文件, 运行信息文件]（在 storage/ 下，网站禁止直接访问） */
    private static function runLockPaths(): array
    {
        $dir = ROOT_PATH . '/storage';
        return [$dir . '/migrations.lock', $dir . '/migrations.running.json'];
    }

    /**
     * 开始执行迁移前取锁：后台、CLI 同一时刻只允许一处在跑，防止重复点击或多个标签页同时升级。
     * 用系统文件锁（flock），进程结束（包括中途崩溃、超时）时系统自动释放，不会卡在「运行中」。
     * 另一处正在执行时返回 false；存储目录不可写时不拦（锁只防重复执行，不能因此让升级跑不了）。
     */
    public static function beginRun(string $source): bool
    {
        if (self::$runLock !== null) return true;
        [$lockFile, $infoFile] = self::runLockPaths();
        if (!is_dir(dirname($lockFile))) @mkdir(dirname($lockFile), 0755, true);
        $handle = @fopen($lockFile, 'c');
        if ($handle === false) return true;
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        self::$runLock = $handle;
        @file_put_contents($infoFile, (string) json_encode(['started_at' => time(), 'source' => $source]));
        return true;
    }

    /**
     * 迁移执行完（成功或失败）释放锁，并让「待升级数量」缓存失效。
     * 后台「数据库升级」与 CLI migrate:run 都经这里收尾：控制台横幅和侧栏角标下一次请求就重新探测，
     * 不会在跑完后还显示旧数量（最长 60 秒）；部分失败时显示的是真实剩余数，而不是一律清零。
     * 存储不可写、没拿到锁文件时（beginRun 放行）同样要清缓存，所以清缓存不放在锁判断里。
     */
    public static function endRun(): void
    {
        if (self::$runLock !== null) {
            [, $infoFile] = self::runLockPaths();
            @unlink($infoFile);
            flock(self::$runLock, LOCK_UN);
            fclose(self::$runLock);
            self::$runLock = null;
        }
        if (function_exists('cacheDelete')) {
            cacheDelete('sidebar_pending_migrations');
        }
    }

    /**
     * 另一处正在执行迁移时返回其开始时间与来源（admin / cli），否则 null。只探测、不留锁。
     * @return null|array{started_at:int,source:string}
     */
    public static function runningInfo(): ?array
    {
        if (self::$runLock !== null) return null;
        [$lockFile, $infoFile] = self::runLockPaths();
        if (!is_file($lockFile)) return null;
        $handle = @fopen($lockFile, 'c');
        if ($handle === false) return null;
        $free = flock($handle, LOCK_EX | LOCK_NB);
        if ($free) flock($handle, LOCK_UN);
        fclose($handle);
        if ($free) return null;
        $info = json_decode((string) @file_get_contents($infoFile), true);
        return ['started_at' => (int) ($info['started_at'] ?? 0), 'source' => (string) ($info['source'] ?? '')];
    }

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
     * 这条 SQL 错误是不是「目标状态已达成」？
     *
     * 迁移按 check() 判定是否待跑，同一状态可能被两条迁移分别覆盖（老站升级时尤其常见），
     * 后跑的那条会撞上已存在的列/索引。MySQL 对重复索引报的是 `Duplicate key name`，
     * 与重复列的 `Duplicate column` 措辞不同——漏掉它会让老站的 migrate:run 中途卡死
     * （2026-09-26：ht-sshc 从 1.x 升 2.0.0 时被 idx_bn_trans 挡住）。与 _addIndex() 同口径。
     */
    public static function isIdempotentSqlError(string $message): bool
    {
        return (bool) preg_match('/duplicate column|duplicate key name|already exists|duplicate entry/i', $message);
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
                if (self::isIdempotentSqlError($msg)) {
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
