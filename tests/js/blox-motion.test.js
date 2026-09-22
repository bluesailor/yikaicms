const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.resolve(__dirname, '../../assets/js/scroll-anim.js'), 'utf8');

function runtime(mode = 'standard', reduced = false, nodes = []) {
  const handlers = {};
  const media = { matches: reduced, addEventListener(name, fn) { handlers[name] = fn; } };
  const document = { querySelector: q => q.startsWith('meta') ? { getAttribute: () => mode } : null, querySelectorAll: () => nodes, addEventListener() {} };
  const window = { matchMedia: () => media, innerWidth: 1440 };
  vm.runInNewContext(source, { window, document, WeakSet, Set, Array });
  return { api: window.YikaiMotion, media, handlers };
}
function node(attributes = {}) {
  const calls = [], classes = new Set();
  return { calls, children: [], getAttribute: k => attributes[k] || null, hasAttribute: k => Object.hasOwn(attributes, k), classList: { add: x => classes.add(x), remove: x => classes.delete(x) }, animate(frames, options) { const a = { frames, options, cancelled: false, cancel() { this.cancelled = true; } }; calls.push(a); return a; } };
}
test('none and reduced motion reveal immediately and never animate', () => {
  for (const [mode, reduced] of [['none', false], ['standard', true]]) {
    const n = node({ 'data-animate': 'fade-up' });
    const { api } = runtime(mode, reduced, [n]);
    api.replay(n, 'fade-up');
    assert.equal(n.calls.length, 0);
    assert.equal(api.level(), 'none');
  }
});
test('light ignores displacement and delay; standard composes transforms instead of replacing hover', () => {
  const n = node({ 'data-animate-delay': 'long' });
  runtime('light').api.replay(n, 'fade-up');
  assert.equal(n.calls.length, 1);
  assert.equal(n.calls[0].options.duration, 180);
  assert.equal(n.calls[0].options.delay, 0);
  const normal = node();
  runtime().api.replay(normal, 'zoom-in');
  assert.equal(normal.calls.length, 2);
  assert.equal(normal.calls[1].options.composite, 'add');
});
test('replay cancels its previous run and a preference change cancels active motion', () => {
  const n = node(), r = runtime();
  r.api.replay(n, 'fade-up');
  r.api.replay(n, 'fade-up');
  assert.equal(n.calls[0].cancelled, true);
  r.media.matches = true;
  r.handlers.change();
  assert.equal(n.calls[2].cancelled, true);
});
test('disabled device and missing animation/observer APIs keep content visible', () => {
  const n = node({ 'data-animate-device': 'mobile' });
  runtime().api.replay(n, 'fade-up');
  assert.equal(n.calls.length, 0);
  const unsupported = node();
  delete unsupported.animate;
  assert.doesNotThrow(() => runtime().api.replay(unsupported, 'fade'));
  const legacy = node({ 'data-aos': 'fade-left' });
  runtime('standard', false, [legacy]);
  assert.equal(legacy.calls.length, 0);
});

test('group replay ignores editor resize handles and staggers only actual children', () => {
  const group = node({ 'data-stagger': '' });
  const first = node(), handle = node(), second = node();
  handle.matches = () => true;
  group.children = [first, handle, second];
  runtime().api.replayGroup(group);
  assert.equal(handle.calls.length, 0);
  assert.equal(first.calls[0].options.delay, 0);
  assert.equal(second.calls[0].options.delay, 70);
});

test('group speed and device apply to loop cards, and light motion removes stagger delay', () => {
  for (const mode of ['standard', 'light']) {
    const group = node({ 'data-stagger': '', 'data-animate-speed': 'fast' });
    const cards = [node(), node()];
    group.children = cards;
    runtime(mode).api.replayGroup(group);
    assert.equal(cards[1].calls[0].options.duration, mode === 'light' ? 180 : 450);
    assert.equal(cards[1].calls[0].options.delay, mode === 'light' ? 0 : 70);
  }
  const disabled = node({ 'data-stagger': '', 'data-animate-device': 'mobile' });
  disabled.children = [node()];
  runtime().api.replayGroup(disabled);
  assert.equal(disabled.children[0].calls.length, 0);
});

test('group skips independent effects, nested groups, empty notices and pagination', () => {
  const group = node({ 'data-stagger': '' });
  const card = node(), explicit = node(), nested = node(), pager = node(), empty = node();
  explicit.matches = selector => selector.includes('[data-animate]');
  nested.querySelector = () => ({});
  pager.matches = selector => selector.includes('.yk-query-pagination-wrap');
  empty.matches = selector => selector.includes('.yk-query-empty');
  group.children = [explicit, nested, pager, empty, card];
  runtime().api.replayGroup(group);
  for (const skipped of [explicit, nested, pager, empty]) assert.equal(skipped.calls.length, 0);
  assert.equal(card.calls[0].options.delay, 0);
});
