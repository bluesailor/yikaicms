<?php
/** Blox 模板模型草稿/发布行为。 */

declare(strict_types=1);

namespace Yikai\Tests\Models;

use InvalidArgumentException;
use RuntimeException;
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
                conditions TEXT,
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
            ['elements' => ['heading', 'nav'], 'plugins' => [], 'design_tokens' => [], 'design_styles' => []],
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
        $sectionId = bloxTemplateModel()->createDraft('section', '区块', '[]');
        bloxTemplateModel()->createDraft('footer', '页尾', '[]');
        bloxTemplateModel()->saveConditions($sectionId, [['main' => 'home', 'ids' => [], 'exclude' => false]]);

        $rows = bloxTemplateModel()->catalog('section');
        $this->assertCount(1, $rows);
        $this->assertSame('区块', $rows[0]['name']);
        $this->assertSame('[{"main":"home","ids":[],"exclude":false}]', $rows[0]['conditions']);
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

    public function testDraftCompareAndSwapRejectsAStaleEditor(): void
    {
        $id = bloxTemplateModel()->createDraft('section', 'Concurrent', '[]');
        bloxTemplateModel()->updateDraft($id, '[{"id":"new","columns":[]}]', [], '[]');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('blox_save_conflict'));
        bloxTemplateModel()->updateDraft($id, '[{"id":"stale","columns":[]}]', [], '[]');
    }

    public function testEditorCatalogOnlyReturnsPublishedSectionAndPageTemplates(): void
    {
        $sectionId = bloxTemplateModel()->createDraft('section', 'Published section', '[]');
        $pageId = bloxTemplateModel()->createDraft('page', 'Published page', '[]');
        $draftId = bloxTemplateModel()->createDraft('section', 'Draft section', '[]');
        $headerId = bloxTemplateModel()->createDraft('header', 'Published header', '[]');
        $popupId = bloxTemplateModel()->createDraft('popup', 'Published popup', '[]');

        bloxTemplateModel()->publishDraft($sectionId);
        bloxTemplateModel()->publishDraft($pageId);
        bloxTemplateModel()->publishDraft($headerId);
        bloxTemplateModel()->publishDraft($popupId);

        $rows = bloxTemplateModel()->publishedEditorCatalog();
        $this->assertSame([$pageId, $sectionId], array_map('intval', array_column($rows, 'id')));
        $this->assertArrayNotHasKey('published_data', $rows[0]);
        $this->assertNull(bloxTemplateModel()->findPublishedForEditor($draftId));
        $this->assertNull(bloxTemplateModel()->findPublishedForEditor($headerId));
        $this->assertNull(bloxTemplateModel()->findPublishedForEditor($popupId));
        $this->assertSame($pageId, (int) bloxTemplateModel()->findPublishedForEditor($pageId)['id']);
    }

    public function testInvalidTypeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        bloxTemplateModel()->createDraft('unknown', '未知', '[]');
    }

    public function testBuiltinAreaPresetReinstallUpdatesDraftWithoutChangingLiveContract(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';

        $first = \BloxAreaTemplatePresets::install('clean-site-header', 7);
        $id = (int) $first['id'];
        bloxTemplateModel()->saveConditions($id, [['main' => 'home', 'ids' => [], 'exclude' => false]]);
        bloxTemplateModel()->publishDraft($id);
        $published = bloxTemplateModel()->findForExport($id);

        $second = \BloxAreaTemplatePresets::install('clean-site-header', 8);
        $updated = bloxTemplateModel()->findForExport($id);

        $this->assertFalse($first['updated']);
        $this->assertTrue($second['updated']);
        $this->assertSame($id, (int) $second['id']);
        $this->assertSame(1, (int) $updated['status']);
        $this->assertSame($published['published_data'], $updated['published_data']);
        $this->assertSame($published['conditions'], $updated['conditions']);
        $this->assertCount(1, bloxTemplateModel()->catalog('header'));
    }

    public function testAreaEditorTargetFollowsTheActuallyRenderedHeader(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';

        $previousOverrides = $GLOBALS['yikai_config_runtime_overrides'] ?? null;
        try {
            $GLOBALS['yikai_config_runtime_overrides'] = [
                'current_theme' => 'default',
                'header_nav_layout' => 'right',
                'blox_custom_header_enabled' => '0',
            ];
            $themeHeader = \BloxAreaTemplatePresets::install('clean-site-header', 1);
            $dormantHeader = \BloxAreaTemplatePresets::install('corporate-site-header', 1);
            bloxTemplateModel()->publishDraft((int) $dormantHeader['id']);
            $this->assertTrue(\BloxAreaEditorTarget::isThemeFallbackTemplate(
                bloxTemplateModel()->find((int) $themeHeader['id']),
                'header'
            ));
            $this->assertFalse(\BloxAreaEditorTarget::isThemeFallbackTemplate(
                bloxTemplateModel()->find((int) $dormantHeader['id']),
                'header'
            ));

            $context = ['home' => true, 'channel_id' => 0, 'page_id' => 0];
            $this->assertSame(
                '/admin/blox_editor.php?template=' . (int) $themeHeader['id'] . '&current_header=1&open=header-settings',
                \BloxAreaEditorTarget::url('header', $context),
                '停用自定义 Header 时，应从当前主题实际显示的 Header 开始编辑'
            );

            $GLOBALS['yikai_config_runtime_overrides']['blox_custom_header_enabled'] = '1';
            $this->assertSame(
                '/admin/blox_editor.php?template=' . (int) $dormantHeader['id'] . '&open=header-settings',
                \BloxAreaEditorTarget::url('header', $context)
            );
        } finally {
            if ($previousOverrides === null) {
                unset($GLOBALS['yikai_config_runtime_overrides']);
            } else {
                $GLOBALS['yikai_config_runtime_overrides'] = $previousOverrides;
            }
        }
    }

    public function testAreaDraftStatusProvidesPrivatePreviewUntilPublish(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';

        $originalSession = $_SESSION ?? null;
        $originalGet = $_GET;
        try {
            $_SESSION = ['admin_id' => 7];
            $_GET = [];
            $publishedJson = '[{"id":"published","type":"section","settings":[],"columns":[]}]';
            $draftJson = '[{"id":"draft","type":"section","settings":[],"columns":[]}]';
            $id = bloxTemplateModel()->createDraft('header', 'Status header', $publishedJson);
            bloxTemplateModel()->publishDraft($id);
            $editorUrl = '/admin/blox_editor.php?template=' . $id . '&open=header-settings';

            self::assertSame([], \BloxPublicationStatus::query([$editorUrl], '/service/process.html?probe=1'));

            bloxTemplateModel()->updateDraft($id, $draftJson, [], $publishedJson);
            $items = \BloxPublicationStatus::query(
                [$editorUrl, $editorUrl],
                '/service/process.html?probe=1&yk_edit_receipt=' . str_repeat('a', 48)
            );
            self::assertCount(1, $items);
            self::assertSame('header', $items[0]['kind']);
            self::assertSame($editorUrl, $items[0]['editor_url']);
            self::assertSame(
                '/service/process.html?probe=1&preview=draft&blox_draft=template%3A' . $id,
                $items[0]['preview_url']
            );

            $_GET = ['preview' => 'draft', 'blox_draft' => 'template:' . $id];
            self::assertSame('template:' . $id, \BloxPublicationStatus::activePreview()['key']);
            self::assertSame($draftJson, \BloxPublicationStatus::areaDraftPreview('header')['draft_data']);
            self::assertNull(\BloxPublicationStatus::areaDraftPreview('footer'));

            bloxTemplateModel()->publishDraft($id);
            self::assertSame([], \BloxPublicationStatus::query([$editorUrl], '/service/process.html'));
            self::assertNull(\BloxPublicationStatus::activePreview());
        } finally {
            $_GET = $originalGet;
            if ($originalSession === null) {
                unset($_SESSION);
            } else {
                $_SESSION = $originalSession;
            }
        }
    }

    public function testAreaPublishConflictMessageUsesPublishedCandidates(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';

        $publishedId = bloxTemplateModel()->createDraft('header', '全站页头', '[{"columns":[]}]');
        bloxTemplateModel()->saveConditions($publishedId, [['main' => 'any', 'ids' => [], 'exclude' => false]]);
        bloxTemplateModel()->publishDraft($publishedId);

        $draftId = bloxTemplateModel()->createDraft('header', '首页页头', '[{"columns":[]}]');
        bloxTemplateModel()->saveConditions($draftId, [['main' => 'home', 'ids' => [], 'exclude' => false]]);
        $message = \BloxAreaConditions::publishConflictMessage(bloxTemplateModel()->find($draftId));

        $this->assertNotSame('', $message);
        $this->assertStringContainsString('全站页头', $message);
    }

    public function testPublishedPopupUsesTheSharedResolverAndFrontendRuntime(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        $document = json_encode([
            'schema' => 1,
            'settings' => ['trigger' => 'delay', 'delay' => 0, 'frequency' => 'every', 'width' => 'sm'],
            'sections' => [[
                'type' => 'section',
                'settings' => [],
                'columns' => [['elements' => [[
                    'type' => 'heading',
                    'data' => ['text' => 'Popup contract', 'level' => 'h2'],
                ]]]],
            ]],
        ], JSON_THROW_ON_ERROR);
        $processed = \BloxPopupDocument::process($document, 'popup_runtime');
        $id = bloxTemplateModel()->createDraft('popup', 'Runtime popup', $processed['json']);
        bloxTemplateModel()->saveConditions($id, [['main' => 'any', 'ids' => [], 'exclude' => false]]);
        bloxTemplateModel()->publishDraft($id);

        $_SERVER['SCRIPT_NAME'] = '/index.php';
        ob_start();
        \BloxPopupRuntime::render();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('data-blox-popup="' . $id . '"', $html);
        $this->assertStringContainsString('data-trigger="delay"', $html);
        $this->assertStringContainsString('Popup contract', $html);
        $this->assertStringContainsString('yk-blox-popup__panel--sm', $html);
    }
}
