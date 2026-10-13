const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function fixture() {
    const context = { window: { innerWidth: 1440, innerHeight: 900 } };
    vm.runInNewContext(fs.readFileSync(path.resolve(__dirname, '../../assets/js/yikay-section-insert.js'), 'utf8'), context);
    const state = context.window.YikaiBloxSectionInsert.mixin({ after: 'After :name', start: 'Start', changed: 'Missing target', split: 'Split', splitHint: 'Split hint' });
    return Object.assign(state, {
        sections: [{ id: 'a' }, { id: 'b' }, { id: 'c' }], calls: [], notices: [], focused: 0,
        $refs: {
            canvas: { clientWidth: 1000, isConnected: true, focus() {}, getBoundingClientRect: () => ({ left: 200, top: 80, width: 800, bottom: 800 }) },
            sectionInsertPicker: {
                querySelector: () => ({ focus: () => { state.focused++; } }),
                querySelectorAll: () => [{ offsetParent: null, focus: () => {} }, { offsetParent: {}, focus: () => { state.focused++; } }]
            }
        },
        $nextTick(callback) { callback(); },
        sectionLabel(section) { return section.id; },
        addSection(count) { this.calls.push({ index: this._insertAt, count }); },
        openPrebuiltSections() { this.calls.push({ index: this._insertAt, template: true }); },
        toast(message) { this.notices.push(message); }
    });
}

test('opening and cancelling a picker changes neither document nor history', () => {
    const state = fixture();
    const before = JSON.stringify(state.sections);
    state.openSectionInsert(2);
    assert.equal(state.sectionInsertLabel, 'After b');
    assert.equal(state.focused, 1);
    state.closeSectionInsert();
    state.chooseSectionLayout(2);
    assert.equal(state.sectionInsertOpen, false);
    assert.equal(JSON.stringify(state.sections), before);
    assert.deepEqual(state.calls, []);
});

test('all six layouts use one existing add command at each exact boundary', () => {
    for (const index of [0, 1, 3]) {
        for (let count = 1; count <= 6; count++) {
            const state = fixture();
            state.openSectionInsert(index);
            state.chooseSectionLayout(count);
            assert.deepEqual(state.calls, [{ index, count }]);
            assert.equal(state._insertAt, null);
        }
    }
});

test('insertion follows the named section if it moves while the picker is open', () => {
    const state = fixture();
    state.openSectionInsert(2);
    state.sections = [state.sections[1], state.sections[0], state.sections[2]];
    state.chooseSectionLayout(1);
    assert.deepEqual(state.calls, [{ index: 1, count: 1 }]);
});

test('a removed insertion target is refused rather than silently appending', () => {
    const state = fixture();
    state.openSectionInsert(2);
    state.sections.splice(1, 1);
    state.chooseSectionLayout(1);
    assert.deepEqual(state.calls, []);
    assert.deepEqual(state.notices, ['Missing target']);
});

test('invalid boundaries and column counts do not insert', () => {
    const state = fixture();
    for (const index of [-1, 4, 1.5, '1']) state.openSectionInsert(index);
    assert.equal(state.sectionInsertOpen, false);
    state.openSectionInsert(1);
    for (const count of [0, 7, 1.5, '2']) state.chooseSectionLayout(count);
    assert.deepEqual(state.calls, []);
});

test('canvas coordinates account for scaling and stay within the viewport', () => {
    const state = fixture();
    state.insertAtBoundary({ index: 1, kind: 'picker', anchor: { x: 500, y: 300 } });
    assert.equal(state.sectionInsertStyle, 'width:304px;left:448px;top:326px');
    // 贴底时按弹层实际高度上移，并在底部留出约 100px（900 - 330 - 100 = 470）
    state.$refs.sectionInsertPicker.offsetHeight = 330;
    state.openSectionInsert(1, null, { x: 9000, y: 9000 });
    assert.equal(state.sectionInsertStyle, 'width:304px;left:1124px;top:470px');
    // 高度尚未可测时先按保守估计定位，不贴底
    delete state.$refs.sectionInsertPicker.offsetHeight;
    state.openSectionInsert(1, null, { x: 9000, y: 9000 });
    assert.equal(state.sectionInsertStyle, 'width:304px;left:1124px;top:460px');
    assert.deepEqual(state.calls, []);
});

test('template library preserves the same insertion boundary', () => {
    const state = fixture();
    state.openSectionInsert(2);
    state.chooseSectionTemplate();
    assert.equal(state.sectionInsertOpen, false);
    assert.deepEqual(state.calls, [{ index: 2, template: true }]);
});

test('an add failure cannot leak the temporary insertion index', () => {
    const state = fixture();
    state.addSection = () => { throw new Error('failed'); };
    state.openSectionInsert(2);
    assert.throws(() => state.chooseSectionLayout(1), /failed/);
    assert.equal(state._insertAt, null);
});

