const { test } = require('node:test');
const assert = require('node:assert/strict');
const sources = require('../../assets/js/blox-style-sources');
const ctrl = { key: 'color', default: '' };

test('style sources distinguish element defaults, stored overrides and unresolved CSS', () => {
  assert.equal(sources.describe({}, ctrl, {}).local, 'css');
  assert.equal(sources.describe({ color: '#123456' }, ctrl, {}).local, 'element');
  assert.equal(sources.describe({ radius: 'none' }, { key: 'radius', default: 'none' }, {}).local, 'default');
});
test('zero is a value and malformed stored data is not rendered as object Object', () => {
  assert.equal(sources.describe({ color: 0 }, ctrl, {}).localValue, '0');
  const result = sources.describe({ color: { m: 'red' } }, ctrl, {});
  assert.equal(result.local, 'unknown');
  assert.equal(result.localValue, '');
});
test('live and archived definitions remain references instead of local overrides', () => {
  for (const status of ['active', 'archived']) {
    const result = sources.describe({ _global_style: 's_card', color: '#abcdef' }, ctrl,
      { styles: [{ id: 's_card', name: 'Card', color: 'var(--yk-color-primary)', status }] });
    assert.equal(result.local, 'element');
    assert.equal(result.shared, status === 'active' ? 'live' : 'archived');
    assert.equal(result.sharedValue, 'var(--yk-color-primary)');
  }
});
test('missing catalog is unknown, not a missing definition or fallback', () => {
  const data = { _global_style: 's_missing', _global_style_snapshot: { color: '#123456' } };
  assert.equal(sources.describe(data, ctrl, {}).shared, 'unknown');
  assert.equal(sources.describe(data, ctrl, { styles: [] }).shared, 'snapshot');
  assert.equal(sources.describe({ _global_style: 's_missing' }, ctrl, { styles: [] }).shared, 'missing');
});
test('unbound snapshots do not become shared styles and inputs are never mutated', () => {
  const data = { color: '#123456', _global_style_snapshot: { color: '#ffffff' } };
  const before = JSON.stringify(data);
  assert.equal(sources.describe(data, ctrl, { styles: [] }).shared, 'unbound');
  assert.equal(JSON.stringify(data), before);
});
test('sources stay scoped to supported root properties', () => {
  assert.equal(sources.supports('heading', ctrl), true);
  assert.equal(sources.supports('icon', ctrl), false);
  assert.equal(sources.supports('container', { key: 'radius' }), true);
  assert.equal(sources.supports('card', { key: 'bg_color' }), true);
  assert.equal(sources.supports('heading', { key: 'text' }), false);
});

// TASK-004：新增两组**已核对渲染契约**的组合（text 的 color / radius 与共享声明同槽），
// 同时锁定"未核对到同槽契约的类型保持不支持"——不因控件名叫 color/radius 就放开。
test('text color and radius join the supported root properties', () => {
  assert.equal(sources.supports('text', { key: 'color' }), true, 'text 根标签与共享声明同槽');
  assert.equal(sources.supports('text', { key: 'radius' }), true, 'text 根标签的圆角类与共享 border-radius 同槽');
  // 扩展开关只影响这两组：其它组合维持原判
  assert.equal(sources.supports('icon', { key: 'color' }), false);
  assert.equal(sources.supports('button', { key: 'color' }), false);
  assert.equal(sources.supports('divider', { key: 'color' }), false);
  assert.equal(sources.supports('heading', { key: 'radius' }), false);
  assert.equal(sources.supports('text', { key: 'text' }), false);
  assert.equal(sources.supports('card', { key: 'radius' }), false, '卡片圆角不在元素根槽位，未核对前不放行');
});

