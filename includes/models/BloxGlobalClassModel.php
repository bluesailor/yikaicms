<?php
/** Blox 全局样式类数据模型（v1.23 设计系统 Phase 1）。 */

declare(strict_types=1);

final class BloxGlobalClassModel extends Model
{
    protected string $table = 'blox_global_classes';
    protected string $defaultOrder = 'updated_at DESC, id DESC';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_TRASHED = 'trashed';

    /** @return array<int,array<string,mixed>> */
    public function allByStatus(string $status): array
    {
        return db()->fetchAll(
            'SELECT * FROM ' . DB_PREFIX . $this->table . ' WHERE status = ? ORDER BY name ASC',
            [$status]
        );
    }

    /** @return array<string,mixed>|null */
    public function findByClassId(string $classId): ?array
    {
        return db()->fetchOne(
            'SELECT * FROM ' . DB_PREFIX . $this->table . ' WHERE class_id = ? LIMIT 1',
            [$classId]
        );
    }

    /** @return array<string,mixed>|null */
    public function findActiveByName(string $name): ?array
    {
        return db()->fetchOne(
            'SELECT * FROM ' . DB_PREFIX . $this->table . " WHERE name = ? AND status = 'active' LIMIT 1",
            [$name]
        );
    }
}
