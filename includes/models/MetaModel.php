<?php
/**
 * Yikai CMS - 通用元数据 Model
 *
 * 参考 Z-BlogPHP {prefix}_metas 设计，为任意资源挂载键值对
 *
 * PHP 8.0+
 */

declare(strict_types=1);

class MetaModel extends Model
{
    protected string $table = 'metas';

    /** 自定义字段过滤算子白名单（v1.25 §7.2.1：第一版 AND 平铺 + 有限算子，嵌套 OR 组放 P2）。 */
    public const FILTER_OPS = ['=', '!=', '>', '>=', '<', '<=', 'like', 'in', 'between', 'empty'];
    /** 单查询最多过滤条数 / IN 候选值数。 */
    public const FILTER_MAX_ITEMS = 5;
    public const FILTER_MAX_IN = 10;

    /**
     * 把 `_meta_filters` 规格（{owner, items:[{field,op,value}…]}）编译为参数化
     * EXISTS 子查询，追加进 $where/$params（惯例同 ProductModel::applyFacetFilters）。
     * $alias 为外层行的列前缀（'c.' / 'p.'）；外层无别名时必须传「全表名.」
     * （如 tableName() . '.'）——EXISTS 内裸 `id` 会优先解析到子查询自己的
     * metas 表，关联条件静默失效。
     *
     * - 条件间 AND；meta 行缺失时 `!=`/`empty` 视为命中（语义：值不同 / 为空）。
     * - 数值比较（> >= < <= between）用 `meta_value+0` 归一，MySQL/SQLite 同语义。
     * - LIKE 用 `ESCAPE '!'`：'\' 在 MySQL 字符串字面量与 SQLite「ESCAPE 必须单字符」
     *   上行为不一致，'!' 两方言等价。
     * - $spec 来自 BloxLoopQuery 归一化后的存储，这里仍整表复验（防御纵深：
     *   字段名/算子不合白名单直接丢弃，值永远走参数位）。
     */
    public static function applyMetaFilters(mixed $spec, string $alias, array &$where, array &$params): void
    {
        if (!is_array($spec) || !is_string($spec['owner'] ?? null) || !is_array($spec['items'] ?? null)) {
            return;
        }
        $owner = $spec['owner'];
        $table = DB_PREFIX . 'metas';
        $applied = 0;
        foreach ($spec['items'] as $item) {
            if ($applied >= self::FILTER_MAX_ITEMS) {
                break;
            }
            if (!is_array($item)) {
                continue;
            }
            $field = (string) ($item['field'] ?? '');
            $op = (string) ($item['op'] ?? '');
            $value = (string) ($item['value'] ?? '');
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $field) !== 1 || !in_array($op, self::FILTER_OPS, true)) {
                continue;
            }
            $exists = "SELECT 1 FROM {$table} mf WHERE mf.owner_type = ? AND mf.owner_id = {$alias}id AND mf.meta_key = ?";
            switch ($op) {
                case 'empty':
                    $where[] = "NOT EXISTS ({$exists} AND mf.meta_value <> '')";
                    array_push($params, $owner, $field);
                    break;
                case '!=':
                    $where[] = "NOT EXISTS ({$exists} AND mf.meta_value = ?)";
                    array_push($params, $owner, $field, $value);
                    break;
                case '=':
                    $where[] = "EXISTS ({$exists} AND mf.meta_value = ?)";
                    array_push($params, $owner, $field, $value);
                    break;
                case 'like':
                    $where[] = "EXISTS ({$exists} AND mf.meta_value LIKE ? ESCAPE '!')";
                    array_push($params, $owner, $field, '%' . strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%');
                    break;
                case 'in':
                    $list = array_slice(array_values(array_filter(
                        array_map('trim', explode(',', $value)),
                        static fn (string $v): bool => $v !== ''
                    )), 0, self::FILTER_MAX_IN);
                    if ($list === []) {
                        continue 2;
                    }
                    $placeholders = implode(',', array_fill(0, count($list), '?'));
                    $where[] = "EXISTS ({$exists} AND mf.meta_value IN ({$placeholders}))";
                    $params = array_merge($params, [$owner, $field], $list);
                    break;
                case 'between':
                    $parts = array_map('trim', explode(',', $value));
                    if (count($parts) !== 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
                        continue 2;
                    }
                    // 参数位也 +0：PDO 默认按 TEXT 绑定，SQLite 里「数值 vs 文本」按
                    // 类型序比较（数值恒小于文本）而不是按值——两侧都归一才是数值比较
                    $where[] = "EXISTS ({$exists} AND (mf.meta_value+0) BETWEEN (?+0) AND (?+0))";
                    array_push($params, $owner, $field, (float) $parts[0], (float) $parts[1]);
                    break;
                default: // > >= < <=
                    if (!is_numeric($value)) {
                        continue 2;
                    }
                    $where[] = "EXISTS ({$exists} AND (mf.meta_value+0) {$op} (?+0))";
                    array_push($params, $owner, $field, (float) $value);
            }
            $applied++;
        }
    }

    public function get(string $ownerType, int $ownerId, string $key, mixed $default = null): mixed
    {
        $row = db()->fetchOne(
            "SELECT meta_value FROM {$this->tableName()} WHERE owner_type = ? AND owner_id = ? AND meta_key = ?",
            [$ownerType, $ownerId, $key]
        );
        return $row ? $row['meta_value'] : $default;
    }

    public function set(string $ownerType, int $ownerId, string $key, mixed $value): bool
    {
        $value = $value === null ? '' : (is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE));
        $now = time();
        $existing = db()->fetchOne(
            "SELECT id FROM {$this->tableName()} WHERE owner_type = ? AND owner_id = ? AND meta_key = ?",
            [$ownerType, $ownerId, $key]
        );
        if ($existing) {
            db()->update($this->table, [
                'meta_value' => $value,
                'updated_at' => $now,
            ], 'id = ?', [(int)$existing['id']]);
        } else {
            db()->insert($this->table, [
                'owner_type' => $ownerType,
                'owner_id'   => $ownerId,
                'meta_key'   => $key,
                'meta_value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        return true;
    }

    public function del(string $ownerType, int $ownerId, string $key = ''): int
    {
        if ($key !== '') {
            return db()->delete($this->table, 'owner_type = ? AND owner_id = ? AND meta_key = ?', [$ownerType, $ownerId, $key]);
        }
        return db()->delete($this->table, 'owner_type = ? AND owner_id = ?', [$ownerType, $ownerId]);
    }

    /**
     * 获取某资源的全部 meta，返回 [key => value]
     */
    public function getAllByOwner(string $ownerType, int $ownerId): array
    {
        $rows = db()->fetchAll(
            "SELECT meta_key, meta_value FROM {$this->tableName()} WHERE owner_type = ? AND owner_id = ?",
            [$ownerType, $ownerId]
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r['meta_key']] = $r['meta_value'];
        }
        return $out;
    }

    /**
     * 批量写入/更新
     *
     * @param array<string,mixed> $data key => value
     */
    public function setBatch(string $ownerType, int $ownerId, array $data): int
    {
        $count = 0;
        foreach ($data as $k => $v) {
            if (!is_string($k) || $k === '') continue;
            $this->set($ownerType, $ownerId, $k, $v);
            $count++;
        }
        return $count;
    }

    /**
     * 根据 owner_id 列表批量获取 meta，返回 [owner_id => [key => value]]
     */
    public function getAllByOwnerIds(string $ownerType, array $ownerIds): array
    {
        $ownerIds = array_values(array_unique(array_map('intval', $ownerIds)));
        if (empty($ownerIds)) return [];
        $placeholders = implode(',', array_fill(0, count($ownerIds), '?'));
        $rows = db()->fetchAll(
            "SELECT owner_id, meta_key, meta_value FROM {$this->tableName()} WHERE owner_type = ? AND owner_id IN ({$placeholders})",
            array_merge([$ownerType], $ownerIds)
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r['owner_id']][$r['meta_key']] = $r['meta_value'];
        }
        return $out;
    }
}
