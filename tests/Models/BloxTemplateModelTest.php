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
                metadata TEXT,
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
        $this->assertSame('general', json_decode((string) $row['metadata'], true)['purpose']);

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
        $this->assertArrayHasKey('metadata', $row);
    }

    public function testMetadataIsNormalizedAndSavedWithoutChangingDraft(): void
    {
        $id = bloxTemplateModel()->createDraft('section', 'Catalog card', '[]');
        bloxTemplateModel()->saveMetadata($id, [
            'purpose' => 'features',
            'page_types' => ['home', 'bad-value'],
            'priority' => 180,
        ]);

        $row = bloxTemplateModel()->findForExport($id);
        $metadata = json_decode((string) $row['metadata'], true);
        $this->assertSame('features', $metadata['purpose']);
        $this->assertSame(['home'], $metadata['page_types']);
        $this->assertSame(100, $metadata['priority']);
        $this->assertSame('[]', $row['draft_data']);
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

    public function testBundledThemeFooterMigrationInstallsOnlyMissingPresets(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';

        $business = \BloxAreaTemplatePresets::install('business-site-footer', 7);
        bloxTemplateModel()->updateDraft((int) $business['id'], '[]', []);
        $migration = require ROOT_PATH . '/migrations/20260904_bundled_theme_footer_templates.php';

        $this->assertFalse(($migration['check'])());
        $message = ($migration['php'])();
        $this->assertSame('已补齐 2 个内置主题网页脚模板。', $message);
        $this->assertTrue(($migration['check'])());

        foreach (['clean-site-footer', 'business-site-footer', 'minimal-site-footer'] as $slug) {
            $this->assertNotNull(bloxTemplateModel()->findWhere([
                'source' => 'builtin',
                'source_ref' => $slug,
            ]));
        }
        $preserved = bloxTemplateModel()->findForExport((int) $business['id']);
        $this->assertSame('[]', $preserved['draft_data']);
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

            $createdBusinessTheme = $this->ensureBusinessThemeFixture();
            try {
                $GLOBALS['yikai_config_runtime_overrides'] = [
                    'current_theme' => 'business',
                    'blox_custom_header_enabled' => '1',
                ];
                $this->assertSame(
                    '/admin/blox_editor.php?template=' . (int) $dormantHeader['id'] . '&open=header-settings',
                    \BloxAreaEditorTarget::url('header', $context),
                    'business 原生 Header 已调用 bloxAreaHtml(header)：开启且模板命中时，编辑目标应是实际命中的发布模板'
                );

                $GLOBALS['yikai_config_runtime_overrides']['blox_custom_header_enabled'] = '0';
                $this->assertSame(
                    '/admin/blox_editor.php?template=' . (int) $themeHeader['id'] . '&current_header=1&open=header-settings',
                    \BloxAreaEditorTarget::url('header', $context),
                    'business 停用自定义 Header 时，应从 business 实际显示的原生 Header 开始编辑'
                );
            } finally {
                $this->removeBusinessThemeFixture($createdBusinessTheme);
            }

            $createdMinimalTheme = $this->ensureMinimalThemeFixture();
            try {
                $GLOBALS['yikai_config_runtime_overrides'] = [
                    'current_theme' => 'minimal',
                    'blox_custom_header_enabled' => '1',
                ];
                $this->assertSame(
                    '/admin/blox_editor.php?template=' . (int) $dormantHeader['id'] . '&open=header-settings',
                    \BloxAreaEditorTarget::url('header', $context),
                    'minimal 原生 Header 已调用 bloxAreaHtml(header)：开启且模板命中时，编辑目标应是实际命中的发布模板'
                );

                $GLOBALS['yikai_config_runtime_overrides']['blox_custom_header_enabled'] = '0';
                $this->assertSame(
                    '/admin/blox_editor.php?template=' . (int) $themeHeader['id'] . '&current_header=1&open=header-settings',
                    \BloxAreaEditorTarget::url('header', $context),
                    'minimal 停用自定义 Header 时，应从 minimal 实际显示的原生 Header 开始编辑，而不是泛化设计系统入口'
                );
            } finally {
                $this->removeMinimalThemeFixture($createdMinimalTheme);
            }
        } finally {
            if ($previousOverrides === null) {
                unset($GLOBALS['yikai_config_runtime_overrides']);
            } else {
                $GLOBALS['yikai_config_runtime_overrides'] = $previousOverrides;
            }
        }
    }

    private function ensureBusinessThemeFixture(): bool
    {
        return $this->ensureRuntimeThemeFromMarketplace('business');
    }

    private function ensureMinimalThemeFixture(): bool
    {
        return $this->ensureRuntimeThemeFromMarketplace('minimal');
    }

    /**
     * 保证 themes/<theme> 运行副本与 marketplace 唯一源码一致（已安装则不动）。
     * 页头编辑目标解析读取的是运行目录（ThemeRuntime::resolve / themeRendersArea），
     * CI 没有"已安装主题"，需要从唯一源码同步最小骨架（theme.json + header/footer），
     * 且必须同步真实源码——Business/Minimal 原生 Header 已调用 bloxAreaHtml，
     * 旧的"伪造无 Blox 页头"夹具会让断言与实际渲染相反。
     */
    private function ensureRuntimeThemeFromMarketplace(string $theme): bool
    {
        $dir = ROOT_PATH . '/themes/' . $theme;
        if (is_file($dir . '/theme.json')) {
            return false;
        }

        $source = ROOT_PATH . '/marketplace/themes/' . $theme;
        foreach (['theme.json', 'layouts/header.php', 'layouts/footer.php'] as $rel) {
            if (!is_file($source . '/' . $rel)) {
                self::fail("marketplace/themes/{$theme} 缺少 {$rel}，无法建立运行时夹具");
            }
        }
        foreach (['theme.json', 'layouts/header.php', 'layouts/footer.php'] as $rel) {
            $dst = $dir . '/' . $rel;
            if (!is_dir(dirname($dst))) {
                mkdir(dirname($dst), 0777, true);
            }
            copy($source . '/' . $rel, $dst);
        }
        return true;
    }

    private function removeBusinessThemeFixture(bool $created): void
    {
        $this->removeRuntimeThemeFixture('business', $created);
    }

    private function removeMinimalThemeFixture(bool $created): void
    {
        $this->removeRuntimeThemeFixture('minimal', $created);
    }

    private function removeRuntimeThemeFixture(string $theme, bool $created): void
    {
        if (!$created) {
            return;
        }

        $dir = ROOT_PATH . '/themes/' . $theme;
        @unlink($dir . '/layouts/header.php');
        @unlink($dir . '/layouts/footer.php');
        @rmdir($dir . '/layouts');
        @unlink($dir . '/theme.json');
        @rmdir($dir);
    }

    public function testAreaDraftStatusProvidesPrivatePreviewUntilPublish(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';

        $originalSession = $_SESSION ?? null;
        $originalGet = $_GET;
        try {
            $_SESSION = ['admin_id' => 7, 'admin_permissions' => ['*']];
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
