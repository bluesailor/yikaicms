<?php
/** Blox 编辑器模板目录契约。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use RuntimeException;
use Yikai\Tests\TestCase;

final class BloxTemplateCatalogTest extends TestCase
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
                metadata TEXT,
                thumbnail TEXT NOT NULL DEFAULT '',
                status INTEGER NOT NULL DEFAULT 0,
                admin_id INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0,
                published_at INTEGER NOT NULL DEFAULT 0
            )",
        ];
    }

    public function testCatalogListsOnlyPublishedUsableEditorTemplates(): void
    {
        $published = bloxTemplateModel()->createDraft(
            'section',
            'Hero section',
            $this->sectionJson('old-section', 'old-element'),
            'user',
            1,
            ['elements' => ['heading'], 'plugins' => []],
            '',
            0,
            '',
            ['purpose' => 'hero', 'page_types' => ['home'], 'priority' => 90]
        );
        bloxTemplateModel()->publishDraft($published);
        bloxTemplateModel()->createDraft('page', 'Draft page', $this->sectionJson('draft', 'draft-el'));
        $missing = bloxTemplateModel()->createDraft(
            'page',
            'Missing plugin',
            $this->sectionJson('missing', 'missing-el'),
            'import',
            1,
            ['elements' => ['heading'], 'plugins' => ['not-active']]
        );
        bloxTemplateModel()->publishDraft($missing);

        $items = \BloxTemplateCatalog::items('page');

        $local = array_values(array_filter(
            $items,
            static fn (array $item): bool => (string) ($item['source'] ?? '') === 'local'
        ));
        $this->assertCount(1, $local);
        $this->assertSame('local:' . $published, $local[0]['key']);
        $this->assertSame('section', $local[0]['type']);
        $this->assertSame('hero', $local[0]['metadata']['purpose']);
        $this->assertSame(['home'], $local[0]['metadata']['page_types']);
        $this->assertSame(90, $local[0]['metadata']['priority']);
        $this->assertContains('builtin:404-route-lost', array_column($items, 'key'));
    }

    public function testResolveReturnsValidatedSectionsWithFreshIds(): void
    {
        $id = bloxTemplateModel()->createDraft(
            'page',
            'Company page',
            $this->sectionJson('old-section', 'old-element'),
            'user',
            1,
            ['elements' => ['heading'], 'plugins' => []]
        );
        bloxTemplateModel()->publishDraft($id);

        $first = \BloxTemplateCatalog::resolve('local:' . $id, 'page');
        $second = \BloxTemplateCatalog::resolve('local:' . $id, 'page');

        $this->assertSame('page', $first['type']);
        $this->assertSame('Company page', $first['name']);
        $this->assertNotSame('old-section', $first['sections'][0]['id']);
        $this->assertNotSame('old-element', $first['sections'][0]['columns'][0]['elements'][0]['id']);
        $this->assertNotSame($first['sections'][0]['id'], $second['sections'][0]['id']);
    }

    public function testUnpublishedTemplateCannotBeResolved(): void
    {
        $id = bloxTemplateModel()->createDraft('section', 'Draft', $this->sectionJson('draft', 'draft-el'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('blox_tpl_not_published');
        \BloxTemplateCatalog::resolve('local:' . $id, 'home');
    }

    private function sectionJson(string $sectionId, string $elementId): string
    {
        return json_encode([[
            'id' => $sectionId,
            'type' => 'section',
            'settings' => [],
            'columns' => [[
                'id' => 'column-' . $sectionId,
                'elements' => [[
                    'id' => $elementId,
                    'type' => 'heading',
                    'data' => ['text' => 'Title', 'level' => 'h2'],
                ]],
            ]],
        ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
