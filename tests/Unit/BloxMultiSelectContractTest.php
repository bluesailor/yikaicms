<?php
/** Blox 同级多选（R1）契约：稳定 id 选择模型、双面板入口与降级边界。
 *
 * 断言口径：只锁契约标识符（类名/testid/协议字段/资产登记），行为断言在
 * tests/js/blox-multi-select.test.js（状态机）、tests/js/blox-canvas-bridge.test.js
 * （载荷边界）与 tests/e2e/blox-multi-select.spec.js（真实交互）中。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BloxMultiSelectContractTest extends TestCase
{
    public function testSelectionStateModuleIsPureAndRegistered(): void
    {
        $module = $this->source('assets/js/blox-multi-select.js');
        $policy = json_decode($this->source('config/blox-assets.json'), true, 512, JSON_THROW_ON_ERROR);

        // 纯状态机：不碰 DOM、不发包、不写存储。
        foreach (['document.', 'fetch(', 'XMLHttpRequest', 'localStorage', 'postMessage'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $module, "选择状态机不得包含 {$forbidden}");
        }
        self::assertStringContainsString('global.YikaiBloxMultiSelect', $module);
        self::assertStringContainsString('module.exports', $module);
        self::assertContains('assets/js/blox-multi-select.js', $policy['core']);
        // 读取付费源码的契约测试登记进 private 桶（与 BloxEditorPreviewContractTest 对齐）。
        self::assertContains('tests/Unit/BloxMultiSelectContractTest.php', $policy['private']);
    }

    public function testEditorWiresSameLevelMultiSelect(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $workspace = $this->source('admin/blox_editor/partials/workspace.php');

        // 模块缺失降级：编辑器入口先取模块再行动，不裸调 window.*。
        self::assertStringContainsString('multiSelModule()', $editor);
        // 三类树节点（区块/元素/子元素）都走修饰键分发入口。
        foreach (['treeSectionClick($event, si)', 'treeElementClick($event, si, ci, ei)', 'treeChildClick($event, si, ci, ei, cei)'] as $entry) {
            self::assertStringContainsString($entry, $workspace, "结构树缺少多选入口 {$entry}");
        }
        // 画布点击经多选分发（含修饰键），且集合按稳定 id 同步回画布。
        self::assertStringContainsString('canvasPickElement(target)', $editor);
        self::assertStringContainsString('canvasPickSection(target)', $editor);
        self::assertStringContainsString('ykMultiIds', $editor);
        // N>1 时属性面板让位给批量操作条（R1 空壳）。
        self::assertStringContainsString('data-testid="blox-batch-bar"', $workspace);
        self::assertStringContainsString('data-testid="blox-batch-count"', $workspace);
        self::assertStringContainsString('!multiSelActive()', $workspace);
        // 行内多选高亮用稳定 id 驱动。
        self::assertSame(3, substr_count($workspace, 'data-multi-selected'), '区块/元素/子元素三行都要有 data-multi-selected');
        // Esc 清空与文档变化失效都接在中央位置。
        self::assertStringContainsString('self.multiSelClear()', $editor);
    }

    public function testCanvasRelaysModifiersAndMultiOutline(): void
    {
        $canvas = $this->source('includes/builder/BloxCanvasPreview.php');

        // 画布点击带出修饰键；多选描边为独立类，不与单选描边混用。
        self::assertStringContainsString('ykMultiIds', $canvas);
        self::assertStringContainsString('yk-multi-selected', $canvas);
        self::assertStringContainsString('data-yk-el-id', $canvas);
        self::assertStringContainsString('data-yk-sec-id', $canvas);
        // 画布内 Esc 透传给编辑器清空多选。
        self::assertStringContainsString("ykEscape: true", $canvas);
    }

    public function testMultiSelectLangKeysExistInAllThreeLanguages(): void
    {
        foreach (['blox_multi_selected_count', 'blox_multi_hint'] as $key) {
            foreach (['zh-CN', 'en', 'ja'] as $lang) {
                $table = require ROOT_PATH . "/lang/{$lang}.php";
                self::assertArrayHasKey($key, $table, "{$lang} 缺少 {$key}");
                self::assertNotSame('', trim((string) $table[$key]), "{$lang} 的 {$key} 不能为空");
            }
        }
        self::assertStringContainsString(':count', (require ROOT_PATH . '/lang/en.php')['blox_multi_selected_count']);
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
