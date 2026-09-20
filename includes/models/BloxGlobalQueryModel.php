<?php
/** Blox 全局查询数据模型（v1.25 Phase 2）。 */

declare(strict_types=1);

final class BloxGlobalQueryModel extends Model
{
    protected string $table = 'blox_global_queries';
    protected string $defaultOrder = 'updated_at DESC, id DESC';

    /** @return array<int,array<string,mixed>> */
    public function allActive(): array
    {
        return db()->fetchAll(
            'SELECT * FROM ' . DB_PREFIX . $this->table . ' ORDER BY name ASC'
        );
    }

    /** @return array<string,mixed>|null */
    public function findByQueryId(string $queryId): ?array
    {
        return db()->fetchOne(
            'SELECT * FROM ' . DB_PREFIX . $this->table . ' WHERE query_id = ? LIMIT 1',
            [$queryId]
        );
    }

    /** @return array<string,mixed>|null */
    public function findByName(string $name): ?array
    {
        return db()->fetchOne(
            'SELECT * FROM ' . DB_PREFIX . $this->table . ' WHERE name = ? LIMIT 1',
            [$name]
        );
    }
}
