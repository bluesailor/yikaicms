const { test } = require('node:test');
const assert = require('node:assert/strict');
const styleGroups = require('../../assets/js/blox-style-groups');

const bg = { key: 'bg_color', type: 'color', tab: 'style', group: 'background', default: '' };
const anim = { key: 'animation', type: 'select', tab: 'style', group: 'animation', default: '' };
const plain = { key: 'radius', type: 'select', tab: 'style', default: 'none' };

test('groupOf: no group and unknown group both fall back to general', () => {
    assert.equal(styleGroups.groupOf(plain), 'general');
    assert.equal(styleGroups.groupOf({ key: 'x', group: 'made-up' }), 'general');
    assert.equal(styleGroups.groupOf(bg), 'background');
});

test('groups: ordered by ORDER, only groups actually present', () => {
    assert.deepEqual(styleGroups.groups([anim, bg, plain]), ['general', 'background', 'animation']);
    assert.deepEqual(styleGroups.groups([anim]), ['animation']);
    assert.deepEqual(styleGroups.groups([]), []);
});

test('filter: partitions by active group, showAll bypasses', () => {
    const list = [bg, anim, plain];
    assert.deepEqual(styleGroups.filter(list, 'background', false), [bg]);
    assert.deepEqual(styleGroups.filter(list, 'general', false), [plain]);
    assert.deepEqual(styleGroups.filter(list, 'background', true), list);
    assert.deepEqual(styleGroups.filter(null, 'general', false), []);
});

test('hasBoxValue: only non-empty strings count (server boxStyle parity)', () => {
    assert.equal(styleGroups.hasBoxValue({ style_margin: 'md' }), true);
    assert.equal(styleGroups.hasBoxValue({ style_padding_top: '10%' }), true);
    assert.equal(styleGroups.hasBoxValue({ style_margin: '' }), false);
    // 数值/数组形态服务端会丢弃，圆点不得亮（unknown-keys 勘误的同口径锚点）
    assert.equal(styleGroups.hasBoxValue({ style_margin_bottom: 20 }), false);
    assert.equal(styleGroups.hasBoxValue({ style_padding_top: { d: 'xl' } }), false);
    assert.equal(styleGroups.hasBoxValue({}), false);
    assert.equal(styleGroups.hasBoxValue(null), false);
});

test('hasModified: per-group dot via injected predicate', () => {
    const modified = (c) => c.key === 'bg_color';
    assert.equal(styleGroups.hasModified('background', [bg, anim, plain], modified), true);
    assert.equal(styleGroups.hasModified('animation', [bg, anim, plain], modified), false);
    assert.equal(styleGroups.hasModified('general', [bg, anim, plain], modified), false);
});

test('methods.styleGroups: disabled for container block, search, modified-only, <2 groups', () => {
    const schema = { controls: [bg, anim, plain] };
    // 模拟编辑器的 methods 混入（...BloxStyleGroups.methods）
    const base = Object.assign({}, styleGroups.methods, {
        selEl: { type: 'card', data: {} },
        elSchema: () => schema,
        isSelectedContainerEl: () => false,
        ctrlQuery: '',
        modifiedOnly: false,
    });
    globalThis.BloxHomeContentPanel = { tabFor: (node, c) => c.tab || 'content' };

    assert.deepEqual(styleGroups.methods.styleGroups.call(base), ['general', 'background', 'animation']);
    assert.deepEqual(styleGroups.methods.styleGroups.call({ ...base, isSelectedContainerEl: () => true }), []);
    assert.deepEqual(styleGroups.methods.styleGroups.call({ ...base, ctrlQuery: 'pad' }), []);
    assert.deepEqual(styleGroups.methods.styleGroups.call({ ...base, modifiedOnly: true }), []);
    const single = { ...base, elSchema: () => ({ controls: [anim] }) };
    assert.deepEqual(styleGroups.methods.styleGroups.call(single), []);
});

test('methods.effectiveStyleGroup: falls to first present group when styleGroup absent', () => {
    globalThis.BloxHomeContentPanel = { tabFor: (node, c) => c.tab || 'content' };
    // card 形态：只有 背景+动画、无 常规——selectElement 重置的 "general" 不在组列表
    const ctx = Object.assign({}, styleGroups.methods, {
        selEl: { type: 'card', data: {} },
        elSchema: () => ({ controls: [bg, anim] }),
        isSelectedContainerEl: () => false,
        ctrlQuery: '',
        modifiedOnly: false,
        styleGroup: 'general',
    });
    assert.equal(styleGroups.methods.effectiveStyleGroup.call(ctx), 'background');
    ctx.styleGroup = 'animation';
    assert.equal(styleGroups.methods.effectiveStyleGroup.call(ctx), 'animation');
    // 分组未启用（单组）时回落 general
    const single = Object.assign({}, ctx, { elSchema: () => ({ controls: [anim] }), styleGroup: 'background' });
    assert.equal(styleGroups.methods.effectiveStyleGroup.call(single), 'general');
});

test('methods.styleTabDot: box value or any modified style control lights the tab', () => {
    globalThis.BloxHomeContentPanel = { tabFor: (node, c) => c.tab || 'content' };
    // 模拟编辑器的 methods 混入（...BloxStyleGroups.methods）
    const ctx = Object.assign({}, styleGroups.methods, {
        selEl: { type: 'card', data: { style_margin: 'md' } },
        elSchema: () => ({ controls: [plain] }),
        isCtrlModified: () => false,
    });
    assert.equal(styleGroups.methods.styleTabDot.call(ctx), true);
    ctx.selEl.data = {};
    assert.equal(styleGroups.methods.styleTabDot.call(ctx), false);
    ctx.isCtrlModified = () => true;
    assert.equal(styleGroups.methods.styleTabDot.call(ctx), true);
    assert.equal(styleGroups.methods.styleTabDot.call({ ...ctx, selEl: null }), false);
});
