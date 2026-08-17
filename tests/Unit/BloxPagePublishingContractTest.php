<?php
/** Blox single-page endpoint and publication boundary contract. */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BloxPagePublishingContractTest extends TestCase
{
    public function testEditorNoLongerDependsOnAdvancedPageEditor(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $header = $this->source('admin/blox_editor/partials/header.php');

        $this->assertStringNotContainsString('/admin/page_edit_advance.php', $editor);
        $this->assertStringContainsString("\$saveEndpoint = '/admin/blox_page_api.php?id='", $editor);
        $this->assertStringContainsString("\$previewEndpoint = '/admin/blox_preview.php?home=1'", $editor);
        $this->assertStringContainsString('PageBloxDocument::load($id)', $editor);
        $this->assertStringContainsString('data-testid="blox-publish-page"', $header);
        $this->assertStringContainsString("__('blox_save_draft')", $header);
    }

    public function testPageApiOwnsPreviewDraftAndPublishActions(): void
    {
        $api = $this->source('admin/blox_page_api.php');

        $this->assertStringContainsString('bloxPageEditorEnabled()', $api);
        $this->assertStringNotContainsString('bloxEditorEnabled()', $api);
        $this->assertStringContainsString("\$action === 'preview'", $api);
        $this->assertStringContainsString('outputBloxCanvasPreview(false, $pageId)', $api);
        $this->assertStringContainsString('PageBloxDocument::saveDraft(', $api);
        $this->assertStringContainsString('PageBloxDocument::saveAndPublish(', $api);
        $this->assertStringContainsString('verifyCsrf();', $api);
    }

    public function testFreeEditionPageEditingIsSeparatedFromAdvancedFeatureLicense(): void
    {
        $functions = $this->source('includes/functions.php');
        $start = strpos($functions, 'function bloxPageEditorEnabled(): bool');
        $this->assertNotFalse($start);
        $body = substr($functions, (int) $start, 320);

        $this->assertStringContainsString("config('blox_editor_enabled', '1')", $body);
        $this->assertStringNotContainsString("config('blox_page_editor_enabled'", $body);
        $this->assertStringNotContainsString('license_valid', $body);
        $this->assertStringNotContainsString('license_has_module', $body);

        $editorGateStart = strpos($functions, 'function bloxPageEditorEnabled(): bool');
        $advancedGateStart = strpos($functions, 'function bloxAdvancedFeaturesEnabled(): bool');
        $this->assertNotFalse($editorGateStart);
        $this->assertNotFalse($advancedGateStart);
        $editorGate = substr($functions, (int) $editorGateStart, (int) $advancedGateStart - (int) $editorGateStart);
        $advancedGate = substr($functions, (int) $advancedGateStart, 520);
        $this->assertStringContainsString("config('blox_editor_enabled', '1')", $editorGate);
        $this->assertStringNotContainsString('license_valid', $editorGate);
        $this->assertStringContainsString('license_valid', $advancedGate);
        $this->assertStringContainsString("license_has_module('blox')", $advancedGate);

        $legacyGateStart = strpos($functions, 'function bloxEditorEnabled(): bool');
        $this->assertNotFalse($legacyGateStart);
        $legacyGate = substr($functions, (int) $legacyGateStart, 180);
        $this->assertStringContainsString('return bloxAdvancedFeaturesEnabled();', $legacyGate);

        $editor = $this->source('admin/blox_editor.php');
        $preview = $this->source('admin/blox_preview.php');
        $this->assertStringContainsString('$isBasicPageRequest ? !bloxPageEditorEnabled() : !bloxAdvancedFeaturesEnabled()', $editor);
        $this->assertStringContainsString('elseif (!bloxPageEditorEnabled())', $preview);
        $this->assertStringContainsString('if (!bloxAdvancedFeaturesEnabled())', $preview);
    }

    public function testBasicPageEditorDefaultsOnWhileAdvancedFeaturesRemainLicensed(): void
    {
        $defaults = require ROOT_PATH . '/config/defaults.php';

        $this->assertArrayNotHasKey('blox_page_editor_enabled', $defaults['system']);
        $this->assertSame('1', $defaults['system']['blox_editor_enabled']['value']);

        $editor = $this->source('admin/blox_editor.php');
        $header = $this->source('admin/blox_editor/partials/header.php');
        $this->assertStringContainsString('$advancedBloxEnabled = bloxAdvancedFeaturesEnabled();', $editor);
        $this->assertStringContainsString('data-blox-advanced="<?php echo $advancedBloxEnabled', $editor);
        $this->assertStringContainsString('advancedMode: <?php echo $advancedBloxEnabled', $editor);
        $this->assertStringContainsString('if (!this.advancedMode) return;', $editor);
        $this->assertStringContainsString('if ($advancedBloxEnabled)', $header);

        $canvas = $this->source('includes/builder/BloxCanvasPreview.php');
        $this->assertStringContainsString("'@@templates_enabled@@' => bloxAdvancedFeaturesEnabled() ? 'true' : 'false'", $canvas);
    }

    public function testInstallAndUpgradePathsEnableTheEditor(): void
    {
        $mysql = $this->source('install/sql/mysql.sql');
        $sqlite = $this->source('install/sql/sqlite.sql');
        $migration = $this->source('migrations/20260816_enable_blox_editor_by_default.php');

        $this->assertStringContainsString("'blox_editor_enabled','1','switch','Blox 可视化编辑器'", $mysql);
        $this->assertStringContainsString("'blox_editor_enabled','1','switch','Blox 可视化编辑器'", $sqlite);
        $this->assertStringContainsString("'id' => '20260816_enable_blox_editor_by_default'", $migration);
        $this->assertStringContainsString("settingModel()->set('blox_editor_enabled', '1', 'system')", $migration);
    }

    public function testSinglePagePrimaryEntriesUseBloxWithoutLegacyFallback(): void
    {
        foreach ([
            'admin/channel.php',
            'admin/page_edit.php',
            'admin/index.php',
            'includes/front_edit.php',
            'includes/functions.php',
            'page.php',
            'contact.php',
        ] as $path) {
            $source = $this->source($path);
            $this->assertStringContainsString('/admin/blox_editor.php?id=', $source, $path);
            $this->assertStringNotContainsString('/admin/page_edit_advance.php?id=', $source, $path);
        }

        $pageList = $this->source('admin/page.php');
        $dashboard = $this->source('admin/index.php');
        $channels = $this->source('admin/channel.php');
        $frontend = $this->source('page.php');
        $functions = $this->source('includes/functions.php');
        $this->assertStringNotContainsString("\$__isBlox ? '/admin/blox_editor.php?id=' : '/admin/page_edit.php?id='", $pageList);
        $this->assertStringNotContainsString("? '/admin/blox_editor.php?id=' . \$item['channel_id']", $dashboard);
        $this->assertStringNotContainsString("'/admin/page_edit.php?id=' . (int) \$channel['id']", $frontend);
        $this->assertStringNotContainsString("return '/admin/page_edit.php?id=' . \$pageId;", $functions);
        $this->assertStringNotContainsString('$__pageModes', $channels);
    }

    public function testLegacyEditorIsACompatibilityRedirectOnly(): void
    {
        $legacy = $this->source('admin/page_edit_advance.php');

        $this->assertStringContainsString("'/admin/blox_editor.php?id=' . \$id", $legacy);
        $this->assertStringContainsString("'/admin/blox_editor.php?home=1'", $legacy);
        $this->assertStringContainsString("(string) (\$_GET['legacy'] ?? '') === '1'", $legacy);
        $this->assertStringContainsString("header('Location: ' . \$target, true, 302);", $legacy);
    }

    public function testPageListRemovesAllLegacyLayoutLinks(): void
    {
        $page = $this->source('admin/page.php');

        $this->assertStringNotContainsString('/admin/page_edit_advance.php?home=1', $page);
        $this->assertStringNotContainsString("__('page_mode_blocks_edit')", $page);
        $this->assertStringNotContainsString('/admin/page_edit_advance.php?id=', $page);
        $this->assertStringNotContainsString("renderTransPills((int)\$item['id'], \$transStatus, '/admin/page_edit.php')", $page);
        $this->assertStringNotContainsString("\$__isBlox ? '/admin/blox_editor.php?id=' : '/admin/page_edit.php?id='", $page);
        $this->assertStringContainsString('/admin/blox_editor.php?home=1', $page);
        $this->assertStringContainsString("renderTransPills((int)\$item['id'], \$transStatus, '/admin/blox_editor.php')", $page);
        $this->assertGreaterThanOrEqual(2, substr_count($page, '<a href="/admin/blox_editor.php?id=<?php echo $item[\'id\']; ?>"'));
        $this->assertGreaterThanOrEqual(2, substr_count($page, "__('page_mode_blox')"));
    }

    public function testFrontendPagePrefersPublishedBloxDocumentAndSetsAdminBarBeforeHeader(): void
    {
        $page = $this->source('page.php');
        $publishedLoad = strpos($page, '$publishedContent = contentModel()->getFirstByChannel($channelId);');
        $legacyFallback = strpos($page, "elseif (!empty(\$channel['content']))");
        $editTarget = strpos($page, "\$GLOBALS['ik_edit_url'] = '/admin/blox_editor.php?id='");
        $header = strpos($page, "require_once theme_path('layouts/header.php')");

        $this->assertNotFalse($publishedLoad);
        $this->assertNotFalse($legacyFallback);
        $this->assertNotFalse($editTarget);
        $this->assertNotFalse($header);
        $this->assertLessThan($legacyFallback, $publishedLoad);
        $this->assertLessThan($header, $editTarget);
        $this->assertStringContainsString("(\$publishedContent['content_type'] ?? '') === 'blocks'", $page);

        $functions = $this->source('includes/functions.php');
        $this->assertStringContainsString("return '/admin/blox_editor.php?id=' . \$pageId;", $functions);
        $this->assertStringNotContainsString("return '/admin/page_edit.php?id=' . \$pageId;", $functions);
    }

    public function testPublishingWritesLiveContentOnlyInsideExplicitPublishTransaction(): void
    {
        $document = $this->source('includes/builder/PageBloxDocument.php');

        $saveStart = strpos($document, 'public static function saveDraft');
        $publishStart = strpos($document, 'public static function saveAndPublish');
        $this->assertNotFalse($saveStart);
        $this->assertNotFalse($publishStart);
        $saveBody = substr($document, (int) $saveStart, (int) $publishStart - (int) $saveStart);
        $this->assertStringContainsString('bloxPageDraftModel()->saveForPage', $saveBody);
        $this->assertStringNotContainsString('contentModel()->updateById', $saveBody);
        $this->assertStringContainsString('$database->beginTransaction();', $document);
        $this->assertStringContainsString('contentModel()->updateById', $document);
        $this->assertStringContainsString("'status' => 1", $document);
        $this->assertStringContainsString('$database->commit();', $document);
        $this->assertStringContainsString('$database->rollback();', $document);
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(ROOT_PATH . '/' . $path);
    }
}
