<?php
/**
 * R2A/R2B 源码契约：样式剪贴板接线与数值预览宽度的边界。
 *
 * 预览宽度是工作区状态：不进文档、不产生历史/dirty、不切换编辑档位；
 * 画布 iframe 保持真实像素宽度用缩放贴合，不谎报宽度。
 * 样式剪贴板：只在编辑器内部、走 runCommand、服务端保存管线不被绕过。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BloxPreviewWidthContractTest extends TestCase
{
    private static string $editor = '';
    private static string $header = '';

    public static function setUpBeforeClass(): void
    {
        self::$editor = (string) bloxEditorSourceForTest();
        self::$header = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/header.php');
    }

    private static function method(string $name, string $stopAt): string
    {
        $start = strpos(self::$editor, $name);
        self::assertNotFalse($start, "method {$name} missing");
        $body = substr(self::$editor, $start);
        $stop = strpos($body, $stopAt);
        self::assertNotFalse($stop, "stop anchor {$stopAt} missing");
        return substr($body, 0, $stop);
    }

    public function testPreviewWidthIsWorkspaceStateOnly(): void
    {
        // 不进保存载荷/历史：documentData 与 historyData 的实现里不得出现预览宽度
        $documentData = self::method('documentData()', 'historyData()');
        self::assertStringNotContainsString('previewCustomWidth', $documentData);
        $historyData = self::method('historyData()', 'queueHistory(');
        self::assertStringNotContainsString('previewCustomWidth', $historyData);
        // 改宽度不触碰 dirty / 历史 / 选中
        $setter = self::method('setPreviewCustomWidth(raw)', 'clearPreviewCustomWidth()');
        foreach (['dirty', 'queueHistory', 'flushHistory', 'selectedSi', 'queueDraftRecovery', 'schedulePreview'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $setter, "setPreviewCustomWidth must not touch {$forbidden}");
        }
        // 不写入文档设置，也不新增 media query
        self::assertStringNotContainsString('docSettings.previewCustomWidth', self::$editor);
        self::assertStringContainsString('clampPreviewWidth', self::$editor);
    }

    public function testCanvasKeepsRealPixelWidthAndScalesToFit(): void
    {
        $frame = self::method('previewFrameStyle()', 'refreshPreview()');
        self::assertStringContainsString('"width:" + width + "px', $frame, 'iframe 用真实像素宽度');
        self::assertStringContainsString('zoom:', $frame, '贴合工作区靠缩放，不靠钳宽');
        $scale = self::method('previewScale()', 'previewShellStyle()');
        self::assertStringContainsString('previewCustomWidthActive()', $scale);
    }

    public function testToolbarShowsBothEditingTierAndPreviewWidth(): void
    {
        self::assertStringContainsString('data-testid="blox-preview-width-input"', self::$header);
        self::assertStringContainsString('data-testid="blox-preview-width-clear"', self::$header);
        // 宽度与档位并列展示，避免误以为切换了编辑档位
        $chip = substr(self::$header, strpos(self::$header, 'blox-preview-width-chip'));
        $chip = substr($chip, 0, 700);
        self::assertStringContainsString('responsiveDeviceTitle(previewDevice)', $chip);
        self::assertStringContainsString("previewCustomWidth + 'px'", $chip);
        // 输入不改变编辑档位
        $input = substr(self::$header, strpos(self::$header, 'blox-preview-width-input') - 900, 1200);
        self::assertStringNotContainsString('previewDevice =', $input);
    }

    public function testStyleClipboardIsWiredThroughCommandRunnerOnly(): void
    {
        $clipboard = (string) file_get_contents(ROOT_PATH . '/assets/js/blox-style-clipboard.js');
        self::assertStringContainsString('"paste-element-style"', $clipboard);
        self::assertStringContainsString("group === \"animation\"", $clipboard, '动画/交互动作不复制');
        self::assertStringContainsString('typography_role: true', $clipboard, '全局样式引用不隐式复制');
        self::assertStringNotContainsString('navigator.clipboard', $clipboard, '只用编辑器内部剪贴板');
        // 编辑器接线 + 面板按钮
        self::assertStringContainsString('YikaiBloxStyleClipboard.mixin', self::$editor);
        $workspace = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/workspace.php');
        self::assertStringContainsString('data-testid="blox-style-copy"', $workspace);
        self::assertStringContainsString('data-testid="blox-style-paste"', $workspace);
    }
}
