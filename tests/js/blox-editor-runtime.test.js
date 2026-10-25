const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../assets/js/yikay-editor-runtime.js'), 'utf8');

test('cached runtime defines methods without boot data or instance side effects', () => {
  const window = {};
  vm.runInNewContext(source, { window });
  assert.deepEqual(Object.keys(window), ['BloxEditorRuntime']);
  const sections = [], settings = {};
  const state = { sections, docSettings: settings, runtimeText: {}, cmdText: {} };
  const app = window.BloxEditorRuntime.create(state);
  assert.equal(app, state);
  assert.equal(app.sections, sections);
  assert.equal(app.docSettings, settings);
  assert.equal(typeof app.initTreeSortable, 'function');
  assert.equal(app.documentData(), '{"schema":1,"settings":{},"sections":[]}');
});

test('draft summary caches remain private to each editor instance', () => {
  let calls = 0;
  const window = { BloxDraftSummary: { summarize: () => ({ total: ++calls }) } };
  vm.runInNewContext(source, { window });
  const make = () => window.BloxEditorRuntime.create({ sections: [], docSettings: {}, publishedDocument: {}, draftRevision: 0, runtimeText: {}, cmdText: {} });
  const first = make(), second = make();
  assert.equal(first.draftSummary().total, 1);
  assert.equal(first.draftSummary().total, 1);
  assert.equal(second.draftSummary().total, 2);
  assert.equal(first.draftSummary().total, 1);
});
