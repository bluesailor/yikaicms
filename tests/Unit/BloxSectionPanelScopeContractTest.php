<?php
/**
 * 选中清空时的区块设置面板：面板里的 sel 走 sectionPanelScope() 别名，取消选择、撤销、删除区块的
 * 那一拍不对着 null 求值。浏览器行为回归见 tests/e2e/blox-selection-teardown.spec.js（预检不跑浏览器，这里守住接线）。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxSectionPanelScopeContractTest extends TestCase
{
    public function testBothSectionPanelsReadSelThroughTheirOwnScope(): void
    {
        $workspace = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/workspace.php');
        foreach ([
            '<template x-if="sel && !selEl && !selectedSectionField && panelTab !== \'condition\'">',
            '<template x-if="sel && selectedSectionField">',
        ] as $guard) {
            $at = strpos($workspace, $guard);
            self::assertNotFalse($at, $guard);
            // x-if 克隆出来的根元素就是面板，别名挂在它上面才能盖住整个面板
            self::assertMatchesRegularExpression(
                '~\A\s*<div\b[^>]*\sx-data="sectionPanelScope\(\)"~',
                substr($workspace, $at + strlen($guard)),
                $guard
            );
        }

        $methods = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/media-editing-methods.php');
        self::assertStringContainsString('sectionPanelScope() {', $methods);
    }
}
