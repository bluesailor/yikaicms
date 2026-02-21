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
            implode(', ', $fields),
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
        $sets = array_map(fn($field) => "$field = ?", array_keys($data));

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
        return $this->pdo->commit();
    }

    /**
     * 回滚事务
     */
    public function rollback(): bool
    {
        return $this->pdo->rollBack();
    }

    /**
     * 检查表是否存在
     */
    public function tableExists(string $table): bool
    {
        $tableName = DB_PREFIX . $table;

        if ($this->driver === 'sqlite') {
            $sql = "SELECT name FROM sqlite_master WHERE type='table' AND name=?";
            return (bool) $this->fetchOne($sql, [$tableName]);
        }

        $sql = "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?";
        return (bool) $this->fetchOne($sql, [DB_NAME, $tableName]);
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
