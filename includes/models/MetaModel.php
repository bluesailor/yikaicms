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
