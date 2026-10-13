<?php
/**
 * 画布拖尺寸（2.1.0，参照 Bricks）：列 / 容器 / 布局块拖底边改最小高度（px），单列区块的列拖右边改宽度。
 * 这里锁住数据能存、能出样式，以及画布手柄与编辑器接线。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxSizeDragTest extends TestCase
{
    public function testColumnMinHeightIsClampedSavedAndRendered(): void
    {
        self::assertSame(['min_height' => 1200], BloxDocumentPipeline::normalizeColumnExtras(['min_height' => '5000']));
        self::assertSame([], BloxDocumentPipeline::normalizeColumnExtras(['min_height' => 0]));
        self::assertSame([], BloxDocumentPipeline::normalizeColumnExtras(['min_height' => 'tall']));

        $doc = (string) json_encode(['schema' => 1, 'settings' => [], 'sections' => [[
            'id' => 's1', 'type' => 'section', 'settings' => [],
            'columns' => [['id' => 'c1', 'min_height' => 240, 'elements' => [
                ['id' => 'e1', 'type' => 'text', 'data' => ['content' => 'x'], 'children' => []],
            ]]],
        ]]]);
        $saved = BloxDocumentPipeline::process($doc);
        self::assertSame(240, $saved['sections'][0]['columns'][0]['min_height']);
        self::assertStringContainsString('style="min-height:240px;"', BlockRenderer::render($saved['json']));
    }

    public function testContainerAndDivMinHeightCompileToInlineStyle(): void
    {
        foreach ([new ContainerElement(), new DivElement()] as $element) {
            $keys = array_column($element->controls(), 'key');
            self::assertContains('min_height_px', $keys, $element::class);
        }
        $html = BlockRenderer::render((string) json_encode([[
            'type' => 'custom', 'id' => 's1',
            'columns' => [['elements' => [['type' => 'container', 'id' => 'e1', 'data' => ['min_height_px' => 166, 'children' => []], 'children' => []]]]],
        ]]));
        self::assertStringContainsString('min-height:166px', $html);
    }

    public function testCanvasHandlesBridgeAndEditorAreWired(): void
    {
        $canvas = (string) file_get_contents(ROOT_PATH . '/includes/builder/BloxCanvasPreview.php');
        self::assertStringContainsString("postToEditor({ ykSizeDrag: { target: drag.target, prop: drag.kind === 'height' ? 'min_height' : 'span', value: drag.value } });", $canvas);
        self::assertStringContainsString('if (t) columnSizeTarget(t, path);', $canvas, '选中列时挂手柄');
        self::assertStringContainsString('elementSizeTarget(activeEl, path);', $canvas, '选中容器 / 布局块时挂手柄');
        self::assertStringContainsString('@media(max-width:1023px){.yk-size-handle{display:none!important}}', $canvas, '只在桌面画布上拖');

        $bridge = (string) file_get_contents(ROOT_PATH . '/assets/js/yikay-canvas-bridge.js');
        self::assertStringContainsString('this.onSizeDrag({ target: sized, prop: sizeProp, value: sizeValue });', $bridge);

        $editor = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor.php');
        self::assertStringContainsString('onSizeDrag: function (payload) { self.applyCanvasSizeDrag(payload); },', $editor);

        $workspace = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/workspace.php');
        self::assertStringContainsString('@change="setColumnMinHeight($event.target.value)"', $workspace);

        $css = (string) file_get_contents(ROOT_PATH . '/assets/css/tailwind.css');
        self::assertStringContainsString('.yk-r-min-height.yk-r-min-height{', $css, '分设备的最小高度规则编进了样式');
    }
}
