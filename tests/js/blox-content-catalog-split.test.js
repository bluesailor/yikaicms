const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

// 循环模板方法是编辑器 Alpine 方法表的一段纯 JS（partial 在 ?> 之后）
const source = fs.readFileSync(path.join(__dirname, '../../admin/blox_editor/partials/loop-template-methods.php'), 'utf8');
const methods = new Function('return ({' + source.slice(source.indexOf('?>') + 2) + '});')();

function editor(data) {
    const catalog = { id: 'e_catalog', type: 'content-catalog', data };
    let uid = 0;
    return Object.assign({
        selEl: catalog, selTopEl: catalog, selectedSi: 1, selectedCi: 0, selectedEi: 0, selectedSubEi: -1,
        elementLib: ['image', 'heading', 'text'].map(type => ({ type, defaults: { seeded: true } })),
        flushes: 0,
        newElementNode(lib) { return { id: 'e_' + (++uid), type: lib.type, data: JSON.parse(JSON.stringify(lib.defaults)) }; },
        flushHistory() { this.flushes++; },
        runCommand(_name, fn) { return fn.call(this); },
        selectChild(si, ci, ei, sub) { this.selected = [si, ci, ei, sub]; },
    }, methods);
}

test('splitting the catalog card creates styleable cover, title, date and summary elements', () => {
    const app = editor({ layout: 'list' });
    app.splitContentCatalogItems();

    const children = app.selEl.data.children;
    assert.deepEqual(children.map(child => [child.type, child.data.loop_field]), [
        ['image', 'cover'], ['heading', 'title'], ['text', 'date'], ['text', 'summary'],
    ]);
    assert.equal(children[1].data.loop_url_field, 'url');
    assert.equal(children[3].data.loop_length, 120);
    assert.equal(children[0].data.seeded, true, 'library defaults are kept');
    assert.equal(new Set(children.map(child => child.id)).size, 4);
    assert.deepEqual(app.selected, [1, 0, 0, 1], 'the title is selected so it can be styled right away');
    assert.equal(app.flushes, 2);
});

test('parts switched off before splitting are not created', () => {
    const app = editor({ show_cover: false, show_date: '0', show_summary: true });
    app.splitContentCatalogItems();
    assert.deepEqual(app.selEl.data.children.map(child => child.data.loop_field), ['title', 'summary']);
});

test('an existing card template is never overwritten', () => {
    const template = [{ id: 'e_keep', type: 'heading', data: { loop_field: 'title' } }];
    const app = editor({ children: template });
    app.splitContentCatalogItems();
    assert.equal(app.selEl.data.children, template);
    assert.equal(app.flushes, 0);
});

test('built-in card switches hide once the card is split, but never on child elements', () => {
    const app = editor({ children: [{ id: 'e1', type: 'heading', data: {} }] });
    assert.equal(app.isLoopTemplateHost(), true);
    assert.equal(app.loopItemControlHidden({ key: 'show_summary' }), true);
    assert.equal(app.loopItemControlHidden({ key: 'show_search' }), false);
    app.selectedSubEi = 0;
    assert.equal(app.loopItemControlHidden({ key: 'show_summary' }), false);
    assert.equal(editor({}).loopItemControlHidden({ key: 'show_summary' }), false);
});
