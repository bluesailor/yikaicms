const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = name => fs.readFileSync(path.join(__dirname, '../../assets/js', name), 'utf8');

function fixture() {
  const scripts = [];
  const window = { document: {
    currentScript: { dataset: { hugerteSrc: '/mounted/assets/hugerte/hugerte.min.js?v=123' } },
    createElement: () => ({ remove() { this.removed = true; } }),
    head: { appendChild: node => scripts.push(node) },
  } };
  vm.runInNewContext(source('yikay-richtext-loader.js'), { window });
  return { window, scripts, load: window.BloxRichTextLoader.load };
}

test('rich text loads only on demand and simultaneous callers share one script', async () => {
  const f = fixture();
  assert.equal(f.scripts.length, 0);
  const first = f.load(), second = f.load();
  assert.equal(first, second);
  assert.equal(f.scripts.length, 1);
  assert.equal(f.scripts[0].src, '/mounted/assets/hugerte/hugerte.min.js?v=123');
  f.window.hugerte = { init() {} };
  f.scripts[0].onload();
  assert.equal(await first, f.window.hugerte);
  assert.equal(await f.load(), f.window.hugerte);
  assert.equal(f.scripts.length, 1);
});

test('network failure removes the failed node and permits a subsequent retry', async () => {
  const f = fixture();
  const failed = f.load();
  f.scripts[0].onerror();
  await assert.rejects(failed, /could not be loaded/);
  assert.equal(f.scripts[0].removed, true);
  const retried = f.load();
  f.window.hugerte = { init() {} };
  f.scripts[1].onload();
  assert.equal(await retried, f.window.hugerte);
  assert.equal(f.scripts.length, 2);
});

test('delayed runtime installs aliases and accessibility hooks exactly once', async () => {
  const f = fixture();
  vm.runInNewContext(source('rich-editor.js'), { window: f.window });
  let registrations = 0;
  const ready = f.load();
  f.window.hugerte = { init() {}, on() { registrations++; } };
  f.scripts[0].onload();
  await ready;
  await f.load();
  f.window.installRichEditorRuntime();
  assert.equal(f.window.tinymce, f.window.hugerte);
  assert.equal(registrations, 1);
  assert.equal(f.window.editorLanguage('ja'), 'ja');
  assert.equal(f.window.editorLanguage('en'), '');
});

test('a compact control closed before loading cannot initialize a stale editor', async () => {
  let release, loads = 0, inits = 0;
  const window = { BloxRichTextLoader: { load() { loads++; return new Promise(resolve => release = resolve); } } };
  vm.runInNewContext(source('yikay-compact-richtext.js'), { window });
  const state = window.BloxCompactRichText.create(() => ({ id: 'one', text: 'unchanged' }), () => assert.fail('unexpected write'));
  state.$refs = {};
  state.$watch = () => {};
  state.$nextTick = fn => fn();
  state.init();
  state.mount();
  assert.equal(loads, 1);
  state.destroy();
  window.hugerte = { init() { inits++; } };
  release();
  await Promise.resolve();
  await Promise.resolve();
  assert.equal(inits, 0);
});

test('a failed compact load retains editable source content', async () => {
  const writes = [];
  const value = { id: 'one', text: '<p>kept</p>', format: 'html' };
  const window = { BloxRichTextLoader: { load: () => Promise.reject(new Error('offline')) } };
  vm.runInNewContext(source('yikay-compact-richtext.js'), { window });
  const state = window.BloxCompactRichText.create(() => value, html => writes.push(html));
  await state.mount();
  assert.equal(state.failed, true);
  assert.equal(state.sourceMode, true);
  assert.equal(value.text, '<p>kept</p>');
  state.sourceInput('<p>edited offline</p>');
  assert.deepEqual(writes, ['<p>edited offline</p>']);
});