test('quick add is limited to the explicitly chosen live column or container', () => {
    const state = fixture();
    state.sections = [{ id: 's1', columns: [{ id: 'c1' }, { id: 'c2' }] }];
    Object.assign(state, { selectedSi: 0, selectedCi: 1, selectedEi: -1, libOpen: true });
    state.elSchema = type => ({ container: type === 'container' });
    state.quickAddTargetId = state.quickAddContextId();
    assert.equal(state.hasQuickAddTarget(), true);
    state.selectedCi = 0;
    assert.equal(state.hasQuickAddTarget(), false);
    state.selectedCi = 1;
    state.libOpen = false;
    assert.equal(state.hasQuickAddTarget(), false);
    state.libOpen = true;
    state.sections[0].columns[1] = { id: 'replacement' };
    assert.equal(state.hasQuickAddTarget(), false);
    state.selectedEi = 0;
    state.selEl = { id: 'nested', type: 'container' };
    state.quickAddTargetId = state.quickAddContextId();
    assert.equal(state.hasQuickAddTarget(), true);
    state.selEl = { id: 'nested', type: 'heading' };
    assert.equal(state.hasQuickAddTarget(), false);
    state.selectedSi = -1;
    assert.equal(state.quickAddContextId(), '');
});

test('desktop palette click inserts into the selected section and never guesses a target', () => {
    // 元素库方法已拆入 partial（0a 编辑器拆模块），与主文件视作同一逻辑源
    const source = fs.readFileSync(path.resolve(__dirname, '../../admin/blox_editor.php'), 'utf8')
        + fs.readFileSync(path.resolve(__dirname, '../../admin/blox_editor/partials/element-library-methods.php'), 'utf8');
    const body = source.match(/activatePaletteElement\(el, event\) \{([\s\S]*?)\n            \},/)[1];
    const activate = new Function('el', 'event', body);
    const state = fixture();
    Object.assign(state, {
        sections: [{ id: 's1', columns: [{ id: 'c1' }] }],
        selectedSi: 0, selectedCi: 0, selectedEi: -1, libOpen: true,
        paletteTapMode: false, uiText: { pickSectionFirst: 'Pick a section' },
        addElement(el) { this.calls.push(el.type); }
    });
    const element = { type: 'heading', label: 'Heading' };
    // 2.0：桌面单击直接插入当前选中区块，不再要求先点「+ 添加元素」
    activate.call(state, element, { detail: 1 });
    assert.deepEqual(state.calls, ['heading']);
    state.quickAddTargetId = state.quickAddContextId();
    activate.call(state, element, { detail: 1 });
    assert.deepEqual(state.calls, ['heading', 'heading']);
    assert.equal(state.quickAddTargetId, '');
    activate.call(state, element, { detail: 0 });
    assert.deepEqual(state.calls, ['heading', 'heading', 'heading']);
    // 有区块却没选中：提示先选，不猜位置
    state.selectedSi = -1;
    activate.call(state, element, { detail: 1 });
    assert.deepEqual(state.calls, ['heading', 'heading', 'heading']);
    assert.equal(state.paletteSelected, '');
});

test('ratio presets insert their spans once; invalid spans or totals over 12 do nothing (2.1.0)', () => {
    for (const spans of [[4, 8], [3, 6, 3], [3]]) {
        const state = fixture();
        state.openSectionInsert(1);
        state.chooseSectionPreset(spans);
        assert.deepEqual(JSON.parse(JSON.stringify(state.calls)), [{ index: 1, count: spans }]);
        assert.equal(state._insertAt, null);
        assert.equal(state.sectionInsertOpen, false);
    }
    for (const spans of [[], [8, 8], [0], [13], [2.5], 'x', [1, 1, 1, 1, 1, 1, 1]]) {
        const state = fixture();
        state.openSectionInsert(1);
        state.chooseSectionPreset(spans);
        assert.deepEqual(state.calls, []);
    }
    const closed = fixture();
    closed.chooseSectionPreset([4, 8]);
    assert.deepEqual(closed.calls, [], 'picker must be open');
});

test('toolbar layout entry opens the same picker at the next insertion point (2.1.0)', () => {
    const state = fixture();
    state.insertIndex = () => 2;
    state.openLayoutPicker({ currentTarget: { isConnected: true, focus() {}, getBoundingClientRect: () => ({ left: 100, top: 10, width: 32, bottom: 42 }) } });
    assert.equal(state.sectionInsertOpen, true);
    assert.equal(state.sectionInsertLabel, 'After b');
    state.chooseSectionPreset([3, 9]);
    assert.deepEqual(JSON.parse(JSON.stringify(state.calls)), [{ index: 2, count: [3, 9] }]);
});

