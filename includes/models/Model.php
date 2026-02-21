<?php
/**
 * Yikai CMS - Model 基类
 *
 * 提供通用 CRUD 操作，所有 Model 子类继承此类。
 * 基于现有 Database 类（db() 单例），不替换，只封装。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

class Model
{
    /** 表名（不含前缀） */
    protected string $table = '';

    /** 主键字段 */
    protected string $primaryKey = 'id';

    /** 默认排序 */
    protected string $defaultOrder = 'id DESC';

    // ═══════════════════════════════════════════════
    // 读取
    // ═══════════════════════════════════════════════

    /**
     * 按主键查单条
     */
    public function find(int $id): ?array
    {
        return db()->fetchOne(
            "SELECT * FROM {$this->tableName()} WHERE {$this->primaryKey} = ?",
            [$id]
        );
    }

    /**
     * 按某字段查单条
     */
    public function findBy(string $column, mixed $value): ?array
    {
        $this->assertColumnName($column);
        return db()->fetchOne(
            "SELECT * FROM {$this->tableName()} WHERE {$column} = ?",
            [$value]
        );
    }

    /**
     * 按条件数组查单条
     * 例: findWhere(['status' => 1, 'slug' => 'about'])
     */
    public function findWhere(array $conditions): ?array
    {
        [$whereSQL, $params] = $this->buildWhere($conditions);
        return db()->fetchOne(
            "SELECT * FROM {$this->tableName()} WHERE {$whereSQL}",
            $params
        );
    }

    /**
     * 查全部
     */
    public function all(string $orderBy = ''): array
    {
        $order = $orderBy ?: $this->defaultOrder;
        $this->assertOrderBy($order);
        return db()->fetchAll(
            "SELECT * FROM {$this->tableName()} ORDER BY {$order}"
        );
    }

    /**
     * 条件查询列表
     */
    public function where(array $conditions = [], string $orderBy = '', int $limit = 0, int $offset = 0): array
    {
        $order = $orderBy ?: $this->defaultOrder;
        $this->assertOrderBy($order);
        $params = [];

        $sql = "SELECT * FROM {$this->tableName()}";
        if ($conditions) {
            [$whereSQL, $params] = $this->buildWhere($conditions);
            $sql .= " WHERE {$whereSQL}";
        }
        $sql .= " ORDER BY {$order}";
        if ($limit > 0) {
            $sql .= " LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
        }

        return db()->fetchAll($sql, $params);
    }

    /**
     * 分页查询
     * 返回 ['items' => [...], 'total' => int]
     */
    public function paginate(int $page, int $perPage = 20, array $conditions = [], string $orderBy = ''): array
    {
        $total = $this->count($conditions);
        $offset = ($page - 1) * $perPage;
        $items = $this->where($conditions, $orderBy, $perPage, $offset);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * 计数
     */
    public function count(array $conditions = []): int
    {
        $params = [];
        $sql = "SELECT COUNT(*) FROM {$this->tableName()}";
        if ($conditions) {
            [$whereSQL, $params] = $this->buildWhere($conditions);
            $sql .= " WHERE {$whereSQL}";
        }
        return (int) db()->fetchColumn($sql, $params);
    }

    /**
     * 自定义 SQL 查多条
     */
    public function query(string $sql, array $params = []): array
    {
        return db()->fetchAll($sql, $params);
    }

    /**
     * 自定义 SQL 查单条
     */
    public function queryOne(string $sql, array $params = []): ?array
    {
        return db()->fetchOne($sql, $params);
    }

    /**
     * 自定义 SQL 查单值
     */
    public function queryColumn(string $sql, array $params = []): mixed
    {
        return db()->fetchColumn($sql, $params);
    }

    // ═══════════════════════════════════════════════
    // 写入
    // ═══════════════════════════════════════════════

    /**
     * 插入，返回新 ID
     */
    public function create(array $data): int|string
    {
        return db()->insert($this->table, $data);
    }

    /**
     * 按 ID 更新
     */
    public function updateById(int $id, array $data): int
    {
        return db()->update($this->table, $data, "{$this->primaryKey} = ?", [$id]);
    }

    /**
     * 条件更新
     */
    public function updateWhere(array $data, string $where, array $params = []): int
    {
        return db()->update($this->table, $data, $where, $params);
    }

    /**
     * 按 ID 删除
     */
    public function deleteById(int $id): int
    {
        return db()->delete($this->table, "{$this->primaryKey} = ?", [$id]);
    }

    /**
     * 批量删除
     */
    public function deleteByIds(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return db()->execute(
            "DELETE FROM {$this->tableName()} WHERE {$this->primaryKey} IN ({$placeholders})",
            array_values($ids)
        );
    }

    /**
     * 切换字段 0 <-> 1，返回新值
     */
    public function toggle(int $id, string $field): int
    {
        $this->assertColumnName($field);
        $row = $this->find($id);
        if (!$row) {
            return 0;
        }
        $newVal = ((int) $row[$field]) ? 0 : 1;
        $this->updateById($id, [$field => $newVal]);
        return $newVal;
    }

    /**
     * 自增某字段
     */
    public function increment(int $id, string $column, int $amount = 1): int
    {
        $this->assertColumnName($column);
        return db()->execute(
            "UPDATE {$this->tableName()} SET {$column} = {$column} + ? WHERE {$this->primaryKey} = ?",
            [$amount, $id]
        );
    }

    // ═══════════════════════════════════════════════
    // 辅助
    // ═══════════════════════════════════════════════

    /**
     * 返回带前缀的完整表名
     */
    public function tableName(): string
    {
        return DB_PREFIX . $this->table;
    }

    /**
     * 检查 slug 是否唯一
     */
    public function isSlugUnique(string $slug, int $excludeId = 0): bool
    {
        $sql = "SELECT {$this->primaryKey} FROM {$this->tableName()} WHERE slug = ?";
        $params = [$slug];
        if ($excludeId > 0) {
            $sql .= " AND {$this->primaryKey} != ?";
            $params[] = $excludeId;
        }
        return !db()->fetchOne($sql, $params);
    }

    /**
     * 将条件数组转为 WHERE 子句
     * ['status' => 1, 'type' => 'page'] => ["status = ? AND type = ?", [1, 'page']]
     */
    protected function buildWhere(array $conditions): array
    {
        $parts = [];
        $params = [];
        foreach ($conditions as $col => $val) {
            $this->assertColumnName($col);
            if (is_null($val)) {
                $parts[] = "{$col} IS NULL";
            } else {
                $parts[] = "{$col} = ?";
                $params[] = $val;
            }
        }
        return [implode(' AND ', $parts), $params];
    }

    /**
     * 校验列名合法性（防止 SQL 注入）
     * 允许: 字母、数字、下划线，以及 table.column 格式
     */
    protected function assertColumnName(string $name): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/', $name)) {
            throw new \InvalidArgumentException("Invalid column name: {$name}");
        }
    }

    /**
     * 校验 ORDER BY 子句合法性
     * 允许: "id DESC", "sort_order ASC, id DESC", "RAND()", "RANDOM()" 等安全格式
     */
    protected function assertOrderBy(string $orderBy): void
    {
        $parts = preg_split('/\s*,\s*/', trim($orderBy));
        foreach ($parts as $part) {
            $part = trim($part);
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*\s+(ASC|DESC)$/i', $part)
                && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_.]*$/i', $part)
                && !preg_match('/^RAND(OM)?\(\)$/i', $part)) {
                throw new \InvalidArgumentException("Invalid ORDER BY clause: {$orderBy}");
            }
        }
    }
}
