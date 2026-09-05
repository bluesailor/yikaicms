<?php
/** Blox 同级多选（R1）与批量动作（R2）契约：稳定 id 选择模型、双面板入口、
 * 四个批量操作各一次 runCommand、降级边界。
 *
 * 断言口径：这里只锁契约标识符（类名/testid/协议字段/资产登记/命令名），行为断言在
 * tests/js/blox-multi-select.test.js（状态机）、tests/js/blox-multi-actions.test.js
 * （批量数组运算）、tests/js/blox-canvas-bridge.test.js（载荷边界）与
 * tests/e2e/blox-multi-select.spec.js（真实交互）中。
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
        // 编辑器页面必须真实加载这两个模块（登记 ≠ 加载，2026-09 教训）。
        foreach (['assets/js/blox-multi-select.js', 'assets/js/blox-multi-actions.js'] as $script) {
            self::assertStringContainsString($script, $this->source('admin/blox_editor.php'), "编辑器缺少 {$script} 脚本标签");
        }
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

    public function testBatchActionsRunThroughSingleCommands(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $workspace = $this->source('admin/blox_editor/partials/workspace.php');
        $module = $this->source('assets/js/blox-multi-actions.js');
        $policy = json_decode($this->source('config/blox-assets.json'), true, 512, JSON_THROW_ON_ERROR);

        // 四个操作 = 恰好四次 runCommand 包装；数组运算全部在纯模块，热点文件只做薄命令。
        self::assertSame(1, substr_count($editor, 'runCommand("batch-delete"'), '删除必须且只能是一次 runCommand');
        self::assertSame(1, substr_count($editor, 'runCommand("batch-duplicate"'));
        self::assertSame(1, substr_count($editor, 'runCommand("batch-cut"'));
        self::assertSame(1, substr_count($editor, 'runCommand("batch-paste"'));
        foreach (['blox-batch-delete', 'blox-batch-duplicate', 'blox-batch-cut', 'blox-batch-paste'] as $button) {
            self::assertStringContainsString('data-testid="' . $button . '"', $workspace);
        }
        self::assertStringContainsString('global.YikaiBloxMultiActions', $module);
        self::assertStringContainsString('module.exports', $module);
        foreach (['document.', 'fetch(', 'localStorage', 'postMessage'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $module, "批量模块不得包含 {$forbidden}");
        }
        self::assertContains('assets/js/blox-multi-actions.js', $policy['core']);
    }

    public function testBatchLangKeysExistInAllThreeLanguages(): void
    {
        $keys = [
            'blox_batch_delete', 'blox_batch_duplicate', 'blox_batch_cut', 'blox_batch_paste',
            'blox_batch_delete_done', 'blox_batch_duplicate_done', 'blox_batch_cut_done',
            'blox_batch_paste_done', 'blox_batch_paste_rejected', 'blox_batch_failed',
        ];
        foreach ($keys as $key) {
            foreach (['zh-CN', 'en', 'ja'] as $lang) {
                $table = require ROOT_PATH . "/lang/{$lang}.php";
                self::assertArrayHasKey($key, $table, "{$lang} 缺少 {$key}");
                self::assertNotSame('', trim((string) $table[$key]), "{$lang} 的 {$key} 不能为空");
            }
        }
    }

    public function testBatchPropertyEditorUsesPureRegisteredModuleAndOneCommand(): void
    {
        $editor = $this->source('admin/blox_editor.php');
        $workspace = $this->source('admin/blox_editor/partials/workspace.php');
        $module = $this->source('assets/js/blox-multi-properties.js');
        $policy = json_decode($this->source('config/blox-assets.json'), true, 512, JSON_THROW_ON_ERROR);

        self::assertStringContainsString('assets/js/blox-multi-properties.js', $editor);
        self::assertContains('assets/js/blox-multi-properties.js', $policy['core']);
        self::assertStringContainsString('global.YikaiBloxBatchProperties', $module);
        self::assertStringContainsString('module.exports', $module);
        self::assertSame(2, substr_count($module, 'runCommand("batch-set-style"'), '控件与间距各一个命令入口');
        foreach (['document.', 'fetch(', 'localStorage', 'postMessage'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $module, "批量属性模块不得包含 {$forbidden}");
        }
        foreach (['blox-batch-style-panel', 'blox-batch-style-', 'blox-batch-spacing-'] as $testId) {
            self::assertStringContainsString($testId, $workspace);
        }
    }

    public function testBatchPropertyLangKeysExistInAllThreeLanguages(): void
    {
        $keys = [
            'blox_batch_style_title', 'blox_batch_style_mixed', 'blox_batch_style_same_type_required',
            'blox_batch_style_sections_unsupported', 'blox_batch_style_empty', 'blox_batch_style_done',
            'blox_batch_current_device', 'blox_batch_spacing_hint',
        ];
        foreach ($keys as $key) {
            foreach (['zh-CN', 'en', 'ja'] as $lang) {
                $table = require ROOT_PATH . "/lang/{$lang}.php";
                self::assertArrayHasKey($key, $table, "{$lang} 缺少 {$key}");
                self::assertNotSame('', trim((string) $table[$key]), "{$lang} 的 {$key} 不能为空");
            }
        }
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
