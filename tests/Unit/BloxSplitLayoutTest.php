<?php
/**
 * 拆分（2.1.0，参照 Bricks 在 Block 里再插列）：列 / 容器 / 布局块里放一个网格容器，按比例分成几个布局块。
 * 数据沿用容器 grid + 子项 grid_span；这里锁住比例需要的 8、9 跨度能存、能出类、编进了样式，以及编辑器接线。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxSplitLayoutTest extends TestCase
{
    /** @return array<string,mixed> */
    private function splitDocument(): array
    {
        $div = static fn (string $id, string $span): array => [
            'id' => $id, 'type' => 'div', 'data' => ['grid_span' => $span, 'children' => [
                ['id' => $id . 't', 'type' => 'text', 'data' => ['content' => 'x' . $id], 'children' => []],
            ]],
        ];
        return ['schema' => 1, 'settings' => [], 'sections' => [[
            'id' => 's1', 'type' => 'section', 'settings' => [],
            'columns' => [['id' => 'c1', 'elements' => [[
                'id' => 'g1', 'type' => 'container',
                'data' => ['layout' => 'grid', 'grid_cols' => '12', 'children' => [$div('a', '4'), $div('b', '8'), $div('c', '9')]],
            ]]]],
        ]]];
    }

    public function testRatioSpansSurviveSavingAndRenderOnTheGrid(): void
    {
        $saved = BloxDocumentPipeline::process((string) json_encode($this->splitDocument()));
        $children = $saved['sections'][0]['columns'][0]['elements'][0]['data']['children'];
        self::assertSame(['4', '8', '9'], array_map(static fn (array $child): string => (string) $child['data']['grid_span'], $children));

        $html = BlockRenderer::render($saved['json']);
        self::assertStringContainsString('grid-cols-1 md:grid-cols-12', $html, '手机上下排列，平板起按 12 格');
        self::assertStringContainsString('md:col-span-8', $html);
        self::assertStringContainsString('md:col-span-9', $html);
        self::assertStringContainsString('md:col-span-4', $html);
    }

    public function testNewSpanClassesAreCompiled(): void
    {
        $css = (string) file_get_contents(ROOT_PATH . '/assets/css/tailwind.css');
        foreach (['.md\\:col-span-8{', '.md\\:col-span-9{', '.lg\\:col-span-8{', '.lg\\:col-span-9{'] as $class) {
            self::assertStringContainsString($class, $css, $class);
        }
    }

    public function testEditorOffersSplitFromPanelsMenuAndCanvas(): void
    {
        $workspace = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/workspace.php');
        self::assertStringContainsString('data-testid="blox-column-split"', $workspace);
        self::assertStringContainsString('data-testid="blox-element-split"', $workspace);

        $picker = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/overlays.php');
        self::assertStringContainsString("sectionInsertMode === 'split' ?", $picker, '拆分时换标题');
        self::assertSame(3, substr_count($picker, "x-show=\"sectionInsertMode !== 'split'\""), '拆分时藏起 1 列 / 5 列 / 1/4 单栏 / 模板导入');

        $editor = bloxEditorSourceForTest();
        self::assertStringContainsString('if (action === "split") { this.openSplitFromCtx(); return; }', $editor);
        self::assertStringContainsString('onSplitAt: function (payload) { self.openSplitPicker(payload.target, null, payload.anchor); }', $editor);
        self::assertStringContainsString("'splitHint' => __('blox_split_hint')", $editor);

        $canvas = (string) file_get_contents(ROOT_PATH . '/includes/builder/BloxCanvasPreview.php');
        self::assertStringContainsString('postToEditor({ ykSplitAt: { target: target', $canvas);
        self::assertStringContainsString("if (hostType === 'container' || hostType === 'div') d.appendChild(splitButton(", $canvas);
        self::assertStringContainsString("'@@blox_split_layout@@' =>", $canvas);
        self::assertStringContainsString("if (c.querySelector('[data-yk-el]')) return;", $canvas, '拆出来的空网格本身不再挂提示');

        foreach (['zh-CN', 'en', 'ja'] as $lang) {
            $strings = require ROOT_PATH . '/lang/' . $lang . '.php';
            foreach (['blox_split_layout', 'blox_split_title', 'blox_split_hint'] as $key) {
                self::assertNotSame('', (string) ($strings[$key] ?? ''), $lang . ':' . $key);
            }
        }
    }
}
