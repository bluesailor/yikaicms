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
                conditions TEXT,
                thumbnail TEXT NOT NULL DEFAULT '',
                status INTEGER NOT NULL DEFAULT 0,
                admin_id INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0,
                published_at INTEGER NOT NULL DEFAULT 0
            )",
            // 依赖判定要查已启用插件。没有这张表时 getActiveSlugs() 抛异常，
            // 判定只能退回「核对不了」——那测的就不是真实路径了。
            "CREATE TABLE plugins (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT NOT NULL,
                status INTEGER NOT NULL DEFAULT 0,
                installed_at INTEGER NOT NULL DEFAULT 0,
                activated_at INTEGER NOT NULL DEFAULT 0
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
        // 依赖不满足的模板**留在列表里**（E08）：此前是直接跳过，作者发布过的模板凭空消失，
        // 面板也不说为什么。插入的拦截仍在 resolve()，见下一条断言。
        $this->assertCount(2, $local);
        $byKey = array_column($local, null, 'key');

        $ok = $byKey['local:' . $published];
        $this->assertSame('section', $ok['type']);
        $this->assertSame('hero', $ok['metadata']['purpose']);
        $this->assertSame(['home'], $ok['metadata']['page_types']);
        $this->assertSame(90, $ok['metadata']['priority']);
        $this->assertSame([], $ok['unavailable'], '依赖齐全的模板不该带缺口');

        $blocked = $byKey['local:' . $missing];
        $this->assertSame(['plugins' => ['not-active']], $blocked['unavailable'], '要说清缺的是哪个插件');

        $this->assertContains('builtin:404-route-lost', array_column($items, 'key'));
    }

    /** 元素缺口同样要说出来——这版不支持的元素，作者装什么插件都补不回来。 */
    public function testMissingElementsAreNamedOnTheCard(): void
    {
        $id = bloxTemplateModel()->createDraft(
            'section',
            'Countdown hero',
            $this->sectionJson('cd', 'cd-el'),
            'import',
            1,
            ['elements' => ['heading', 'countdown-timer'], 'plugins' => []]
        );
        bloxTemplateModel()->publishDraft($id);

        $items = array_column(\BloxTemplateCatalog::items('page'), null, 'key');
        $this->assertArrayHasKey('local:' . $id, $items);
        $this->assertSame(['elements' => ['countdown-timer']], $items['local:' . $id]['unavailable']);
    }

    /** 依赖清单本身坏了：仍然不可插入，但说的是"读不了"，不是假装模板不存在。 */
    public function testUnreadableRequirementsAreReportedAsUnreadable(): void
    {
        $id = bloxTemplateModel()->createDraft('section', 'Broken deps', $this->sectionJson('bd', 'bd-el'));
        bloxTemplateModel()->publishDraft($id);
        // update() 自己补 DB_PREFIX，这里传裸表名（DB_PREFIX 契约）
        db()->update('blox_templates', ['requirements' => '{not json'], 'id = ?', [$id]);

        $items = array_column(\BloxTemplateCatalog::items('page'), null, 'key');
        $this->assertSame(['invalid' => true], $items['local:' . $id]['unavailable']);
    }

    /** 列出不等于可插入：真正的闸仍在 resolve()，与依赖缺口用同一套判定。 */
    public function testListedButUnavailableTemplateStillRefusesToResolve(): void
    {
        $missing = bloxTemplateModel()->createDraft(
            'page',
            'Missing plugin',
            $this->sectionJson('missing', 'missing-el'),
            'import',
            1,
            ['elements' => ['heading'], 'plugins' => ['not-active']]
        );
        bloxTemplateModel()->publishDraft($missing);

        $listed = array_column(\BloxTemplateCatalog::items('page'), 'key');
        $this->assertContains('local:' . $missing, $listed, '先确认它确实在列表里');

        $this->expectException(RuntimeException::class);
        \BloxTemplateCatalog::resolve('local:' . $missing, 'page');
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

    public function testImportedPageFrameSurvivesLocalPublishExportAndResolve(): void
    {
        // 夹具内联：原先借用随包的 restaurant-landing，该模板 2026-09-16 移出随包目录。
        // 被保护的是发布/导出/解析链路对 page_*_hidden 的处理，与目录里有哪些模板无关。
        $package = (string) json_encode([
            'format' => 'yikaicms-blox-template',
            'version' => 1,
            'type' => 'page',
            'name' => 'Frame fixture',
            'requires' => ['elements' => ['heading'], 'plugins' => []],
            'document' => [
                'schema' => 1,
                'settings' => array_fill_keys([
                    'page_header_hidden', 'page_footer_hidden', 'page_breadcrumb_hidden',
                    'page_title_hidden', 'page_sidebar_hidden',
                ], true),
                'sections' => [[
                    'type' => 'section',
                    'settings' => [],
                    'columns' => [['elements' => [
                        ['type' => 'heading', 'data' => ['text' => 'Frame', 'level' => 'h1']],
                    ]]],
                ]],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $prepared = \BloxTemplateImporter::prepare($package);
        $id = bloxTemplateModel()->createDraft('page', 'Frame page', $prepared['draft_json']);
        bloxTemplateModel()->publishDraft($id);
        // A newer draft must not leak its frame settings into the published catalog.
        $draft = json_decode($prepared['draft_json'], true, 512, JSON_THROW_ON_ERROR);
        $draft['settings']['page_header_hidden'] = false;
        bloxTemplateModel()->updateDraft($id, json_encode($draft, JSON_THROW_ON_ERROR), $prepared['requirements']);
        $resolved = \BloxTemplateCatalog::resolve('local:' . $id);
        $this->assertSame($prepared['settings'], $resolved['settings']);
        $row = bloxTemplateModel()->findForExport($id);
        $exported = \BloxTemplateImporter::exportJson($row);
        $roundTrip = \BloxTemplateImporter::prepare($exported);
        $this->assertSame($prepared['settings'], $roundTrip['settings']);
        $this->assertNotSame($resolved['sections'][0]['id'], $roundTrip['sections'][0]['id']);
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
