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

test('methods.styleGroups: includes common settings, bypasses container and filters', () => {
    const schema = { controls: [bg, anim, plain] };
    // 模拟编辑器的 methods 混入（...BloxStyleGroups.methods）
    const base = Object.assign({}, styleGroups.methods, {
        selEl: { type: 'card', data: {} },
        elSchema: () => schema,
        isSelectedContainerEl: () => false,
        ctrlQuery: '',
        modifiedOnly: false,
        isCtrlModified: () => false,
        styleGroupLabels: { general: '常规', background: '背景', animation: '动画' },
    });
    globalThis.BloxHomeContentPanel = { tabFor: (node, c) => c.tab || 'content' };
    // TASK-003 R01：分组候选集由宿主 styleCandidates() 提供（与最终渲染同源）
    base.styleCandidates = function () {
        return styleGroups.visibleCandidates([{ group: 'general' }].concat(schema.controls), {
            query: this.ctrlQuery, modifiedOnly: this.modifiedOnly,
            isModified: this.isCtrlModified, groupLabels: this.styleGroupLabels,
        });
    };

    assert.deepEqual(styleGroups.methods.styleGroups.call(base), ['general', 'background', 'animation']);
    assert.deepEqual(styleGroups.methods.styleGroups.call({ ...base, isSelectedContainerEl: () => true }), []);
    assert.deepEqual(styleGroups.methods.styleGroups.call({ ...base, ctrlQuery: 'pad' }), [], '无命中时不启用分组');
    assert.deepEqual(styleGroups.methods.styleGroups.call({ ...base, modifiedOnly: true }), [], '只看已修改且无修改时不启用分组');
    const single = { ...base, elSchema: () => ({ controls: [anim] }), styleCandidates: () => [{ group: 'general' }, anim] };
    assert.deepEqual(styleGroups.methods.styleGroups.call(single), ['general', 'animation']);
});

// TASK-003 D：搜索时不再清空分组——只列出"有命中的组"，命中分组仍可逐组查看
test('methods.styleGroups: keeps only groups that have search hits', () => {
    // 带可检索文案的夹具：bg/anim 共享「卡片」，plain 只有「圆角」
    const bgCard = { key: 'bg_color', tab: 'style', group: 'background', label: '卡片阴影' };
    const animCard = { key: 'animation', tab: 'style', group: 'animation', label: '卡片动画' };
    const plainCard = { key: 'radius', tab: 'style', label: '圆角' };
    const base = Object.assign({}, styleGroups.methods, {
        selEl: { type: 'card', data: {} },
        elSchema: () => ({ controls: [bgCard, animCard, plainCard] }),
        isSelectedContainerEl: () => false,
        ctrlQuery: '',
        modifiedOnly: false,
        isCtrlModified: () => false,
        styleGroupLabels: { general: '常规', background: '背景', animation: '动画' },
    });
    globalThis.BloxHomeContentPanel = { tabFor: (node, c) => c.tab || 'content' };
    base.styleCandidates = function () {
        return styleGroups.visibleCandidates([bgCard, animCard, plainCard], {
            query: this.ctrlQuery, modifiedOnly: this.modifiedOnly,
            isModified: this.isCtrlModified, groupLabels: this.styleGroupLabels,
        });
    };

    // 命中两组 → 只列这两组（此前一律返回 [] 而整体平铺，分组信息全丢）
    assert.deepEqual(styleGroups.methods.styleGroups.call({ ...base, ctrlQuery: '卡片' }), ['background', 'animation']);
    // 只命中一组 → 不启用分组（平铺等价，避免单组 chip 噪音）
    assert.deepEqual(styleGroups.methods.styleGroups.call({ ...base, ctrlQuery: '圆角' }), []);
    // 只看已修改且两组各有修改 → 列出这两组
    assert.deepEqual(
        styleGroups.methods.styleGroups.call({
            ...base,
            modifiedOnly: true,
            isCtrlModified: (c) => c === bgCard || c === animCard,
        }),
        ['background', 'animation']
    );
});

test('matchesQuery / searchFilter: section and group names are searchable', () => {
    const sectioned = { group: 'background', label: 'Background image', section: '卡片外观' };
    const plain = { group: 'general', label: 'Padding', key: 'style_padding' };
    const labels = { general: '常规', background: '背景', animation: '动画' };

    assert.equal(styleGroups.matchesQuery(sectioned, '', labels), true, '空关键词一律命中');
    assert.equal(styleGroups.matchesQuery(sectioned, 'background', labels), true, '控件名命中');
    assert.equal(styleGroups.matchesQuery(sectioned, '卡片', labels), true, '所在区块名命中');
    assert.equal(styleGroups.matchesQuery(sectioned, '背景', labels.background), true, '所属分组名命中');
    assert.equal(styleGroups.matchesQuery(plain, '背景', labels.general), false, '不命中就是不命中');

    assert.deepEqual(styleGroups.searchFilter([sectioned, plain], '卡片', false, null, labels), [sectioned]);
    assert.deepEqual(styleGroups.searchFilter([sectioned, plain], '', false, null, labels), [sectioned, plain]);
    assert.deepEqual(
        styleGroups.searchFilter([sectioned, plain], '', true, (c) => c === plain, labels),
        [plain],
        '只看已修改只保留谓词为真的控件'
    );
});

