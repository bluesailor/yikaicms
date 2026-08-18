<?php
/** Blox single-page draft persistence. */

declare(strict_types=1);

final class BloxPageDraftModel extends Model
{
    protected string $table = 'blox_page_drafts';

    public function findByPageId(int $pageId): ?array
    {
        return $this->findBy('page_id', $pageId);
    }

    public function saveForPage(int $pageId, string $documentJson, int $adminId = 0): int
    {
        if ($pageId <= 0) {
            throw new InvalidArgumentException('invalid page id');
        }

        $now = time();
        $current = $this->findByPageId($pageId);
        if ($current) {
            $this->updateById((int) $current['id'], [
                'draft_data' => $documentJson,
                'admin_id' => $adminId,
                'updated_at' => $now,
            ]);
            return (int) $current['id'];
        }

        return (int) $this->create([
            'page_id' => $pageId,
            'draft_data' => $documentJson,
            'admin_id' => $adminId,
            'created_at' => $now,
            'updated_at' => $now,
            'published_at' => 0,
        ]);
    }

    public function markPublished(int $pageId, int $publishedAt): void
    {
        $current = $this->findByPageId($pageId);
        if ($current) {
            $this->updateById((int) $current['id'], ['published_at' => $publishedAt]);
        }
    }

    public function hasPublishedStorage(): bool
    {
        if (!db()->tableExists('blox_page_drafts')) {
            return false;
        }
        $table = $this->tableName();
        if (db()->isSqlite()) {
            foreach (db()->fetchAll("PRAGMA table_info('{$table}')") as $column) {
                if ((string) ($column['name'] ?? '') === 'published_data') {
                    return true;
                }
            }
            return false;
        }
        return db()->fetchOne(
            'SELECT 1 AS ok FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, 'published_data']
        ) !== null;
    }

    public function publishForPage(int $pageId, string $documentJson, int $adminId = 0): int
    {
        if (!$this->hasPublishedStorage()) {
            throw new RuntimeException(__('blox_channel_storage_missing'));
        }
        $id = $this->saveForPage($pageId, $documentJson, $adminId);
        $this->updateById($id, [
            'published_data' => $documentJson,
            'published_at' => time(),
        ]);
        return $id;
    }
}
