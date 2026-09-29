const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// vm 沙箱里建的对象原型属于另一个 realm，严格深比较前先转成普通值
const plain = value => (value === undefined ? value : JSON.parse(JSON.stringify(value)));

/** 2.0.3 查询循环面板：来源联动、分类多选、ID、默认值不落盘、随机/嵌套不分页。 */
function editorWith(query) {
    const window = { BloxProEditorData: {
        loopText: { orders: { default: 'Default', random: 'Random', manual: 'Manual', price_asc: 'Price ↑' }, termOrders: { default: 'Default', count: 'Count' } },
        loopTerms: { content: [{ value: 1, label: 'News' }, { value: 2, label: '— Industry' }], product: [{ value: 7, label: 'Sensors' }] },
    } };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../plugins/yikai-builder/assets/blox-pro-editor.js'), 'utf8'),
        { window, URLSearchParams });
    return Object.assign({}, window.BloxProEditor.methods, { selEl: { type: 'div', data: { _query: query } } });
}

test('source kind drives taxonomy candidates and order options', () => {
    const editor = editorWith({ source: 'type:article', limit: 6 });
    assert.equal(editor.loopQueryKind(), 'content');
    assert.deepEqual(plain(editor.loopTermOptions().map(term => term.value)), [1, 2]);
    assert.ok(editor.loopQueryOrderOptions().some(option => option.value === 'recommend_first'));
    assert.ok(!editor.loopQueryOrderOptions().some(option => option.value === 'price_asc'));

    editor.setLoopQueryField('source', 'type:product');
    assert.equal(editor.loopQueryKind(), 'product');
    assert.deepEqual(plain(editor.loopTermOptions().map(term => term.value)), [7]);
    assert.ok(editor.loopQueryOrderOptions().some(option => option.value === 'price_asc'));
    assert.equal(editor.loopQueryChildren(), true, 'products include subcategories by default');

    editor.setLoopQueryField('source', 'terms:content');
    assert.equal(editor.loopQueryKind(), 'term');
    assert.deepEqual(plain(editor.loopQueryOrderOptions().map(option => option.value)), ['default', 'name', 'name_desc', 'newest', 'count']);
});

test('switching source clears stale category, order and item-only settings', () => {
    const editor = editorWith({ source: 'type:article', limit: 6, cats: ['1'], order: 'views', keyword: 'x', ids: [3], scope: 'related' });
    editor.setLoopQueryField('source', 'terms:content');
    assert.deepEqual(plain(editor.selEl.data._query), { source: 'terms:content', limit: 6 });
});

test('category multi-select merges a numeric legacy cat and keeps slug ones', () => {
    const editor = editorWith({ source: 'type:article', limit: 6, cat: '1' });
    editor.toggleLoopQueryListItem('cats', '2', true);
    assert.deepEqual(plain(editor.selEl.data._query.cats), ['1', '2']);
    assert.equal(editor.selEl.data._query.cat, undefined);
    editor.toggleLoopQueryListItem('cats', '1', false);
    editor.toggleLoopQueryListItem('cats', '2', false);
    assert.equal(editor.selEl.data._query.cats, undefined, 'empty lists are not stored');

    const slug = editorWith({ source: 'type:article', limit: 6, cat: 'news' });
    slug.toggleLoopQueryListItem('cats', '2', true);
    assert.equal(slug.selEl.data._query.cat, 'news');
});

test('ids, defaults and pagination constraints', () => {
    const editor = editorWith({ source: 'type:article', limit: 6, pagination: 'load_more', load_more_text: 'More' });
    editor.setLoopQueryField('ids', '9, 3，x 9 0');
    assert.deepEqual(plain(editor.selEl.data._query.ids), [9, 3]);
    assert.equal(editor.loopQueryIds('ids'), '9, 3');
    assert.ok(editor.loopQueryOrderOptions().some(option => option.value === 'manual'));
    editor.setLoopQueryField('order', 'manual');
    editor.setLoopQueryField('ids', '');
    assert.equal(editor.selEl.data._query.order, undefined, 'manual order needs ids');

    editor.setLoopQueryField('children', false);
    assert.equal(editor.selEl.data._query.children, undefined, 'matching the source default is not stored');
    editor.setLoopQueryField('children', true);
    assert.equal(editor.selEl.data._query.children, true);

    editor.setLoopQueryField('date_within', '30');
    assert.equal(editor.selEl.data._query.date_within, 30);
    editor.setLoopQueryField('date_within', '');
    assert.equal(editor.selEl.data._query.date_within, undefined);
    editor.setLoopQueryField('parent_term', '0');
    assert.equal(editor.selEl.data._query.parent_term, 0);
    assert.equal(editor.loopQueryField('parent_term'), '0');

    editor.setLoopQueryField('order', 'random');
    assert.equal(editor.selEl.data._query.pagination, undefined, 'random order cannot paginate');
    editor.setLoopQueryField('pagination', 'numbers');
    assert.equal(editor.selEl.data._query.load_more_text, undefined);
    editor.setLoopQueryField('scope', 'parent');
    assert.equal(editor.selEl.data._query.pagination, undefined, 'nested loops cannot paginate');
    editor.setLoopQueryField('filter_relation', 'and');
    assert.equal(editor.selEl.data._query.filter_relation, undefined);
});

test('global query: edit in place, save back to the shared body, or cancel to the reference', async () => {
    const calls = [];
    const window = { BloxProEditorData: { loopText: {} } };
    const fetch = async (url, options) => {
        calls.push(Object.fromEntries(options.body));
        const query = JSON.parse(options.body.get('query') || '{}');
        return { json: async () => ({ code: 0, data: { query: { query_id: 'gq_aaaaaaaaaaaa', name: 'Latest', query, modified: 9 } } }) };
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../plugins/yikai-builder/assets/blox-pro-editor.js'), 'utf8'),
        { window, URLSearchParams, fetch });
    const element = { type: 'div', data: { _query: { ref: 'gq_aaaaaaaaaaaa' } } };
    const editor = Object.assign({}, window.BloxProEditor.methods, {
        csrf: 't', selEl: element, toast() {},
        globalQueries: [{ query_id: 'gq_aaaaaaaaaaaa', name: 'Latest', query: { source: 'type:article', limit: 6 }, modified: 5 }],
    });

    editor.editLoopGlobalQuery();
    assert.ok(editor.loopGlobalEditingActive());
    assert.deepEqual(plain(element.data._query), { source: 'type:article', limit: 6 });
    editor.setLoopQueryField('limit', '9');
    editor.saveLoopGlobalQuery();
    await new Promise(resolve => setTimeout(resolve, 0));
    assert.equal(calls[0].action, 'query_update');
    assert.equal(calls[0].modified, '5', 'optimistic concurrency uses the version that was loaded');
    assert.deepEqual(JSON.parse(calls[0].query), { source: 'type:article', limit: 9 });
    assert.deepEqual(plain(element.data._query), { ref: 'gq_aaaaaaaaaaaa' });
    assert.equal(editor.globalQueries[0].modified, 9);

    editor.editLoopGlobalQuery();
    editor.setLoopQueryField('limit', '3');
    editor.cancelLoopGlobalQuery();
    assert.deepEqual(plain(element.data._query), { ref: 'gq_aaaaaaaaaaaa' });
    assert.equal(calls.length, 1);
});
