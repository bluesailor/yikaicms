'use strict';

const assert = require('node:assert/strict');
const path = require('node:path');
const test = require('node:test');
const { plan } = require('../../tools/ci-e2e-plan');
const { SHARDS, shardMatrix } = require('../e2e/shards');
const { validateSpecs } = require('../e2e/validate-shards');

const root = path.resolve(__dirname, '../..');

test('browser paths select only their relevant shard', () => {
  assert.deepEqual(plan(['admin/upload.php'], { root }), ['media']);
  assert.deepEqual(plan(['admin/blox_templates.php'], { root }), ['design']);
  assert.deepEqual(plan(['lang/ja.php'], { root }), ['locale']);
  assert.deepEqual(plan(['admin/blox_home_api.php'], { root }), ['core']);
});

test('tagged specs select their declared shard', () => {
  assert.deepEqual(plan(['tests/e2e/blox-banner-video.spec.js'], { root }), ['media']);
  assert.deepEqual(plan(['tests/e2e/theme-market.spec.js'], { root }), ['design']);
});

test('shared browser infrastructure selects every shard', () => {
  assert.deepEqual(plan(['tests/e2e/run-local.js'], { root }), ['core', 'media', 'design', 'locale']);
  assert.deepEqual(plan(['.github/workflows/ci.yml'], { root }), ['core', 'media', 'design', 'locale']);
});

test('documentation-only changes do not schedule browser shards', () => {
  assert.deepEqual(plan(['docs/example.md', 'README.md'], { root }), []);
});

test('full mode preserves all browser coverage', () => {
  assert.deepEqual(plan([], { root, full: true }), ['core', 'media', 'design', 'locale']);
});

test('each shard owns a distinct fixed port and matrix row', () => {
  assert.deepEqual(Object.values(SHARDS).map((shard) => shard.port), [8081, 8082, 8083, 8084]);
  assert.equal(new Set(Object.values(SHARDS).map((shard) => shard.port)).size, 4);
  assert.equal(shardMatrix().include.length, 4);
});

test('every ci browser test has exactly one known shard', () => {
  const result = validateSpecs(path.join(root, 'tests/e2e'));
  assert.deepEqual(result.errors, []);
  for (const count of Object.values(result.counts)) assert.ok(count > 0);
});
