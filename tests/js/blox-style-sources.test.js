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
