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
