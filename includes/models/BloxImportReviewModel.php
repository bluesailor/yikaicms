<?php
/** Blox 导入检查-确认两段式的服务端待确认记录。 */

declare(strict_types=1);

final class BloxImportReviewModel extends Model
{
    protected string $table = 'blox_import_reviews';
    protected string $primaryKey = 'id';
    protected string $defaultOrder = 'created_at DESC';

    public function tableReady(): bool
    {
        try {
            db()->fetchAll('SELECT catalog_origin FROM ' . DB_PREFIX . $this->table . ' WHERE 1 = 0');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $row */
    public function insert(array $row): void
    {
        db()->insert($this->table, $row);
    }

    /** 原子领取：仅当未消费时置 consumed_at，返回是否领取成功（防并发双确认）。 */
    public function claim(string $id, int $now): bool
    {
        return db()->execute(
            'UPDATE ' . DB_PREFIX . $this->table
            . ' SET consumed_at = ? WHERE id = ? AND consumed_at = 0',
            [$now, $id]
        ) === 1;
    }

    /** 写入失败时归还领取（仅当尚未回填结果），用户修复映射后可直接重试。 */
    public function release(string $id): void
    {
        db()->execute(
            'UPDATE ' . DB_PREFIX . $this->table
            . ' SET consumed_at = 0 WHERE id = ? AND consumed_at > 0 AND result_ref = \'\'',
            [$id]
        );
    }

    /** 清理过期记录；只为当前管理员清，不扫全表。 */
    public function deleteExpired(int $adminId, int $now): void
    {
        db()->delete($this->table, 'admin_id = ? AND expires_at > 0 AND expires_at < ?', [$adminId, $now]);
    }

    /** @return list<array<string,mixed>> 未消费记录（按创建时间倒序）。 */
    public function pendingFor(int $adminId): array
    {
        return db()->fetchAll(
            'SELECT id FROM ' . DB_PREFIX . $this->table
            . ' WHERE admin_id = ? AND consumed_at = 0 ORDER BY created_at DESC',
            [$adminId]
        );
    }
}
