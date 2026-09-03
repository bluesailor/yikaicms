<?php
/** Blox preview, style and template authorization security boundaries. */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use AbstractElement;
use BloxDocumentPipeline;
use BlockRenderer;
use CodeElement;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxSecurityBoundaryTest extends TestCase
{
    public function testStyleValueWhitelistRejectsAdditionalDeclarationsAndUnsafeSchemes(): void
    {
        self::assertSame('#aabbcc', AbstractElement::cssColor('#AABBCC'));
        self::assertSame('rgba(10, 20, 30, .5)', AbstractElement::cssColor('rgba(10, 20, 30, .5)'));
        self::assertSame('var(--color-primary)', AbstractElement::cssColor('var(--color-primary)'));
        self::assertNull(AbstractElement::cssColor('#fff;position:fixed;inset:0'));
        self::assertNull(AbstractElement::cssColor('red/* injected */'));

        self::assertSame('/uploads/images/hero.jpg', AbstractElement::cssImageUrl('/uploads/images/hero.jpg'));
        self::assertSame('https://cdn.example.test/hero.jpg', AbstractElement::cssImageUrl('https://cdn.example.test/hero.jpg'));
        self::assertNull(AbstractElement::cssImageUrl('//evil.example.test/hero.jpg'));
        self::assertNull(AbstractElement::cssImageUrl('javascript:alert(1)'));
        self::assertNull(AbstractElement::cssImageUrl('data:image/svg+xml;base64,PHN2Zz4='));
    }

    public function testDocumentPipelineDropsUnsafeSectionColumnAndContainerStyles(): void
    {
        $processed = BloxDocumentPipeline::process((string) json_encode([[
            'settings' => [
                'bg_color' => '#fff;position:fixed',
                'bg_overlay_color' => '#000;position:fixed',
                'bg_overlay_opacity' => 999,
                'container_bg' => 'red;background:url(//evil.test)',
                'container_bg_image' => 'javascript:alert(1)',
                'container_bg_overlay_color' => '#000;position:fixed',
                'container_bg_overlay_opacity' => -50,
                'bg_image' => 'javascript:alert(1)',
                'bg_video' => 'javascript:alert(1)',
                'text_tone' => 'light;position:fixed',
                'bg_position' => 'center;position:fixed',
                'min_height' => '10000px',
                'content_v_align' => 'end;position:fixed',
            ],
            'columns' => [[
                'card_bg' => '#fff;inset:0',
                'card_bg_image' => 'javascript:alert(1)',
                'card_bg_overlay_color' => '#000;position:fixed',
                'card_bg_overlay_opacity' => 999,
                'elements' => [[
                    'type' => 'container',
                    'data' => [
                        'bg_color' => '#000;position:absolute',
                        'children' => [],
                    ],
                ]],
            ]],
        ]], JSON_THROW_ON_ERROR));

        $section = $processed['sections'][0];
        self::assertSame('', $section['settings']['bg_color']);
        self::assertSame('', $section['settings']['bg_overlay_color']);
        self::assertSame(100, $section['settings']['bg_overlay_opacity']);
        self::assertSame('', $section['settings']['container_bg']);
        self::assertSame('', $section['settings']['container_bg_image']);
        self::assertSame('', $section['settings']['container_bg_overlay_color']);
        self::assertSame(0, $section['settings']['container_bg_overlay_opacity']);
        self::assertSame('', $section['settings']['bg_image']);
        self::assertSame('', $section['settings']['bg_video']);
        self::assertSame('auto', $section['settings']['text_tone']);
        self::assertSame('', $section['settings']['bg_position']);
        self::assertSame('', $section['settings']['min_height']);
        self::assertSame('', $section['settings']['content_v_align']);
        self::assertSame('', $section['columns'][0]['card_bg']);
        self::assertSame('', $section['columns'][0]['card_bg_image']);
        self::assertSame('', $section['columns'][0]['card_bg_overlay_color']);
        self::assertSame(100, $section['columns'][0]['card_bg_overlay_opacity']);
        self::assertSame('', $section['columns'][0]['elements'][0]['data']['bg_color']);
    }

    public function testDocumentPipelineKeepsSafeContainerAndColumnOverlays(): void
    {
        $processed = BloxDocumentPipeline::process((string) json_encode([[
            'settings' => [
                'bg_video' => '/uploads/hero.webm',
                'bg_video_mobile_mode' => 'unsafe',
                'container_bg_image' => '/uploads/container.jpg',
                'container_bg_overlay_color' => '#123456',
                'container_bg_overlay_opacity' => 35,
            ],
            'columns' => [[
                'card_bg_image' => '/uploads/column.jpg',
                'card_bg_overlay_color' => 'rgba(0, 0, 0, .5)',
                'card_bg_overlay_opacity' => 60,
                'elements' => [],
            ]],
        ]], JSON_THROW_ON_ERROR));

        $section = $processed['sections'][0];
        self::assertSame('/uploads/hero.webm', $section['settings']['bg_video']);
        self::assertSame('poster', $section['settings']['bg_video_mobile_mode']);
        self::assertSame('/uploads/container.jpg', $section['settings']['container_bg_image']);
        self::assertSame('#123456', $section['settings']['container_bg_overlay_color']);
        self::assertSame(35, $section['settings']['container_bg_overlay_opacity']);
        self::assertSame('/uploads/column.jpg', $section['columns'][0]['card_bg_image']);
        self::assertSame('rgba(0, 0, 0, .5)', $section['columns'][0]['card_bg_overlay_color']);
        self::assertSame(60, $section['columns'][0]['card_bg_overlay_opacity']);
    }

    public function testRendererDefendsLegacyDocumentsThatBypassSaveNormalization(): void
    {
        $html = BlockRenderer::render((string) json_encode([[
            'settings' => [
                'bg_color' => '#fff;position:fixed',
                'container_bg' => '#fff;inset:0',
                'bg_image' => 'javascript:alert(1)',
                'col_card' => true,
            ],
            'columns' => [[
                'card_bg' => '#fff;z-index:9999',
                'elements' => [[
                    'type' => 'div',
                    'data' => ['bg_color' => '#fff;position:absolute', 'children' => []],
                ]],
            ]],
        ]], JSON_THROW_ON_ERROR));

        self::assertStringNotContainsString('position:', $html);
        self::assertStringNotContainsString('inset:', $html);
        self::assertStringNotContainsString('z-index:', $html);
        self::assertStringNotContainsString('javascript:', $html);
    }

    public function testPreviewCsrfChecksRunBeforeSubmittedDocumentRendering(): void
    {
        $home = $this->source('admin/blox_home_api.php');
        $page = $this->source('admin/blox_page_api.php');
        $preview = $this->source('admin/blox_preview.php');

        $this->assertBefore($home, 'verifyCsrf();', "(\$_POST['action'] ?? '') === 'preview'");
        $this->assertBefore($page, 'verifyCsrf();', "\$action === 'preview'");
        $this->assertBefore($preview, 'verifyCsrf();', 'outputBloxCanvasPreview(');

        self::assertStringContainsString('Content-Security-Policy', $this->source('includes/builder/BloxCanvasPreview.php'));
        self::assertStringContainsString('BloxElementPolicy::assertJsonAllowed($previewJson);', $this->source('includes/builder/BloxCanvasPreview.php'));
        self::assertStringContainsString("script-src 'self' 'nonce-", $this->source('includes/builder/BloxCanvasPreview.php'));
        self::assertStringContainsString('$body = (string) preg_replace', $this->source('includes/builder/BloxCanvasPreview.php'));
    }

    public function testCodeElementScriptsAreRemovedOnlyInsideTheAuthenticatedCanvas(): void
    {
        $element = new CodeElement();
        $data = ['html' => '<div data-safe="1">Safe</div><script>parent.compromised=true;</script>'];

        self::assertStringContainsString('<script>', $element->renderWithContext($data));
        $canvasHtml = $element->renderWithContext($data, '', ['edit_mode' => true]);
        self::assertStringContainsString('data-safe="1"', $canvasHtml);
        self::assertStringNotContainsString('<script', $canvasHtml);
        self::assertStringNotContainsString('compromised', $canvasHtml);
    }

    public function testTemplateAndMediaMutationEndpointsKeepTheirServerSideGates(): void
    {
        $auth = $this->source('admin/includes/auth.php');
        $editor = $this->source('admin/blox_editor.php');
        $templates = $this->source('admin/blox_template_api.php');
        $templateManager = $this->source('admin/blox_templates.php');
        $media = $this->source('admin/media_api.php');
        $upload = $this->source('admin/upload.php');

        self::assertStringContainsString("['header', 'footer', 'popup']", $auth);
        self::assertStringContainsString("requirePermission('blox_home');", $editor);
        self::assertStringContainsString("requirePermission('blox_edit');", $editor);
        self::assertStringContainsString("!in_array(\$templateType, ['section', 'page'], true) && !\$advancedBloxEnabled", $editor);
        self::assertStringContainsString('requireBloxTemplateTypePermission($templateType);', $editor);
        self::assertStringContainsString("requirePermission('blox_global');", $templateManager);
        self::assertStringContainsString('if (!bloxPageEditorEnabled())', $templates);
        self::assertStringContainsString('$requireTemplateLicense($type);', $templates);
        self::assertStringContainsString("\$item['locked_reason'] = 'license_missing';", $templates);
        self::assertBefore($templates, "str_starts_with(\$key, 'remote:')", 'BloxTemplateCatalog::resolve($key, $context)');
        self::assertGreaterThanOrEqual(4, substr_count($templates, 'requireBloxTemplateTypePermission('));
        self::assertStringContainsString("if (\$_SERVER['REQUEST_METHOD'] === 'POST') {\n    verifyCsrf();", $templateManager);
        self::assertStringContainsString("if (\$action === 'rollback_remote')", $templateManager);
        self::assertStringContainsString('data-testid="blox-official-update"', $templateManager);
        self::assertStringContainsString('data-testid="blox-official-rollback"', $templateManager);
        $remoteInstaller = $this->source('includes/builder/BloxRemoteTemplateInstaller.php');
        self::assertBefore($remoteInstaller, '$stateModel->stageUpdate(', 'bloxTemplateModel()->updateDraft(');
        self::assertStringContainsString('$existingDraft', $remoteInstaller);
        self::assertStringContainsString("if (\$action === 'remote_import' && \$_SERVER['REQUEST_METHOD'] === 'POST')", $media);
        self::assertBefore($media, "if (!canUploadImage()) {\n        ma_deny('没有上传图片的权限');\n    }\n    verifyCsrf();", 'RemoteOfficialMedia::import(');
        self::assertSame(3, substr_count($media, 'verifyCsrf();'));
        self::assertSame(1, substr_count($upload, 'verifyCsrf();'));
    }

    private function assertBefore(string $source, string $first, string $second): void
    {
        $firstPosition = strpos($source, $first);
        $secondPosition = strpos($source, $second);
        self::assertNotFalse($firstPosition, $first);
        self::assertNotFalse($secondPosition, $second);
        self::assertLessThan($secondPosition, $firstPosition);
    }

    private function source(string $path): string
    {
        $file = ROOT_PATH . '/' . $path;
        if (!is_file($file) && (str_starts_with($path, 'admin/blox_editor') || $path === 'admin/blox_home_api.php')) {
            // 付费 Blox 源码不随公开仓库分发；无注入的 CI 矩阵跳过，注入 job 与本地全量执行。
            self::markTestSkipped('付费 Blox 源码未注入：' . $path);
        }
        return (string) file_get_contents($file);
    }
}
