<?php
/**
 * TASK-002 第 4 项接线契约：画布拾取后结构面板要定位到"最深选中行"。
 *
 * 说明：这里锁定的是**接线与守卫**（哪几个画布动作触发定位、定位取哪一行、
 * 有哪些提前返回），行为本身在浏览器里实测（见 progress/TASK-002.md）——
 * 不在单元测试里伪造 DOM 去模拟滚动。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BloxTreeRevealContractTest extends TestCase
{
    /** @return array<string,string> 关键文件源码（编辑器由多个 partial 组成） */
    private function sources(): array
    {
        $files = [
            'editor' => 'admin/blox_editor.php',
            'workspace' => 'admin/blox_editor/partials/workspace.php',
        ];
        $out = [];
        foreach ($files as $key => $path) {
            $source = file_get_contents(ROOT_PATH . '/' . $path);
            self::assertNotFalse($source, "无法读取 {$path}");
            $out[$key] = (string) $source;
        }
        return $out;
    }

    /** 画布端拾取的四类目标都必须触发定位（元素/列/容器/区块）。 */
    public function testCanvasPickHandlersRevealTheTreeRow(): void
    {
        $editor = $this->sources()['editor'];

        foreach (['onPickElement', 'onEditElement', 'onPickColumn', 'onPickContainer', 'onPickSection'] as $handler) {
            self::assertMatchesRegularExpression(
                '/' . $handler . ': function \([^)]*\) \{[^}]*revealTreeSelection\(\);/',
                $editor,
                $handler . ' 应在选中后调用 revealTreeSelection()'
            );
        }
    }

    /** 定位取"最深选中行"，且在没有命中行时不抛错。 */
    public function testRevealPrefersTheDeepestSelectedRow(): void
    {
        $editor = $this->sources()['editor'];

        $element = strpos($editor, '[data-testid=blox-tree-element][data-selected="1"]');
        $column = strpos($editor, '[data-testid=blox-tree-column][data-selected="1"]');
        $container = strpos($editor, '[data-testid=blox-tree-container][data-selected="1"]');
        $section = strpos($editor, '[data-testid=blox-tree-section][data-selected="1"]');

        self::assertNotFalse($element, '元素行选择器缺失');
        self::assertNotFalse($column, '列行选择器缺失');
        self::assertNotFalse($container, '容器行选择器缺失');
        self::assertNotFalse($section, '区块行选择器缺失');
        self::assertTrue($element < $column && $column < $container && $container < $section, '选择器必须由内到外排序');
    }

    /** 三条守卫：面板不可见 / 行被折叠 / 行已完整可见，都不滚动。 */
    public function testRevealKeepsItsGuards(): void
    {
        $editor = $this->sources()['editor'];

        self::assertStringContainsString('if (typeof this.rightPanelContentVisible === "function" && !this.rightPanelContentVisible()) return;', $editor);
        self::assertStringContainsString('if (!panel || !panel.getBoundingClientRect().height) return;', $editor);
        self::assertStringContainsString('if (!rowRect.height) return;', $editor);
        self::assertStringContainsString('if (rowRect.top >= panelRect.top && rowRect.bottom <= panelRect.bottom) return;', $editor);
        // 不抢焦点：定位过程只改 scrollTop，不调用 focus()
        $helperStart = strpos($editor, 'revealTreeRowOnce()');
        self::assertNotFalse($helperStart);
        $helper = substr($editor, (int) $helperStart, 1600);
        self::assertStringNotContainsString('.focus(', $helper, '定位不应抢焦点');
        self::assertStringNotContainsString('scrollIntoView', $helper, '不用 scrollIntoView（避免平滑动画与父链滚动）');
    }

    /** 结构树三类行都要带 data-selected，否则定位取不到目标。 */
    public function testTreeRowsExposeSelectionMarker(): void
    {
        $workspace = $this->sources()['workspace'];

        foreach (["blox-tree-section", "blox-tree-container", "blox-tree-column", "blox-tree-element"] as $testid) {
            $needle = 'data-testid="' . $testid . '"';
            $at = strpos($workspace, $needle);
            self::assertNotFalse($at, $testid . ' 未找到');
            // 属性顺序在不同行里不一致（有的 selected 在 testid 前、有的在后），
            // 因此在同一行/相邻行的窗口内确认存在标记即可。
            $window = substr($workspace, max(0, (int) $at - 600), 900);
            self::assertStringContainsString(':data-selected=', $window, $testid . ' 需要 data-selected 标记');
        }
    }
}
