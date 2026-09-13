const { test } = require('node:test');
const assert = require('node:assert/strict');
const conditions = require('../../assets/js/blox-detail-conditions');

const base = { content_type: 'product', lang: 'zh-CN', source: 'custom', priority: 4 };

test('rowsFromScope keeps rule order and per-rule children flag', () => {
    const rows = conditions.rowsFromScope({
        include: [
            { kind: 'category', ids: ['5'], include_children: true },
            { kind: 'item', ids: [7, 9] },
            { kind: 'all' },
        ],
        exclude: [{ kind: 'category', ids: [12], include_children: false }],
    });
    assert.deepEqual(rows.include.map((r) => r.kind), ['category', 'item', 'all']);
    assert.deepEqual(rows.include[0].ids, ['5'], '目标 id 统一为字符串，与多选框取值一致');
    assert.equal(rows.include[0].include_children, true);
    assert.deepEqual(rows.include[1].ids, ['7', '9']);
    assert.equal(rows.exclude[0].include_children, false);
    // 缺字段/脏数据不炸
    assert.deepEqual(conditions.rowsFromScope(null), { include: [], exclude: [] });
    assert.deepEqual(conditions.rowsFromScope({ include: 'nope' }).include, []);
});

test('scopeFromRows preserves untouched fields and normalizes shapes', () => {
    const scope = conditions.scopeFromRows({
        include: [
            { kind: 'category', ids: [5, 5, 0], include_children: true },
            { kind: 'item', ids: [7], include_children: true },
            { kind: 'all' },
        ],
        exclude: [{ kind: 'category', ids: [12] }],
    }, base);

    assert.equal(scope.version, 2);
    assert.equal(scope.content_type, 'product');
    assert.equal(scope.lang, 'zh-CN');
    assert.equal(scope.source, 'custom');
    assert.equal(scope.priority, 4, '不编辑的字段原样保留');
    assert.deepEqual(scope.include, [
        { kind: 'category', ids: [5], include_children: true },
        { kind: 'item', ids: [7], include_children: false },   // item 的子级归 false
        { kind: 'all' },
    ]);
    assert.deepEqual(scope.exclude, [{ kind: 'category', ids: [12], include_children: false }]);
});

test('empty include means not applied and stays distinct from native', () => {
    const scope = conditions.scopeFromRows({ include: [], exclude: [] }, { ...base, source: 'native' });
    assert.deepEqual(scope.include, [], '空 include 是"不应用"的合法表达');
    assert.equal(scope.source, 'native', 'native 是 source 维度，不能被写成空 include');
});

test('rows without a target are reported before submit and dropped from payload', () => {
    const rows = { include: [{ kind: 'item', ids: [] }], exclude: [{ kind: 'category', ids: [] }] };
    const found = conditions.problems(rows);
    assert.deepEqual(found.map((p) => p.code), ['missing_target', 'missing_target']);
    assert.deepEqual(conditions.problems({ include: [{ kind: 'all', ids: [] }], exclude: [] }), []);
    assert.deepEqual(conditions.scopeFromRows(rows, base).include, [], '空目标行不落库');
});

test('changed compares include/exclude only (priority/source handled elsewhere)', () => {
    const before = { include: [{ kind: 'all' }], exclude: [], priority: 0, source: 'custom' };
    assert.equal(conditions.changed(before, { include: [{ kind: 'all' }], exclude: [], priority: 9, source: 'native' }), false);
    assert.equal(conditions.changed(before, { include: [], exclude: [] }), true);
    assert.equal(conditions.changed(before, { include: [{ kind: 'all' }, { kind: 'item', ids: [1] }], exclude: [] }), true);
    assert.equal(conditions.changed(before, { include: [{ kind: 'all' }], exclude: [{ kind: 'category', ids: [2] }] }), true);
});

test('saved scope stays clean after the panel rebuilds its rows', () => {
    // 回归：服务端归一后的作用域（all 规则省略 ids/子级）与面板行模型形状不同，
    // 保存成功后不能因此仍判为"已修改"。
    const saved = {
        version: 2, content_type: 'article', lang: 'zh-CN', source: 'custom', priority: 0,
        include: [{ kind: 'all' }, { kind: 'item', ids: [20], include_children: false }],
        exclude: [{ kind: 'category', ids: [89], include_children: true }],
    };
    const rows = conditions.rowsFromScope(saved);
    assert.equal(conditions.changed(saved, rows), false, '保存后基线＝新作用域，不应再报已修改');
    rows.include[1].ids.push(21);
    assert.equal(conditions.changed(saved, rows), true, '真正改动仍要报出');
    assert.equal(conditions.changed({ include: [{ kind: 'all', ids: [], include_children: false }] }, { include: [{ kind: 'all' }] }), false, 'all 规则的形状差异不算变更');
});

test('legacy product scope adapts v1 without writing v2', () => {
    // mode=all → 一条 all 规则；v1 的 source/lang 保留
    const all = conditions.legacyProductScope({ mode: 'all', ids: [6, 1], lang: 'zh-CN', source: 'native' });
    assert.deepEqual(all.include, [{ kind: 'all' }]);
    assert.equal(all.lang, 'zh-CN');
    assert.equal(all.source, 'native');
    assert.equal(all.priority, 0);
    assert.equal(all.exclude.length, 0);
    assert.ok(!('version' in all), '适配视图不带 version，免得被当成已声明的 v2 契约');

    // mode=selected → 一条 item 规则，id 与多选框取值同型（字符串）
    assert.deepEqual(conditions.legacyProductScope({ mode: 'selected', ids: [6, 1] }).include, [{ kind: 'item', ids: ['6', '1'] }]);

    // v1 的 selected + 空 ids = 未应用（空 include），不是 all
    assert.deepEqual(conditions.legacyProductScope({ mode: 'selected', ids: [] }).include, []);
    assert.deepEqual(conditions.legacyProductScope({}).include, []);

    // 脏 id 被过滤，缺省 source 归 custom
    const dirty = conditions.legacyProductScope({ mode: 'selected', ids: ['0', '-2', 'x', '3', '3'] });
    assert.deepEqual(dirty.include, [{ kind: 'item', ids: ['3'] }]);
    assert.equal(dirty.source, 'custom');

    // 适配出的行模型应与该视图"未修改"（打开面板不能一进来就是脏的）
    ['all', 'selected', 'none'].forEach((mode) => {
        const view = conditions.legacyProductScope({ mode: mode, ids: [6, 1] });
        assert.equal(conditions.changed(view, conditions.rowsFromScope(view)), false, mode);
    });
});
