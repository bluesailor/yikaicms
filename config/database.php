<?php
/**
 * Yikai CMS - 数据库类
 *
 * 支持 MySQL 5.7+ 和 SQLite 3
 * PHP 8.0+
 *
 * @author  Yikai CMS
 * @version 1.0
 */

declare(strict_types=1);

// 防止直接访问
if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

/**
 * 数据库连接类
 * 支持 MySQL 和 SQLite
 */
class Database
{
    private static ?self $instance = null;
    private PDO $pdo;
    private string $driver;
    /** @var array<string,true> 已确认存在的表（本连接生命周期内） */
    private array $existingTables = [];
    /** @var list<array{0:callable,1:?callable}> 事务提交后执行的回调（回滚时丢弃） */
    private array $afterCommit = [];

    private function __construct()
    {
        $this->driver = defined('DB_DRIVER') ? DB_DRIVER : 'mysql';

        try {
            match ($this->driver) {
                'sqlite' => $this->connectSqlite(),
                default => $this->connectMysql(),
            };
        } catch (PDOException $e) {
            $message = (defined('DEBUG') && DEBUG)
                ? '数据库连接失败: ' . $e->getMessage()
                : '数据库连接失败，请检查配置';
            throw new RuntimeException($message, 0, $e);
        }
    }

    /**
     * 连接 MySQL 数据库
     */
    private function connectMysql(): void
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES ' . DB_CHARSET
        ]);
    }

    /**
     * 连接 SQLite 数据库
     */
    private function connectSqlite(): void
    {
        $dbPath = defined('DB_PATH') ? DB_PATH : ROOT_PATH . '/storage/database.sqlite';

        // 确保目录存在
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->pdo = new PDO('sqlite:' . $dbPath, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        // 启用外键约束
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    /**
     * 获取数据库实例（单例模式）
     */
    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * 获取数据库驱动类型
     */
    public function getDriver(): string
    {
        return $this->driver;
    }

    /**
     * 是否为 MySQL
     */
    public function isMysql(): bool
    {
        return $this->driver === 'mysql';
    }

    /**
     * 是否为 SQLite
     */
    public function isSqlite(): bool
    {
        return $this->driver === 'sqlite';
    }

    /**
     * 获取PDO对象
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * 执行查询，返回所有结果
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * 执行查询，返回单条结果
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * 执行查询，返回单个值
     */
    public function fetchColumn(string $sql, array $params = []): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    /**
     * 执行语句，返回影响行数
     */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * 插入数据，返回自增ID
     */
    public function insert(string $table, array $data): int|string
    {
        $fields = array_keys($data);
        $placeholders = array_fill(0, count($fields), '?');

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            DB_PREFIX . $table,
            implode(', ', array_map(fn($f) => "`$f`", $fields)),
            implode(', ', $placeholders)
        );

        $this->execute($sql, array_values($data));
        return $this->pdo->lastInsertId();
    }

    /**
     * 批量插入数据
     */
    public function insertBatch(string $table, array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $fields = array_keys($rows[0]);
        $placeholders = '(' . implode(', ', array_fill(0, count($fields), '?')) . ')';
        $allPlaceholders = implode(', ', array_fill(0, count($rows), $placeholders));

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s',
            DB_PREFIX . $table,
            implode(', ', $fields),
            $allPlaceholders
        );

        $params = [];
        foreach ($rows as $row) {
            $params = [...$params, ...array_values($row)];
        }

        return $this->execute($sql, $params);
    }

    /**
     * 更新数据
     */
    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = array_map(fn($field) => "`$field` = ?", array_keys($data));

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            DB_PREFIX . $table,
            implode(', ', $sets),
            $where
        );

        return $this->execute($sql, [...array_values($data), ...$whereParams]);
    }

    /**
     * 删除数据
     */
    public function delete(string $table, string $where, array $params = []): int
    {
        $sql = sprintf('DELETE FROM %s WHERE %s', DB_PREFIX . $table, $where);
        return $this->execute($sql, $params);
    }

    /**
     * 开始事务
     */
    public function beginTransaction(): bool
    {
        return $this->pdo->beginTransaction();
    }

    /**
     * 提交事务
     */
    public function commit(): bool
    {
        $ok = $this->pdo->commit();
        $callbacks = $this->afterCommit;
        $this->afterCommit = [];
        foreach ($callbacks as [$onCommit, $onRollback]) {
            $ok ? $onCommit() : ($onRollback !== null ? $onRollback() : null);
        }
        return $ok;
    }

    /**
     * 回滚事务
     */
    public function rollback(): bool
    {
        $callbacks = $this->afterCommit;
        $this->afterCommit = [];
        $ok = $this->pdo->rollBack();
        foreach ($callbacks as [, $onRollback]) {
            if ($onRollback !== null) {
                $onRollback();
            }
        }
        return $ok;
    }

    /**
     * 提交后执行：不在事务中立即执行；事务中登记到提交成功后，回滚则丢弃。
     * 用于页面缓存失效等副作用，避免在数据提交前让并发请求把旧内容重新缓存。
     */
    public function afterCommit(callable $onCommit, ?callable $onRollback = null): void
    {
        if (!$this->pdo->inTransaction()) {
            $onCommit();
            return;
        }
        $this->afterCommit[] = [$onCommit, $onRollback];
    }

    /**
     * 检查表是否存在。
     *
     * 注意：$table 传【不带前缀】的表名（与 insert/update/delete/insertBatch 一致，
     * 方法内部会自动拼接 DB_PREFIX）。例：tableExists('albums')；
     * 不要写成 tableExists(DB_PREFIX . 'albums')，否则会双前缀而永远返回 false。
     * （fetchAll/fetchOne/execute 走原始 SQL，需自行写 DB_PREFIX。）
     */
    public function tableExists(string $table): bool
    {
        $tableName = DB_PREFIX . $table;
        // 只缓存「存在」：后台一页会反复问同一张表（实测 50+ 次）。表不会在请求中途消失，
        // 而「不存在」可能被同一请求里的迁移补建，所以不缓存否定结果。
        if (isset($this->existingTables[$tableName])) {
            return true;
        }

        if ($this->driver === 'sqlite') {
            $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name=?";
            $exists = (bool) $this->fetchOne($sql, [$tableName]);
        } else {
            $sql = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?";
            $exists = (bool) $this->fetchOne($sql, [DB_NAME, $tableName]);
        }
        if ($exists) {
            $this->existingTables[$tableName] = true;
        }
        return $exists;
    }

    /**
     * 执行多条 SQL 语句
     */
    public function executeMultiple(string $sql): bool
    {
        return $this->pdo->exec($sql) !== false;
    }

    // 防止克隆
    private function __clone() {}
}

/**
 * 获取数据库实例的快捷函数
 */
function db(): Database
{
    return Database::getInstance();
}