test('methods.effectiveStyleGroup: falls to first present group when styleGroup absent', () => {
    globalThis.BloxHomeContentPanel = { tabFor: (node, c) => c.tab || 'content' };
    // Common settings remain available even when the schema only has background and animation.
    const ctx = Object.assign({}, styleGroups.methods, {
        selEl: { type: 'card', data: {} },
        elSchema: () => ({ controls: [bg, anim] }),
        isSelectedContainerEl: () => false,
        ctrlQuery: '',
        modifiedOnly: false,
        styleGroup: 'general',
        // TASK-003 R01：候选集来自宿主（此处模拟"常规设置始终可用"的合成项）
        styleCandidates: () => [{ group: 'general' }, bg, anim],
    });
    assert.equal(styleGroups.methods.effectiveStyleGroup.call(ctx), 'general');
    assert.equal(ctx.commonStyleVisible(), true);
    ctx.styleGroup = 'animation';
    assert.equal(styleGroups.methods.effectiveStyleGroup.call(ctx), 'animation');
    assert.equal(ctx.commonStyleVisible(), false);
    ctx.styleGroup = 'background';
    assert.equal(ctx.commonStyleVisible(), false);
    // 旧契约"搜索即禁用分组、常规块始终可见"已被 TASK-003 D 取代：搜索现在保留分组，
    // 常规块是否可见只取决于当前分组是否落在常规（此处显式记录契约变更）
    const searching = { ...ctx, ctrlQuery: 'padding' };
    assert.deepEqual(styleGroups.methods.styleGroups.call(searching), ['general', 'background', 'animation']);
    searching.styleGroup = 'general';
    assert.equal(searching.commonStyleVisible(), true);
    // An unavailable selected group falls back to general.
    const single = Object.assign({}, ctx, {
        elSchema: () => ({ controls: [anim] }),
        styleCandidates: () => [{ group: 'general' }, anim],
        styleGroup: 'background',
    });
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
    ctx.selEl.data = { _hide_on: ['m'] };
    assert.equal(ctx.styleGroupDot('general'), true);
    assert.equal(ctx.styleTabDot(), true);
    ctx.selEl.data = { _global_style: 'test' };
    assert.equal(ctx.styleGroupDot('general'), true);
    ctx.selEl.data = {};
    ctx.isCtrlModified = () => true;
    assert.equal(styleGroups.methods.styleTabDot.call(ctx), true);
    assert.equal(styleGroups.methods.styleTabDot.call({ ...ctx, selEl: null }), false);
});

// TASK-003 R01 最小复现：隐藏控件不得参与分组计算（否则会出现"有可见命中却默认落进空分组"）
test('styleGroups never counts hidden controls into groups', () => {
    const labels = { general: '常规', background: '背景', animation: '动画' };
    const hiddenGeneral = { key: 'bg_color', tab: 'style', group: 'general', label: '颜色', editor_hidden: true };
    const visibleBackground = { key: 'other_color', tab: 'style', group: 'background', label: '颜色' };
    const visibleAnimation = { key: 'animation', tab: 'style', group: 'animation', label: '颜色' };
    const all = [hiddenGeneral, visibleBackground, visibleAnimation];
    const isExcluded = (c) => c.editor_hidden === true;
    const candidates = (query) => styleGroups.visibleCandidates(all, { isExcluded, query, groupLabels: labels });

    const base = Object.assign({}, styleGroups.methods, {
        selEl: { type: 'card', data: {} },
        isSelectedContainerEl: () => false,
        ctrlQuery: '颜色',
        modifiedOnly: false,
        styleGroup: 'general',
        styleCandidates: () => candidates('颜色'),
    });

    // 隐藏的是 general 的唯一控件 → 它不能把 general 变成一个"幽灵分组"
    assert.deepEqual(styleGroups.methods.styleGroups.call(base), ['background', 'animation']);
    // 当前分组停在 general（已无可见控件）→ 自动落到有命中的分组，不会渲染空分组
    assert.equal(styleGroups.methods.effectiveStyleGroup.call(base), 'background');
    assert.deepEqual(
        styleGroups.filter(base.styleCandidates(), styleGroups.methods.effectiveStyleGroup.call(base), false),
        [visibleBackground]
    );

    // 只剩一组可见命中时不启用分组——平铺即可看到，不会"有结果却显示空分组"
    const single = { ...base, styleCandidates: () => candidates('颜色').filter((c) => c !== visibleAnimation) };
    assert.deepEqual(styleGroups.methods.styleGroups.call(single), []);
});
