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

    /**
     * 软删除开关。子类设为 true 时（表需有 deleted_at 列）：
     *   - deleteById / deleteByIds 改为写 deleted_at 时间戳（不物理删除）
     *   - restore / forceDeleteById / getTrashed / trashedCount 可用
     * 读方法的"排除已删"过滤由子类在各自查询里用 softDeleteGuard() 注入。
     */
    protected bool $softDelete = false;
    protected string $deletedAtColumn = 'deleted_at';

    // ═══════════════════════════════════════════════
    // 读取
    // ═══════════════════════════════════════════════

    /**
     * 按主键查单条。软删除模型自动排除回收站中的行
     *（详情页/编辑页据此对已删项返回 null；回收站的读取用 getTrashed，不走这里）。
     */
    public function find(int|string $id): ?array
    {
        return db()->fetchOne(
            "SELECT * FROM {$this->tableName()} WHERE {$this->primaryKey} = ?" . $this->softDeleteGuard(),
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
            "SELECT * FROM {$this->tableName()} WHERE {$whereSQL}" . $this->softDeleteGuard(),
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
        $guard = $this->softDeleteGuard();
        $where = $guard !== '' ? ' WHERE 1=1' . $guard : '';
        return db()->fetchAll(
            "SELECT * FROM {$this->tableName()}{$where} ORDER BY {$order}"
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
        $guard = $this->softDeleteGuard();
        if ($conditions) {
            [$whereSQL, $params] = $this->buildWhere($conditions);
            $sql .= " WHERE {$whereSQL}{$guard}";
        } elseif ($guard !== '') {
            $sql .= " WHERE 1=1{$guard}";
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
        // 方法级钩子（站点覆盖层可挂载）：入库前可改数据，入库后可响应。
        if (function_exists('apply_filters')) $data = apply_filters('model_before_create', $data, $this->table);
        $id = db()->insert($this->table, $data);
        if (function_exists('do_action')) {
            do_action('model_created', $this->table, $id, $data);
            do_action('data_changed', $this->table, $id);
        }
        return $id;
    }

    /**
     * 按 ID 更新
     */
    public function updateById(int|string $id, array $data): int
    {
        if (function_exists('apply_filters')) $data = apply_filters('model_before_update', $data, $this->table, $id);
        $r = db()->update($this->table, $data, "{$this->primaryKey} = ?", [$id]);
        if (function_exists('do_action')) {
            do_action('model_updated', $this->table, $id, $data);
            do_action('data_changed', $this->table, $id);
        }
        return $r;
    }

    /**
     * 条件更新
     */
    public function updateWhere(array $data, string $where, array $params = []): int
    {
        $r = db()->update($this->table, $data, $where, $params);
        if (function_exists('do_action')) do_action('data_changed', $this->table);
        return $r;
    }

    /**
     * 缺列友好报错：软删除写 deleted_at 时列不存在（文件已升级、数据库未升级的中间态），
     * 把晦涩的 SQL 异常转成指路提示；其它异常原样抛出。
     */
    private function raiseIfMissingSoftDeleteColumn(\Throwable $e): void
    {
        $msg = $e->getMessage();
        $isMissingCol = strpos($msg, $this->deletedAtColumn) !== false
            && (strpos($msg, '42S22') !== false || strpos($msg, '1054') !== false
                || stripos($msg, 'no such column') !== false || stripos($msg, 'Unknown column') !== false);
        if ($isMissingCol && function_exists('error')) {
            error("数据库结构未升级（缺少 {$this->table}.{$this->deletedAtColumn} 列），删除未执行。请到「系统 → 升级管理」运行数据库升级后重试。");
        }
    }

    /**
     * 按 ID 删除。软删除模型写 deleted_at（进回收站），否则物理删除。
     */
    public function deleteById(int|string $id): int
    {
        if (function_exists('do_action')) do_action('model_before_delete', $this->table, $id);
        if ($this->softDelete) {
            try {
                $r = db()->update($this->table, [$this->deletedAtColumn => time()], "{$this->primaryKey} = ?", [$id]);
            } catch (\Throwable $e) {
                $this->raiseIfMissingSoftDeleteColumn($e);
                throw $e;
            }
        } else {
            $r = db()->delete($this->table, "{$this->primaryKey} = ?", [$id]);
        }
        if (function_exists('do_action')) {
            do_action('model_deleted', $this->table, $id);
            do_action('data_changed', $this->table, $id);
        }
        return $r;
    }

    /**
     * 批量删除。软删除模型写 deleted_at，否则物理删除。
     */
    public function deleteByIds(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if ($this->softDelete) {
            $params = array_merge([time()], array_values($ids));
            try {
                $r = db()->execute(
                    "UPDATE {$this->tableName()} SET {$this->deletedAtColumn} = ? WHERE {$this->primaryKey} IN ({$placeholders})",
                    $params
                );
            } catch (\Throwable $e) {
                $this->raiseIfMissingSoftDeleteColumn($e);
                throw $e;
            }
        } else {
            $r = db()->execute(
                "DELETE FROM {$this->tableName()} WHERE {$this->primaryKey} IN ({$placeholders})",
                array_values($ids)
            );
        }
        if (function_exists('do_action')) do_action('data_changed', $this->table);
        return $r;
    }

    // ═══════════════════════════════════════════════
    // 软删除 / 回收站（仅 $softDelete = true 的模型）
    // ═══════════════════════════════════════════════

    /** 读查询里排除已删行的 WHERE 片段；非软删模型返回空串。$alias 如 'c.' */
    public function softDeleteGuard(string $alias = ''): string
    {
        return $this->softDelete ? " AND {$alias}{$this->deletedAtColumn} IS NULL" : '';
    }

    /** 从回收站恢复 */
    public function restore(int $id): int
    {
        if (!$this->softDelete) return 0;
        $r = db()->update($this->table, [$this->deletedAtColumn => null], "{$this->primaryKey} = ?", [$id]);
        if (function_exists('do_action')) do_action('data_changed', $this->table, $id);
        return $r;
    }

    /** 彻底删除（物理），仅对已在回收站的行生效 */
    public function forceDeleteById(int $id): int
    {
        $r = db()->delete($this->table, "{$this->primaryKey} = ?", [$id]);
        if (function_exists('do_action')) do_action('data_changed', $this->table, $id);
        return $r;
    }

    /** 回收站列表（已删行，按删除时间倒序） */
    public function getTrashed(int $limit = 50, int $offset = 0): array
    {
        if (!$this->softDelete) return [];
        return db()->fetchAll(
            "SELECT * FROM {$this->tableName()} WHERE {$this->deletedAtColumn} IS NOT NULL
             ORDER BY {$this->deletedAtColumn} DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    /** 回收站条数 */
    public function trashedCount(): int
    {
        if (!$this->softDelete) return 0;
        return (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM {$this->tableName()} WHERE {$this->deletedAtColumn} IS NOT NULL"
        );
    }

    /** 清空回收站中早于 $days 天的行（定时清理用），返回删除条数 */
    public function purgeTrashedOlderThan(int $days): int
    {
        if (!$this->softDelete || $days < 0) return 0;
        $cutoff = time() - $days * 86400;
        return db()->execute(
            "DELETE FROM {$this->tableName()} WHERE {$this->deletedAtColumn} IS NOT NULL AND {$this->deletedAtColumn} < ?",
            [$cutoff]
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
