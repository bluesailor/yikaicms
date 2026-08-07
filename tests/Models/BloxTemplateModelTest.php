<?php
/** Blox 模板模型草稿/发布行为。 */

declare(strict_types=1);

namespace Yikai\Tests\Models;

use InvalidArgumentException;
use Yikai\Tests\TestCase;

final class BloxTemplateModelTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE blox_templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                name TEXT NOT NULL,
                source TEXT NOT NULL DEFAULT 'user',
                source_ref TEXT NOT NULL DEFAULT '',
                schema_version INTEGER NOT NULL DEFAULT 1,
                draft_data TEXT NOT NULL,
                published_data TEXT,
                requirements TEXT,
                thumbnail TEXT NOT NULL DEFAULT '',
                status INTEGER NOT NULL DEFAULT 0,
                admin_id INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0,
                published_at INTEGER NOT NULL DEFAULT 0
            )",
        ];
    }

    public function testCreateDraftAndPublishCopiesTheDraft(): void
    {
        $id = bloxTemplateModel()->createDraft(
            'header',
            '默认网页头',
            '[{"id":"s1","columns":[]}]',
            'import',
            1,
            ['elements' => ['nav', 'heading'], 'plugins' => []],
            '/uploads/templates/header.jpg',
            7
        );

        $row = bloxTemplateModel()->find($id);
        $this->assertSame('header', $row['type']);
        $this->assertSame('import', $row['source']);
        $this->assertSame(0, (int) $row['status']);
        $this->assertSame(
            ['elements' => ['heading', 'nav'], 'plugins' => []],
            json_decode((string) $row['requirements'], true)
        );

        bloxTemplateModel()->publishDraft($id);
        $published = bloxTemplateModel()->find($id);
        $this->assertSame(1, (int) $published['status']);
        $this->assertSame($published['draft_data'], $published['published_data']);
        $this->assertGreaterThan(0, (int) $published['published_at']);
    }

    public function testCatalogCanFilterByTypeWithoutReturningLargeJson(): void
    {
        bloxTemplateModel()->createDraft('section', '区块', '[]');
        bloxTemplateModel()->createDraft('footer', '页尾', '[]');

        $rows = bloxTemplateModel()->catalog('section');
        $this->assertCount(1, $rows);
        $this->assertSame('区块', $rows[0]['name']);
        $this->assertArrayNotHasKey('draft_data', $rows[0]);
    }

    public function testFindForExportReturnsLargeJsonFields(): void
    {
        $id = bloxTemplateModel()->createDraft('section', 'Exportable', '[{"id":"s1","columns":[]}]');
        bloxTemplateModel()->publishDraft($id);

        $row = bloxTemplateModel()->findForExport($id);
        $this->assertNotNull($row);
        $this->assertSame('[{"id":"s1","columns":[]}]', $row['draft_data']);
        $this->assertSame($row['draft_data'], $row['published_data']);
        $this->assertArrayHasKey('requirements', $row);
    }
    public function testEditorCatalogOnlyReturnsPublishedSectionAndPageTemplates(): void
    {
        $sectionId = bloxTemplateModel()->createDraft('section', 'Published section', '[]');
        $pageId = bloxTemplateModel()->createDraft('page', 'Published page', '[]');
        $draftId = bloxTemplateModel()->createDraft('section', 'Draft section', '[]');
        $headerId = bloxTemplateModel()->createDraft('header', 'Published header', '[]');

        bloxTemplateModel()->publishDraft($sectionId);
        bloxTemplateModel()->publishDraft($pageId);
        bloxTemplateModel()->publishDraft($headerId);

        $rows = bloxTemplateModel()->publishedEditorCatalog();
        $this->assertSame([$pageId, $sectionId], array_map('intval', array_column($rows, 'id')));
        $this->assertArrayNotHasKey('published_data', $rows[0]);
        $this->assertNull(bloxTemplateModel()->findPublishedForEditor($draftId));
        $this->assertNull(bloxTemplateModel()->findPublishedForEditor($headerId));
        $this->assertSame($pageId, (int) bloxTemplateModel()->findPublishedForEditor($pageId)['id']);
    }

    public function testInvalidTypeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        bloxTemplateModel()->createDraft('popup', '弹窗', '[]');
    }
}
