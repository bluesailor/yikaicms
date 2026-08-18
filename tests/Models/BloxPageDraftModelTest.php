<?php
/** Blox page draft persistence tests. */

declare(strict_types=1);

namespace Yikai\Tests\Models;

use Yikai\Tests\TestCase;

final class BloxPageDraftModelTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE blox_page_drafts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page_id INTEGER NOT NULL UNIQUE,
                draft_data TEXT NOT NULL,
                published_data TEXT,
                admin_id INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0,
                published_at INTEGER NOT NULL DEFAULT 0
            )",
        ];
    }

    public function testSaveForPageCreatesThenUpdatesOneDraft(): void
    {
        $id = bloxPageDraftModel()->saveForPage(12, '{"sections":[]}', 3);
        $updatedId = bloxPageDraftModel()->saveForPage(12, '{"sections":[{"id":"s1"}]}', 7);

        $this->assertSame($id, $updatedId);
        $row = bloxPageDraftModel()->findByPageId(12);
        $this->assertNotNull($row);
        $this->assertSame(7, (int) $row['admin_id']);
        $this->assertStringContainsString('s1', (string) $row['draft_data']);
        $this->assertSame(1, bloxPageDraftModel()->count());
    }

    public function testMarkPublishedDoesNotChangeDraft(): void
    {
        bloxPageDraftModel()->saveForPage(8, '[{"id":"draft"}]', 2);
        bloxPageDraftModel()->markPublished(8, 123456);

        $row = bloxPageDraftModel()->findByPageId(8);
        $this->assertSame(123456, (int) $row['published_at']);
        $this->assertSame('[{"id":"draft"}]', $row['draft_data']);
    }

    public function testChannelPublicationKeepsDraftAndPublishedDocumentTogether(): void
    {
        $id = bloxPageDraftModel()->publishForPage(18, '{"schema":1,"sections":[]}', 4);

        $row = bloxPageDraftModel()->findByPageId(18);
        $this->assertSame($id, (int) $row['id']);
        $this->assertSame($row['draft_data'], $row['published_data']);
        $this->assertGreaterThan(0, (int) $row['published_at']);
    }
}
