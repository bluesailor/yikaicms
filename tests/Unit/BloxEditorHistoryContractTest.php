<?php
/**
 * Blox 本地撤销/重做协议回归测试。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class BloxEditorHistoryContractTest extends TestCase
{
    private function editorSource(): string
    {
        $paths = [
            'admin/blox_editor.php',
            'admin/blox_editor/partials/header.php',
            'admin/blox_editor/partials/workspace.php',
            'admin/blox_editor/partials/overlays.php',
        ];

        return implode("\n", array_map(function (string $path): string {
            $source = file_get_contents(ROOT_PATH . '/' . $path);
            $this->assertNotFalse($source);
            return (string) $source;
        }, $paths));
    }

    private function historySource(): string
    {
        $source = file_get_contents(ROOT_PATH . '/assets/js/blox-history-store.js');
        $this->assertNotFalse($source);

        return (string) $source;
    }

    public function testHistoryUsesBoundedSnapshotsAndMergesPropertyInput(): void
    {
        $editor = $this->editorSource();
        $source = $this->historySource();

        $this->assertStringContainsString('<script src="/assets/js/blox-history-store.js?v=', $editor);
        $this->assertStringContainsString('new window.BloxHistoryStore({', $editor);
        $this->assertStringContainsString('limit: 51,', $editor);
        $this->assertStringContainsString('delay: 700,', $editor);
        $this->assertStringContainsString('if (this.entries.length > this.limit) this.entries.shift();', $source);
        $this->assertStringContainsString('if (snapshot.structure !== current.structure)', $source);
        $this->assertStringContainsString('this.timer = setTimeout(function () { self.flush(false); }, this.delay);', $source);
        $this->assertStringContainsString('this.entries.splice(this.index + 1);', $source);
    }

    public function testSnapshotRestoresLayeredSelectionAndSavedBaseline(): void
    {
        $source = $this->editorSource();

        foreach (['selectedSi', 'selectedCi', 'selectedEi', 'selectedSubEi', 'selectedSectionField', 'selLayer', 'targetCi'] as $field) {
            $this->assertStringContainsString($field . ': this.' . $field, $source);
        }
        $this->assertStringContainsString('this.restoreHistorySelection(snapshot.selection);', $source);
        $this->assertStringContainsString('this.dirty = this.documentData() !== this._savedDocumentSnapshot;', $source);
        $this->assertStringContainsString('var savedData = this.historyData();', $source);
        // 历史仍保存裸 sections；dirty/恢复基线使用完整信封，避免 settings 改动漏报。
        $this->assertStringContainsString('documentData() {', $source);
        $this->assertStringContainsString('this._savedDocumentSnapshot = this.documentData();', $source);
        $this->assertStringContainsString('body.set("blocks_data", payload);', $source);
        $this->assertStringContainsString('acceptSavedDocument(payload, savedData, res)', $source);
        $this->assertStringContainsString('this._savedSnapshot = savedData;', $source);
        $this->assertStringContainsString('this._savedDocumentSnapshot = payload;', $source);
        $this->assertStringContainsString('this.dirty = this.documentData() !== payload;', $source);
        $this->assertStringContainsString('self.acceptSavedDocument(payload, savedData, res);', $source);
    }

    public function testToolbarShortcutsAndTinyMcePriorityAreWired(): void
    {
        $source = $this->editorSource();

        $this->assertStringContainsString('@click="undo()" :disabled="!canUndo()"', $source);
        $this->assertStringContainsString('@click="redo()" :disabled="!canRedo()"', $source);
        $this->assertStringContainsString('if (!(e.ctrlKey || e.metaKey) || e.altKey) return;', $source);
        $this->assertStringContainsString('activeEditor.hasFocus()', $source);
        $this->assertStringContainsString('if (e.shiftKey) self.redo(); else self.undo();', $source);
        $this->assertStringContainsString('key === "y" && !e.shiftKey', $source);
    }

    public function testUndoRedoCopyExistsInAllRequiredLanguages(): void
    {
        foreach (['zh-CN', 'en', 'ja'] as $language) {
            $messages = require ROOT_PATH . '/lang/' . $language . '.php';
            foreach (['blox_undo', 'blox_redo', 'blox_undo_shortcut', 'blox_redo_shortcut', 'blox_undo_done', 'blox_redo_done', 'blox_copy', 'blox_cut', 'blox_paste', 'blox_copy_done', 'blox_cut_done', 'blox_paste_done', 'blox_clipboard_empty', 'blox_clipboard_invalid', 'blox_cut_source_missing'] as $key) {
                $this->assertArrayHasKey($key, $messages, "{$language} 缺少 {$key}");
                $this->assertNotSame('', trim((string) $messages[$key]), "{$language}.{$key} 不得为空");
            }
        }
    }

    public function testClipboardAndCrossColumnDragContracts(): void
    {
        $source = $this->editorSource();

        foreach (['selectionClipboardSource()', 'copySelection()', 'cutSelection()', 'pasteClipboard(', 'pasteSelection()'] as $method) {
            $this->assertStringContainsString($method, $source);
        }
        $this->assertStringContainsString('key === "c" && self.hasClipboardSelection()', $source);
        $this->assertStringContainsString('key === "x" && self.hasClipboardSelection()', $source);
        $this->assertStringContainsString('key === "v" && self.clipboard', $source);
        $this->assertStringContainsString('group: { name: "blox-elements", pull: true, put: true }', $source);
        $this->assertStringContainsString('ghostClass: "blox-sort-ghost"', $source);
        $this->assertStringContainsString('moveElementsBetweenColumns(', $source);
    }
}