function splitFixture() {
    const state = fixture();
    const column = { id: 'c1', elements: [] };
    const div = { id: 'd1', type: 'div', data: { children: [] } };
    state.sections = [{ id: 's1', columns: [column, { id: 'c2', elements: [div] }] }];
    state.ctx = { kind: 'column', target: { si: 0, ci: 0 }, x: 40, y: 50 };
    state.uidCount = 0;
    state.commands = [];
    Object.assign(state, {
        uid(prefix) { this.uidCount += 1; return prefix + this.uidCount; },
        elSchema(type) { return { label: type === 'container' ? 'Container' : 'Div', defaults: type === 'container' ? { layout: 'flex', gap: 'md' } : { direction: 'column' } }; },
        elementAtPath(path) { return path === '0.1.0' ? div : null; },
        ctxChildSubPath() { return []; },
        closeCtx() { this.ctx.open = false; },
        runCommand(name, fn) { this.commands.push(name); return fn.call(this); },
        insertElementAt(node, target, label) { this.calls.push({ node, target, label }); return true; },
    });
    return { state, column, div };
}

test('split mode builds one grid container of divs, equal or by ratio (2.1.0)', () => {
    const { state } = splitFixture();
    state.openSplitPicker({ kind: 'column', sec: 0, col: 0 });
    assert.equal(state.sectionInsertMode, 'split');
    assert.equal(state.sectionInsertLabel, 'Split hint');
    state.chooseSectionLayout(3);
    assert.deepEqual(state.commands, ['split-layout']);
    const equal = state.calls[0].node;
    assert.equal(equal.type, 'container');
    assert.equal(equal.data.layout, 'grid');
    assert.equal(equal.data.grid_cols, '3');
    assert.equal(equal.data.gap, 'md', 'container defaults kept');
    assert.equal(equal.data.children.length, 3);
    assert.ok(equal.data.children.every((child) => child.type === 'div' && child.data.grid_span === undefined && child.data.direction === 'column'));
    assert.deepEqual(JSON.parse(JSON.stringify(state.calls[0].target)), { kind: 'column', sec: 0, col: 0 });
    assert.equal(state.sectionInsertOpen, false);

    state.openSplitPicker({ kind: 'container', path: '0.1.0' });
    state.chooseSectionPreset([4, 8]);
    const ratio = state.calls[1].node;
    assert.equal(ratio.data.grid_cols, '12');
    assert.deepEqual(ratio.data.children.map((child) => child.data.grid_span), ['4', '8']);
    assert.deepEqual(JSON.parse(JSON.stringify(state.calls[1].target)), { kind: 'container', path: '0.1.0' });
});

test('split refuses one column, unsupported spans, other element types and stale targets (2.1.0)', () => {
    const { state, column } = splitFixture();
    state.openSplitPicker({ kind: 'column', sec: 0, col: 0 });
    state.chooseSectionLayout(1);
    state.chooseSectionLayout(5);
    state.chooseSectionPreset([3]);
    state.chooseSectionPreset([5, 7]);
    assert.deepEqual(state.calls, []);
    assert.equal(state.sectionInsertOpen, true, 'invalid picks leave the picker open');

    state.sections[0].columns[0] = { id: 'c1', elements: [] }; // undo replaced the column object
    state.chooseSectionPreset([4, 8]);
    assert.deepEqual(state.calls, []);
    assert.deepEqual(state.notices, ['Missing target']);
    assert.notEqual(state.sections[0].columns[0], column);

    state.openSplitPicker({ kind: 'container', path: '9.9.9' });
    assert.equal(state.sectionInsertOpen, false, 'no host, no picker');
    state.openSplitPicker({ kind: 'column', sec: 0, col: 0 });
    state.closeSectionInsert();
    state.openSectionInsert(0);
    assert.equal(state.sectionInsertMode, 'section', 'section insert resets the mode');
});

test('context menu splits columns and container elements at the menu position (2.1.0)', () => {
    const { state } = splitFixture();
    assert.deepEqual(JSON.parse(JSON.stringify(state.ctxSplitTarget())), { kind: 'column', sec: 0, col: 0 });
    state.ctx = { kind: 'element', target: { si: 0, ci: 1, ei: 0 }, x: 300, y: 200 };
    assert.deepEqual(JSON.parse(JSON.stringify(state.ctxSplitTarget())), { kind: 'container', path: '0.1.0' });
    state.openSplitFromCtx();
    assert.equal(state.sectionInsertMode, 'split');
    assert.match(state.sectionInsertStyle, /top:200px/);
    state.ctx = { kind: 'section', target: { si: 0 }, x: 0, y: 0 };
    assert.equal(state.ctxSplitTarget(), null);
});
