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
}
