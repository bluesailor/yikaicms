<?php

declare(strict_types=1);

use Yikai\Tests\TestCase;

final class PageBloxLegacyImportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE channels (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                lang TEXT NOT NULL,
                name TEXT NOT NULL,
                content TEXT,
                updated_at INTEGER NOT NULL DEFAULT 0
            )',
            'CREATE TABLE contents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channel_id INTEGER NOT NULL,
                status INTEGER NOT NULL DEFAULT 1,
                deleted_at INTEGER,
                is_top INTEGER NOT NULL DEFAULT 0,
                content_type TEXT NOT NULL DEFAULT \'html\',
                content TEXT,
                blocks_data TEXT
            )',
            'CREATE TABLE blox_page_drafts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                page_id INTEGER NOT NULL UNIQUE,
                draft_data TEXT NOT NULL,
                admin_id INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0,
                published_at INTEGER NOT NULL DEFAULT 0
            )',
        ];
    }

    public function testLegacyRichTextIsSeededWithoutWritingADraft(): void
    {
        $pageId = $this->insertRow('channels', [
            'type' => 'page',
            'lang' => 'zh-CN',
            'name' => 'Legacy page',
            'content' => '',
            'updated_at' => 1,
        ]);
        $this->insertRow('contents', [
            'channel_id' => $pageId,
            'status' => 1,
            'deleted_at' => null,
            'is_top' => 0,
            'content_type' => 'html',
            'content' => '<h2>Existing heading</h2><p>Existing body</p>',
            'blocks_data' => null,
        ]);

        $state = PageBloxDocument::load($pageId);
        $document = BloxDocumentPipeline::decode($state['document_json']);

        self::assertFalse($state['has_draft']);
        self::assertFalse($state['has_published']);
        self::assertSame('text', $document['sections'][0]['columns'][0]['elements'][0]['type']);
        self::assertSame(
            '<h2>Existing heading</h2><p>Existing body</p>',
            $document['sections'][0]['columns'][0]['elements'][0]['data']['html']
        );
        self::assertNull(bloxPageDraftModel()->findByPageId($pageId));
    }
}