// 新增组合的 describe() 走既有映射（无需新增文案键）：本地值、共享值、失效引用分别表达
test('text color/radius keep local, shared and invalid references distinguishable', () => {
  const radiusControl = { key: 'radius', default: 'none' };
  const catalog = { styles: [{ id: 's_card', name: 'Card', color: '#123456', radius: 'xl', status: 'active' }] };

  const localOnly = sources.describe({ color: '#abcdef' }, { key: 'color', default: '' }, catalog);
  assert.equal(localOnly.local, 'element');
  assert.equal(localOnly.shared, 'unbound', '未绑定共享样式时不编造共享来源');

  const sharedOnly = sources.describe({ _global_style: 's_card' }, { key: 'color', default: '' }, catalog);
  assert.equal(sharedOnly.shared, 'live');
  assert.equal(sharedOnly.sharedValue, '#123456');

  // 共享与本地可同时存在，不切成互斥来源
  const both = sources.describe({ _global_style: 's_card', radius: 'md' }, radiusControl, catalog);
  assert.equal(both.local, 'element');
  assert.equal(both.shared, 'live');
  assert.equal(both.sharedValue, 'xl');

  // 失效引用：目录里没有该 id 时按快照/缺失区分
  const snapshot = sources.describe({ _global_style: 's_gone', _global_style_snapshot: { color: '#fff' } }, { key: 'color', default: '' }, { styles: [] });
  assert.equal(snapshot.shared, 'snapshot');
  const missing = sources.describe({ _global_style: 's_gone' }, { key: 'color', default: '' }, { styles: [] });
  assert.equal(missing.shared, 'missing');
});

test('全局类作为第三条来源轴（E06）', () => {
  const catalog = {
    styles: [],
    classes: {
      gc_aaaaaaaaaaaa: { name: 'card-hero', settings: { text_color: '#111827', radius: 'lg' } },
      gc_bbbbbbbbbbbb: { name: 'spacing-only', settings: { padding_px: 24 } },
    },
  };
  const colorControl = { key: 'color', default: '' };

  // 挂了类且该类设了这个属性 → 报出来，带类名与取值
  const hit = sources.describe({ _classes: ['gc_aaaaaaaaaaaa'] }, colorControl, catalog);
  assert.equal(hit.classes.length, 1);
  assert.equal(hit.classes[0].name, 'card-hero');
  assert.equal(hit.classes[0].value, '#111827');

  // 挂了类但该类没设这个属性 → 不提，免得把无关的类说成来源
  const unrelated = sources.describe({ _classes: ['gc_bbbbbbbbbbbb'] }, colorControl, catalog);
  assert.deepEqual(unrelated.classes, []);

  // 没挂类
  assert.deepEqual(sources.describe({}, colorControl, catalog).classes, []);

  // 类已被删：报缺失而不是假装没有来源
  const gone = sources.describe({ _classes: ['gc_cccccccccccc'] }, colorControl, catalog);
  assert.equal(gone.classes.length, 1);
  assert.equal(gone.classes[0].status, 'missing');

  // 目录没下发时标为未知——不能冒充"没有类来源"
  const noCatalog = sources.describe({ _classes: ['gc_aaaaaaaaaaaa'] }, colorControl, { styles: [] });
  assert.equal(noCatalog.classes[0].status, 'unknown');

  // 三条轴互不干扰
  const all = sources.describe(
    { _classes: ['gc_aaaaaaaaaaaa'], _global_style: 's_card', color: '#ff0000' },
    colorControl,
    { styles: [{ id: 's_card', name: 'Card', status: 'live', color: '#0000ff' }], classes: catalog.classes }
  );
  assert.equal(all.local, 'element');
  assert.equal(all.shared, 'live');
  assert.equal(all.classes.length, 1);
});

