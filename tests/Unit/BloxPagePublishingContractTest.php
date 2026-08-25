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
        $this->assertStringContainsString('ChannelBloxDocument::class', $api);
        $this->assertStringContainsString('PageBloxDocument::class', $api);
        $this->assertStringContainsString('$documentClass::saveDraft(', $api);
        $this->assertStringContainsString('$documentClass::saveAndPublish(', $api);
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
        $homeApi = $this->source('admin/blox_home_api.php');
        $this->assertStringContainsString('if (!bloxPageEditorEnabled())', $editor);
        $this->assertStringNotContainsString('$isBasicPageRequest', $editor);
        $this->assertStringContainsString('elseif (!bloxPageEditorEnabled())', $preview);
        $this->assertStringContainsString("if (\$isHomeLayout) {\n    if (!bloxPageEditorEnabled())", $preview);
        $this->assertStringContainsString('if (!bloxPageEditorEnabled())', $homeApi);
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
        $this->assertStringNotContainsString("openTemplates() {\n                if (!this.advancedMode)", $editor);
        $this->assertStringNotContainsString("loadTemplates(force) {\n                if (!this.advancedMode)", $editor);
        $this->assertStringContainsString('data-testid="blox-templates-open"', $header);

        $canvas = $this->source('includes/builder/BloxCanvasPreview.php');
        $this->assertStringContainsString("'@@templates_enabled@@' => bloxPageEditorEnabled() ? 'true' : 'false'", $canvas);
        $this->assertStringContainsString('$renderPublishedArea = static function (string $area): string', $canvas);
        $this->assertStringContainsString('$headerBlox = $headerEnabled ? $renderPublishedArea(\'header\') : \'\';', $canvas);
        $this->assertStringContainsString('$footerBlox = $footerEnabled ? $renderPublishedArea(\'footer\') : \'\';', $canvas);
        $this->assertStringContainsString('yk-home-context-area', $canvas);
        $this->assertStringContainsString('data-testid="blox-context-edit-\' . $area', $canvas);
        // v1.18.6：首页画布的页头编辑入口带 back=home——编辑完页头一键返回首页编辑器
        $this->assertStringContainsString("BloxAreaEditorTarget::url('header', \$homeAreaContext, 'home')", $canvas);
        $this->assertStringContainsString('$body = $headerBody . $homeBody . $footerBody;', $canvas);

        $bridge = $this->source('assets/js/blox-canvas-bridge.js');
        $this->assertStringContainsString('function areaEditPayload(value)', $bridge);
        // v1.18.6：白名单加入 back=home 段（首页↔页头编辑回路）
        $this->assertStringContainsString('(&current_header=1)?(&back=home)?(&open=header-settings)?$', $bridge);
        $this->assertStringContainsString('if ((template[2] || template[4]) && value.area !== "header") return null;', $bridge);
        $this->assertStringContainsString('payload = areaEditPayload(data.ykEditArea);', $bridge);
        $this->assertStringContainsString('this.onEditArea(payload);', $bridge);
        $this->assertStringContainsString('onEditArea: function (payload) { window.location.assign(payload.url); }', $editor);

        $templateApi = $this->source('admin/blox_template_api.php');
        $this->assertStringContainsString("post('replace_theme_area', '')", $templateApi);
        $this->assertStringContainsString('BloxAreaEditorTarget::isThemeFallbackTemplate($row, $type)', $templateApi);
        $this->assertStringContainsString("array_key_exists('blocks_data', \$_POST)", $templateApi);
        $this->assertStringContainsString('$templateRevisionMatches($type, $currentDraft, $baseRevision)', $templateApi);
        $this->assertStringContainsString('bloxTemplateModel()->updateDraft(', $templateApi);
        $this->assertStringContainsString('BloxTemplateImporter::deriveRequirements($processed[\'sections\'])', $templateApi);
        $this->assertStringContainsString('db()->beginTransaction();', $templateApi);
        $this->assertStringContainsString('bloxTemplateModel()->unpublish($replacementId);', $templateApi);
        $this->assertStringContainsString('bloxTemplateModel()->publishDraft($id);', $templateApi);
        $this->assertStringContainsString("'blox_custom_header_enabled' : 'blox_custom_footer_enabled' => '1'", $templateApi);
        $this->assertStringContainsString('body.set("replace_theme_area", replaceThemeArea);', $editor);
        $this->assertStringContainsString('body.set("blocks_data", payload);', $editor);
        $this->assertStringContainsString('body.set("base_revision", this.baseRevision);', $editor);
        $this->assertStringContainsString('self.acceptSavedDocument(payload, savedData, res);', $editor);
        $this->assertStringContainsString('if (res.msg === self.uiText.saveConflict)', $editor);
        $this->assertStringNotContainsString('if (this.dirty) { this.toast(this.uiText.tplPublishRequiresSaved); return; }', $editor);
        $this->assertStringContainsString('@click="publishTemplate()" :disabled="saving"', $header);
        $this->assertStringContainsString("__('blox_tpl_publish_saves_current')", $header);
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
            'admin/page_edit.php',
            'admin/index.php',
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
        $this->assertStringContainsString('pagePrimaryEditUrl($channel)', $channels);
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
        $this->assertGreaterThanOrEqual(2, substr_count($page, 'pagePrimaryEditUrl($item)'));
        $this->assertGreaterThanOrEqual(2, substr_count($page, 'pagePrimaryEditTarget($item)'));
        $this->assertGreaterThanOrEqual(2, substr_count($page, 'channelUrl($item)'));
        $this->assertStringContainsString('isTimelinePageChannel($itemEditTarget)', $page);
        $this->assertStringContainsString('page_redirect_target_badge', $page);
        $this->assertStringContainsString("e(__('admin_timeline'))", $page);
    }

    public function testTimelinePageUsesItsRealDataEditorAndCanonicalPreviewPath(): void
    {
        $functions = $this->source('includes/functions.php');
        $editor = $this->source('admin/blox_editor.php');
        $frontend = $this->source('page.php');
        $timeline = $this->source('admin/timeline.php');

        $this->assertStringContainsString('function isTimelinePageChannel(array $channel): bool', $functions);
        $this->assertStringContainsString("return \$sourceSlugCache[\$sourceId] === 'history';", $functions);
        $this->assertStringContainsString("\$url = '/admin/timeline.php';", $functions);
        $this->assertStringContainsString('function pagePrimaryEditTarget(array $channel): array', $functions);
        $this->assertStringContainsString('channelModel()->getByParent($targetId, true)', $functions);
        $this->assertStringContainsString("\$url .= '&from_parent=' . \$sourceId;", $functions);
        $this->assertStringContainsString('$primaryEditTarget = pagePrimaryEditTarget($page);', $editor);
        $this->assertStringContainsString('$primaryEditUrl = pagePrimaryEditUrl($page);', $editor);
        $this->assertStringContainsString("(int) (\$primaryEditTarget['id'] ?? 0) !== (int) \$page['id']", $editor);
        $this->assertStringContainsString("header('Location: ' . \$primaryEditUrl);", $editor);
        $this->assertStringContainsString('if (isTimelinePageChannel($channel))', $frontend);
        $this->assertStringContainsString("include __DIR__ . '/history.php';", $frontend);
        $this->assertSame(2, substr_count($timeline, 'href="/about/history.html"'));
        $this->assertStringNotContainsString('href="/history.php"', $timeline);
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
        $this->assertStringContainsString("\$url = '/admin/blox_editor.php?id=' . \$targetId;", $functions);
        $this->assertStringContainsString('return pagePrimaryEditUrl($channel);', $functions);
        $this->assertStringNotContainsString("return '/admin/page_edit.php?id=' . \$pageId;", $functions);
    }

    public function testFrontendBloxPagesExposeStableSectionDeepLinksWithoutLeakingEditContext(): void
    {
        $overlay = $this->source('includes/front_edit.php');
        $functions = $this->source('includes/functions.php');
        $this->assertStringContainsString("el.hasAttribute('data-yk-sec-id')", $overlay);
        $this->assertStringContainsString("target.searchParams.set('focus_section', sectionId)", $overlay);
        $this->assertStringContainsString("elementTarget.searchParams.set('focus_element', elementId)", $overlay);
        $this->assertStringContainsString("elementTarget.searchParams.delete('open')", $overlay);
        $this->assertStringNotContainsString('&focus=', $overlay);
        foreach (['data-yk-element-edit', 'data-yk-element-id', 'data-yk-element-label', 'data-yk-sec-id', 'data-yk-nav', 'data-yk-footer', 'data-yk-partners', 'data-yk-edit'] as $marker) {
            $this->assertStringContainsString($marker, $overlay);
        }

        $targetRegistry = $this->source('includes/builder/BloxFrontendEditTarget.php');
        foreach (['header-navigation', 'site-contact', 'social-links', 'fe_edit_header_navigation'] as $token) {
            $this->assertStringContainsString($token, $targetRegistry);
        }
        $this->assertStringContainsString('BloxFrontendEditTarget::inArea(', $functions);
        $this->assertStringContainsString('BloxAreaDocument::renderShell($area, $document[\'settings\'], $body, \'\', $editUrl)', $functions);

        $this->assertStringContainsString('function renderFrontEditableContentBody(array $content, int $channelId): string', $functions);
        $this->assertStringContainsString('$previousChannelId = BlockRenderer::$editChannelId;', $functions);
        $this->assertStringContainsString('BlockRenderer::$editChannelId = $previousChannelId;', $functions);

        foreach (['page.php', 'contact.php', 'news.php', 'list.php'] as $path) {
            $source = $this->source($path);
            $this->assertStringContainsString("\$GLOBALS['ik_edit_url']", $source, $path);
            $this->assertStringNotContainsString("\$GLOBALS['ik_front_edit_cid']", $source, $path);
            $this->assertStringContainsString('renderFrontEditableContentBody(', $source, $path);
        }
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
        $file = ROOT_PATH . '/' . $path;
        if (!is_file($file) && str_starts_with($path, 'admin/blox_editor')) {
            // 付费 Blox 源码不随公开仓库分发；无注入的 CI 矩阵跳过，注入 job 与本地全量执行。
            self::markTestSkipped('付费 Blox 源码未注入：' . $path);
        }
        return (string) file_get_contents($file);
    }
}
