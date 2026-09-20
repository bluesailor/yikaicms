const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '../..');
const editor = fs.readFileSync(path.join(root, 'admin/blox_editor.php'), 'utf8');
const preview = fs.readFileSync(path.join(root, 'includes/builder/BloxCanvasPreview.php'), 'utf8');
const clearBody = editor.match(/deselectAll\(\) \{([\s\S]*?)\n            \},/)[1];
const keyboardPrefix = editor.split('window.addEventListener("keydown", function (e) {')[1]
    .split('if (!(e.ctrlKey || e.metaKey) || e.altKey) return;')[0];

function selectedState() {
    return {
        selectedSi: 1, selectedCi: 0, selectedEi: 0, selectedSubEi: 1, selectedSubPath: [1],
        selectedSectionField: 'title', selectedHomeField: 'text', selectedHomeColumn: 'main',
        selLayer: 'el', _insertAt: { index: 2 }, libOpen: true, mobilePanel: 'settings',
        sections: [{ id: 'one' }, { id: 'two' }], dirty: false,
        get sel() { return this.sections[this.selectedSi] || null; },
        // 0b：子级选区路径写入口（真实实现见 blox_editor.php setSubSelection）
        setSubSelection(subPath) {
            this.selectedSubPath = Array.isArray(subPath) ? subPath.slice() : [];
            this.selectedSubEi = this.selectedSubPath.length ? this.selectedSubPath[0] : -1;
        },
        multiSelClear() { this.multiReset = true; },
        multiSelReset() { this.multiReset = true; },
        multiSelActive() { return false; },
        expandLeftPanel() { this.leftPanelCollapsed = false; },
        highlightCanvasSelection() { this.highlighted = this.selectedSi; },
        deselectAll: new Function(clearBody),
        ctx: { open: false },
        closeCtx() { this.ctx.open = false; },
        finishPaletteDrag() { this.canvasDragActive = false; },
    };
}

test('deselect restores the library and append target without editing document content', () => {
    const state = selectedState();
    const before = JSON.stringify(state.sections);
    state.deselectAll();
    assert.equal(state.sel, null);
    assert.equal(state.selectedSubEi, -1);
    assert.equal(state.selectedHomeField, '');
    assert.equal(state._insertAt, null);
    assert.equal(state.mobilePanel, 'library');
    assert.equal(state.multiReset, true);
    assert.equal(state.highlighted, -1);
    assert.equal(JSON.stringify(state.sections), before);
    assert.equal(state.dirty, false);
});

function escape(state, options = {}) {
    const document = {
        activeElement: { matches: () => !!options.input, isContentEditable: !!options.contentEditable },
        querySelectorAll: () => options.dialog ? [{ getClientRects: () => [1] }] : [],
    };
    const event = { key: 'Escape', defaultPrevented: !!options.prevented, preventDefault() { this.defaultPrevented = true; } };
    vm.runInNewContext('(function(e){' + keyboardPrefix + '})(event)', { self: state, document, event });
    return event;
}

test('Escape cancels a single selection', () => {
    const state = selectedState();
    assert.equal(escape(state).defaultPrevented, true);
    assert.equal(state.sel, null);
});

test('Escape keeps selection while editing a field or handling a dialog', () => {
    for (const options of [{ input: true }, { contentEditable: true }, { dialog: true }, { prevented: true }]) {
        const state = selectedState();
        escape(state, options);
        assert.equal(state.selectedSi, 1);
    }
});

test('Escape closes a context menu or cancels dragging before clearing selection', () => {
    const state = selectedState();
    state.ctx.open = true;
    escape(state);
    assert.equal(state.ctx.open, false);
    assert.equal(state.selectedSi, 1);
    state.canvasDragActive = true;
    escape(state);
    assert.equal(state.canvasDragActive, false);
    assert.equal(state.selectedSi, 1);
});

test('canvas Escape uses the same clear protocol and respects inline-edit cancellation', () => {
    const body = preview.match(/document.addEventListener\('keydown', function \(e\) \{([\s\S]*?)\n    \}\);/)[1];
    const messages = [];
    const handler = vm.runInNewContext('(function(e){' + body + '})', { postToEditor: data => messages.push(data) });
    handler({ key: 'Escape', defaultPrevented: true });
    assert.equal(messages.length, 0);
    handler({ key: 'Escape', defaultPrevented: false, preventDefault() {} });
    assert.equal(messages.length, 1);
    assert.equal(messages[0].ykEscape, true);
});
