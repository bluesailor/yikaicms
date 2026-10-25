const { test } = require('node:test');
const assert = require('node:assert/strict');
const { bloxEditorSource } = require('./helpers/editor-source');

const source = bloxEditorSource();
const start = source.indexOf('            rawState(value) {');
const end = source.indexOf('            draftSummary() {', start);
assert.ok(start >= 0 && end > start, 'load the real serialization methods');
const methods = source.slice(start, end);
const create = (window = {}) => new Function('window', 'return ({' + methods + '});')(window);
const envelope = app => JSON.stringify({ schema: 1, settings: app.docSettings || {}, sections: app.sections || [] });

test('fast and fallback serialization preserve the complete JSON envelope byte for byte', () => {
  const app = create();
  for (const sections of [[], [{ id: '标题😀', columns: [{ elements: [{ data: { text: '引号"、反斜杠\\、换行\n', html: '<a href="/fr/a?x=1&y=2">内容</a>', enabled: false, zero: 0, value: null } }] }] }]]) {
    app.sections = sections;
    for (const settings of [undefined, {}, { language: 'fr', title: '中文 日本語', enabled: false, gap: 0 }]) {
      app.docSettings = settings;
      assert.equal(app.documentData(), envelope(app));
      assert.equal(app.documentData(app.historyData()), envelope(app));
      assert.deepEqual(JSON.parse(app.documentData()).sections, sections);
    }
  }
});

test('Alpine raw objects are serialized without walking reactive proxy properties', () => {
  const raw = new WeakMap();
  let reads = 0;
  const wrap = value => {
    const proxy = new Proxy(value, { get(target, key) { reads++; return Reflect.get(target, key); } });
    raw.set(proxy, value);
    return proxy;
  };
  const sections = [{ id: 'one', columns: [] }];
  const settings = { color: '#123456' };
  const app = create({ Alpine: { raw: value => raw.get(value) || value } });
  app.sections = wrap(sections);
  app.docSettings = wrap(settings);
  const expected = JSON.stringify({ schema: 1, settings, sections });
  assert.equal(app.documentData(), expected);
  assert.equal(app.documentData(app.historyData()), expected);
  assert.equal(reads, 0);
});

test('a prepared sections snapshot is not serialized a second time', () => {
  let serializations = 0;
  const app = create();
  app.sections = [{ toJSON() { serializations++; return { id: 'counted' }; } }];
  app.docSettings = { layout: 'wide' };
  const sectionsJson = app.historyData();
  assert.equal(serializations, 1);
  assert.equal(app.documentData(sectionsJson), '{"schema":1,"settings":{"layout":"wide"},"sections":[{"id":"counted"}]}');
  assert.equal(serializations, 1);
});

test('content and settings edits remain dirty until the complete saved envelope is restored', () => {
  const app = create();
  app.sections = [{ id: 's1', columns: [{ elements: [{ data: { text: 'Original' } }] }] }];
  app.docSettings = { background: 'white' };
  const saved = app.documentData();
  const assertCurrent = () => assert.equal(app.documentData(app.historyData()), envelope(app));
  app.sections[0].columns[0].elements[0].data.text = '译文';
  assertCurrent();
  assert.notEqual(app.documentData(), saved);
  app.sections = JSON.parse(saved).sections;
  assert.equal(app.documentData(), saved);
  const bareHistory = app.historyData();
  app.docSettings.background = 'black';
  assert.equal(app.historyData(), bareHistory);
  assertCurrent();
  assert.notEqual(app.documentData(), saved);
  app.docSettings = JSON.parse(saved).settings;
  assert.equal(app.documentData(), saved);
});

test('empty and non-array legacy states retain the existing fallback semantics', () => {
  const app = create();
  for (const sections of [undefined, null, { legacy: true }]) {
    app.sections = sections;
    app.docSettings = null;
    assert.equal(app.documentData(), envelope(app));
    assert.equal(app.documentData('[]'), envelope(app));
  }
});