test('class conflicts: element exact values, then presets, then later-named classes block a class property', () => {
  const catalog = {
    styles: [{ id: 's_card', name: 'Card', status: 'live', color: '#0000ff', background: '', border_color: '', radius: 'none' }],
    classes: {
      gc_aaaaaaaaaaaa: { name: 'hero-title', settings: { text_color: '#c2410c', font_size_px: { d: 36, m: 28 }, margin_top_px: 24, padding_top_px: 8, radius_px: 6 } },
      gc_bbbbbbbbbbbb: { name: 'zz-accent', settings: { font_size_px: 40 } },
      gc_cccccccccccc: { name: 'aa-base', settings: { margin_top_px: 0 } },
    },
  };
  const heading = (data) => ({ type: 'heading', data: Object.assign({ _classes: ['gc_aaaaaaaaaaaa'] }, data) });
  const keys = (list) => list.map((item) => item.key + ':' + item.by).sort();

  // 只挂类、没有本地值 → 无冲突（验收用例 1 的第二个标题）
  assert.deepEqual(sources.classConflicts(heading({}), 'gc_aaaaaaaaaaaa', catalog), []);

  // 元素本地精确值挡住类：颜色（heading 内联）、字号（声明式 CSS）、上外边距（内联 !important）
  const local = sources.classConflicts(heading({ color: '#111111', type_font_size: { d: '20px' }, style_margin_top: '10px' }), 'gc_aaaaaaaaaaaa', catalog);
  assert.deepEqual(keys(local), ['font_size_px:element', 'margin_top_px:element', 'text_color:element']);
  assert.deepEqual(local.find((item) => item.key === 'margin_top_px').localKeys, ['style_margin_top']);
  // 四边统一的本地 margin 同样挡住分边类属性
  assert.deepEqual(sources.classConflicts(heading({ style_margin: 'lg' }), 'gc_aaaaaaaaaaaa', catalog)[0].localKeys, ['style_margin']);

  // 样式预设（内联 !important）挡住颜色；预设圆角为 none 时不算
  const preset = sources.classConflicts(heading({ _global_style: 's_card' }), 'gc_aaaaaaaaaaaa', catalog);
  assert.deepEqual(keys(preset), ['text_color:preset']);
  assert.equal(preset[0].name, 'Card');

  // 多类：类名更靠后的类胜出（与挂载顺序无关）；更靠前的不挡
  const multi = sources.classConflicts(heading({ _classes: ['gc_bbbbbbbbbbbb', 'gc_cccccccccccc', 'gc_aaaaaaaaaaaa'] }), 'gc_aaaaaaaaaaaa', catalog);
  assert.deepEqual(keys(multi), ['font_size_px:class']);
  assert.equal(multi[0].name, 'zz-accent');

  // 颜色本地键只在与类同槽的元素上报（button 的 color 属于内层链接，不报）
  assert.deepEqual(sources.classConflicts({ type: 'button', data: { _classes: ['gc_aaaaaaaaaaaa'], color: 'red' } }, 'gc_aaaaaaaaaaaa', catalog), []);

  // 类不在目录里 → 不猜
  assert.deepEqual(sources.classConflicts(heading({}), 'gc_dddddddddddd', catalog), []);
});

test('class conflicts cover hover/focus states and never report the states map itself', () => {
  const catalog = {
    styles: [{ id: 's_card', name: 'Card', status: 'live', color: '', background: '#ffffff', border_color: '', radius: 'none' }],
    classes: {
      gc_aaaaaaaaaaaa: { name: 'cta', settings: {
        text_color: '#111111',
        states: { hover: { text_color: '#c2410c', bg_color: '#fff7ed' }, focus: { border_color: '#c2410c' } },
      } },
      gc_bbbbbbbbbbbb: { name: 'zz-hover', settings: { states: { hover: { bg_color: '#000000' } } } },
      gc_cccccccccccc: { name: 'aa-base', settings: { states: { focus: { border_color: '#000000' } } } },
    },
  };
  const el = (data) => ({ type: 'heading', data: Object.assign({ _classes: ['gc_aaaaaaaaaaaa'] }, data) });
  const keys = (list) => list.map((item) => (item.state ? item.state + '.' : '') + item.key + ':' + item.by).sort();

  assert.deepEqual(sources.classConflicts(el({}), 'gc_aaaaaaaaaaaa', catalog), []);
  // 内联本地颜色同时挡住基础与悬停状态的文字颜色
  assert.deepEqual(keys(sources.classConflicts(el({ color: '#222222' }), 'gc_aaaaaaaaaaaa', catalog)),
    ['hover.text_color:element', 'text_color:element']);
  // 样式预设的背景（内联 !important）挡住悬停背景
  assert.deepEqual(keys(sources.classConflicts(el({ _global_style: 's_card' }), 'gc_aaaaaaaaaaaa', catalog)),
    ['hover.bg_color:preset']);
  // 多类只比同一状态：zz-hover 的悬停背景胜出；aa-base 名字更靠前，不挡聚焦边框
  const multi = sources.classConflicts(el({ _classes: ['gc_aaaaaaaaaaaa', 'gc_bbbbbbbbbbbb', 'gc_cccccccccccc'] }), 'gc_aaaaaaaaaaaa', catalog);
  assert.deepEqual(keys(multi), ['hover.bg_color:class']);
  assert.equal(multi[0].name, 'zz-hover');
});
