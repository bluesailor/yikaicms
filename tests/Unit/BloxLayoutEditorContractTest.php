<?php
/**
 * 布局操作（2.1.0，参照 Bricks 的布局模型）：列设置能存住、添加布局有统一入口、面包屑不露键名。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxLayoutEditorContractTest extends TestCase
{
    private function source(string $path): string
    {
        return $path === 'admin/blox_editor.php' ? bloxEditorSourceForTest() : (string) file_get_contents(ROOT_PATH . '/' . $path);
    }

    public function testColumnSettingsSurviveSaving(): void
    {
        $doc = (string) json_encode(['schema' => 1, 'settings' => [], 'sections' => [[
            'id' => 's1', 'type' => 'section', 'settings' => [],
            'columns' => [[
                'id' => 'c1', 'span' => 3, 'hide_on' => ['m', 'x', 'm', 't'], 'position' => 'center',
                'card_bg' => '#ffffff', 'elements' => [],
            ]],
        ]]]);
        $column = BloxDocumentPipeline::process($doc)['sections'][0]['columns'][0];
        self::assertSame(['m', 't'], $column['hide_on'], '显示设备一保存就丢是 2.1.0 前的老问题');
        self::assertSame('center', $column['position']);
        self::assertSame('#ffffff', $column['card_bg']);

        self::assertSame([], BloxDocumentPipeline::normalizeColumnExtras(['position' => 'left', 'hide_on' => []]), '靠左是默认，不落盘');
        self::assertSame([], BloxDocumentPipeline::normalizeColumnExtras(['position' => 'middle', 'hide_on' => 'x,y']));
        self::assertSame(['hide_on' => ['d', 'w']], BloxDocumentPipeline::normalizeColumnExtras(['hide_on' => 'd, w']));
    }

    public function testHomeDocumentsKeepTheSameColumnSettings(): void
    {
        foreach (['includes/builder/HomeBloxDocument.php', 'includes/builder/HomeLayoutDocument.php'] as $path) {
            self::assertStringContainsString('BloxDocumentPipeline::normalizeColumnExtras($column)', $this->source($path), $path);
        }
    }

    public function testOneLayoutPickerServesToolbarCanvasAndStructureTree(): void
    {
        $header = $this->source('admin/blox_editor/partials/header.php');
        self::assertStringContainsString('@click.stop="openLayoutPicker($event)" data-testid="blox-layout-open"', $header);
        self::assertStringContainsString('data-testid="blox-mobile-add-layout"', $header);

        $canvas = $this->source('includes/builder/BloxCanvasPreview.php');
        self::assertStringContainsString("emptyActionButton('layout', @@blox_choose_layout@@", $canvas);
        self::assertStringContainsString('insertPopover(0, b.getBoundingClientRect())', $canvas, '空画布与区块边界「+」同一个选择器');
        self::assertStringContainsString("'@@blox_choose_layout@@' =>", $canvas);
        // 空画布最后一个按钮仍是「添加空白区块」（e2e 辅助函数按 last() 点它）
        self::assertMatchesRegularExpression("/d\\.appendChild\\(layout\\);\\s*d\\.appendChild\\(emptyActionButton\\('section'/", $canvas);

        $picker = $this->source('admin/blox_editor/partials/overlays.php');
        foreach (['4-8', '8-4', '3-9', '9-3', '3-6-3', '3'] as $ratio) {
            self::assertStringContainsString('data-testid="blox-add-section-ratio-<?= e(implode', $picker);
            self::assertStringContainsString('[' . str_replace('-', ', ', $ratio) . ']', $picker, $ratio);
        }
        self::assertStringContainsString('chooseSectionPreset(', $picker);
    }

    public function testColumnPanelSpeaksInFractionsAndHasAPosition(): void
    {
        $workspace = $this->source('admin/blox_editor/partials/workspace.php');
        self::assertStringContainsString('spanFraction(n)', $workspace);
        self::assertStringContainsString('data-testid="blox-column-position"', $workspace);
        self::assertStringContainsString('x-show="columnSizingVisible() && !sel.settings.tablet_stack"', $workspace, '单列不足整行时也能分设平板宽度');
        self::assertStringContainsString('data-testid="blox-layout-apply-hint"', $workspace);

        $editor = $this->source('admin/blox_editor.php');
        self::assertStringNotContainsString("\$jt('blox_el_container')", $editor, '面包屑曾直接显示键名 blox_el_container');
        self::assertStringContainsString('items.push({ act: "con", label: runtimeText.treeContainer });', $editor);
    }

    public function testNewInterfaceTextExistsInTheBundledLanguages(): void
    {
        foreach (['zh-CN', 'en', 'ja'] as $lang) {
            $strings = require ROOT_PATH . '/lang/' . $lang . '.php';
            foreach (['blox_add_layout', 'blox_choose_layout', 'blox_layout_equal', 'blox_layout_by_ratio', 'blox_layout_single_quarter',
                'blox_layout_apply_hint', 'blox_col_full_width', 'blox_col_position', 'blox_col_position_hint', 'blox_tree_container'] as $key) {
                self::assertNotSame('', (string) ($strings[$key] ?? ''), $lang . ':' . $key);
            }
        }
    }
}
