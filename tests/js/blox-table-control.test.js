const test = require('node:test');
const assert = require('node:assert/strict');
const { methods, normalize } = require('../../plugins/blox-pro/assets/blox-pro-table.js');

function editor() {
    return Object.assign({
        selEl: {id: 'table-1', type: 'table', data: {grid: {rows: [['A', 'B'], ['C', 'D']], widths: [120, 200]}}},
        elSchema() { return {controls: [{key: 'grid', default: {rows: [['Default']], widths: [0]}}]}; },
        flushHistory() {},
        runCommand(name, run) { run(); },
        elementPathById(id) { return this.selEl && this.selEl.id === id ? '0.0.0' : ''; },
        elementAtPath(path) { return path === '0.0.0' ? this.selEl : null; },
        $refs: {tableExpandedDialog: {}},
        focusDialog() {}, releaseDialog() {},
        uiText: {saveConflict: 'Conflict'},
        toast(message) { this.lastError = message; },
    }, methods);
}

test('edit cells, reorder columns with widths, add and remove rows and columns', () => {
    const e = editor();
    e.tableCell(1, 1, 'Edited');
    assert.equal(e.tableGrid().rows[1][1], 'Edited');
    e.tableAction('column', 'previous', 1);
    assert.deepEqual(e.tableGrid(), {rows: [['B', 'A'], ['Edited', 'C']], widths: [200, 120]});
    e.tableAction('row', 'previous', 1);
    assert.deepEqual(e.tableGrid().rows[0], ['Edited', 'C']);
    e.tableAction('row', 'add', 0);
    assert.deepEqual(e.tableGrid().rows[1], ['', '']);
    e.tableAction('row', 'delete', 1);
    e.tableAction('column', 'add', 0);
    assert.deepEqual(e.tableGrid().widths, [200, 0, 120]);
    e.tableAction('column', 'delete', 1);
    e.tableWidth(0, 300);
    assert.deepEqual(e.tableGrid().widths, [300, 120]);
});

test('expanded edits are staged, cancellable, and applied as one command', () => {
    const e = editor();
    let commands = 0;
    e.runCommand = (name, run) => { commands++; run(); };
    e.openTableExpanded();
    e.tableCell(0, 0, 'Changed');
    e.tableAction('column', 'add', 1);
    assert.equal(e.selEl.data.grid.rows[0][0], 'A');
    e.closeTableExpanded();
    assert.equal(commands, 0);
    e.openTableExpanded();
    e.tableCell(0, 0, 'Applied');
    e.applyTableExpanded();
    assert.equal(commands, 1);
    assert.equal(e.selEl.data.grid.rows[0][0], 'Applied');
    assert.equal(e.tableExpanded, null);
});

test('stale expanded and canvas edits cannot replace newer cells or another element', () => {
    const e = editor();
    e.openTableExpanded();
    e.selEl.data.grid.rows[0][0] = 'Newer';
    e.applyTableExpanded();
    assert.equal(e.lastError, 'Conflict');
    e.closeTableExpanded();
    e.applyTableCanvasCell({id:'table-1',row:0,column:0,base:'A',value:'Stale'});
    assert.equal(e.selEl.data.grid.rows[0][0], 'Newer');
    e.applyTableCanvasCell({id:'missing',row:0,column:0,base:'Newer',value:'Wrong'});
    e.applyTableCanvasCell({id:'table-1',row:0,column:0,base:'Newer',value:'Canvas'});
    assert.equal(e.selEl.data.grid.rows[0][0], 'Canvas');
});

test('grid limits, last row and column, and stale selections remain safe', () => {
    assert.deepEqual(normalize(null), {rows: [['']], widths: [0]});
    const grid = normalize({rows: Array.from({length: 60}, () => Array(15).fill({})), widths: [-1, 4, 9999]});
    assert.equal(grid.rows.length, 50);
    assert.equal(grid.rows[0].length, 12);
    assert.deepEqual(grid.widths.slice(0, 3), [0, 80, 800]);
    const e = editor();
    e.selEl.data.grid = grid;
    e.tableAction('row', 'add', 0);
    e.tableAction('column', 'add', 0);
    assert.equal(e.tableGrid().rows.length, 50);
    assert.equal(e.tableGrid().widths.length, 12);
    e.selEl.data.grid = normalize(null);
    e.tableAction('row', 'delete', 99);
    e.tableAction('column', 'delete', 99);
    e.tableCell(99, 99, 'ignored');
    assert.deepEqual(e.tableGrid(), normalize(null));
});

test('create table stages dimensions, preserves palette defaults and cancels without insertion', () => {
    const e = editor();
    const palette = {type: 'table', defaults: {caption: 'Caption', grid: {rows: [['Sample']]}}};
    e.historyData = () => JSON.stringify(e.selEl);
    e.$nextTick = run => run();
    let inserted = null;
    e.addElement = (el, target, ready) => { inserted = {el, target, ready}; e.selEl = {id: 'new-table', type: el.type, data: el.defaults}; };
    e.openTableCreate(palette, {kind: 'column', sec: 0, col: 0});
    e.tableCreateSize(4, 10);
    e.closeTableCreate();
    assert.equal(inserted, null);
    e.openTableCreate(palette, {kind: 'column', sec: 0, col: 0});
    e.tableCreateSize(4, 10);
    e.tableCreate.style = 'bordered';
    e.applyTableCreate();
    assert.equal(inserted.ready, true);
    assert.deepEqual(inserted.target, {kind: 'column', sec: 0, col: 0});
    assert.equal(inserted.el.defaults.grid.rows.length, 4);
    assert.equal(inserted.el.defaults.grid.widths.length, 10);
    assert.equal(inserted.el.defaults.grid.rows[0][0], '');
    assert.equal(inserted.el.defaults.table_style, 'bordered');
    assert.equal(palette.defaults.grid.rows[0][0], 'Sample');
    assert.equal(e.tableExpanded.id, 'new-table');
});

test('create dimensions are bounded and stale document targets are rejected', () => {
    const e = editor();
    e.historyData = () => JSON.stringify(e.selEl);
    e.addElement = () => assert.fail('Must not insert at a stale target');
    e.openTableCreate({type: 'table'}, null);
    e.tableCreateSize(999, 999);
    assert.equal(e.tableCreate.rows, 50);
    assert.equal(e.tableCreate.columns, 12);
    e.tableCreateSize('', NaN);
    assert.equal(e.tableCreate.rows, 1);
    assert.equal(e.tableCreate.columns, 1);
    e.selEl.data.grid.rows[0][0] = 'External edit';
    e.applyTableCreate();
    assert.equal(e.lastError, 'Conflict');
});
