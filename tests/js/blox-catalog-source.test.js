const { test } = require('node:test');
const assert = require('node:assert/strict');
const { create } = require('../../assets/js/blox-catalog-source');

const response = (items, page = 1, more = false) => ({ ok: true,
    json: async () => ({ code: 0, data: { items, page, has_more: more } }) });

test('catalog lookup sends only read parameters, paginates, and builds local numeric edit URLs', async t => {
    const original = global.fetch;
    t.after(() => { global.fetch = original; });
    const app = create(18, 'csrf-test', 'article');
    app.keyword = 'Needle';
    global.fetch = async (url, options) => {
        assert.equal(url, '/admin/blox_page_api.php');
        assert.deepEqual(Object.fromEntries(options.body), { action: 'catalog_items', id: '18', _token: 'csrf-test', keyword: 'Needle', page: '2' });
        return response([{ id: 9, title: '<img onerror=alert(1)>' }, { id: '../evil' }, { id: -1 }], 2, true);
    };
    await app.load(2);
    assert.equal(app.items.length, 1);
    assert.equal(app.editUrl(app.items[0]), '/admin/article_edit.php?id=9');
    assert.equal(app.page, 2);
    assert.equal(app.hasMore, true);
    assert.equal(app.loading, false);
    assert.equal(create(18, '', '../evil').editUrl({ id: 1 }), '');
});

test('stale responses and responses after panel destruction cannot replace current results', async t => {
    const original = global.fetch;
    t.after(() => { global.fetch = original; });
    const pending = [];
    global.fetch = () => new Promise(resolve => pending.push(resolve));
    const app = create(2, '', 'product');
    const first = app.load(1), second = app.load(2);
    pending[1](response([{ id: 2 }], 2)); await second;
    pending[0](response([{ id: 1 }])); await first;
    assert.equal(app.items[0].id, 2);
    const third = app.load(3);
    app.destroy(); pending[2](response([{ id: 3 }], 3)); await third;
    assert.deepEqual(app.items, []);
});

test('HTTP, protocol and network failures show retry state; retry can recover to an empty result', async t => {
    const original = global.fetch;
    t.after(() => { global.fetch = original; });
    const app = create(2, '', 'product');
    for (const bad of [{ ok: false }, { ok: true, json: async () => ({}) },
        { ok: true, json: async () => ({ code: null, data: { items: [] } }) }, null]) {
        global.fetch = async () => { if (!bad) throw new Error('offline'); return bad; };
        await app.load(1);
        assert.equal(app.failed, true);
        assert.equal(app.loading, false);
        assert.deepEqual(app.items, []);
    }
    global.fetch = async () => response([]);
    await app.load(1);
    assert.equal(app.failed, false);
    assert.deepEqual(app.items, []);
});
