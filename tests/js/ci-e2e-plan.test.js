'use strict';

const assert = require('node:assert/strict');
const path = require('node:path');
const test = require('node:test');
const { plan } = require('../../tools/ci-e2e-plan');
const { SHARDS, shardMatrix, ciSpecsForShard, extraPhasesForShard, phasePort } = require('../e2e/shards');

const root = path.resolve(__dirname, '../..');

test('browser paths select their relevant shard', () => {
  assert.deepEqual(plan(['admin/upload.php'], { root }), ['media']);
  assert.deepEqual(plan(['admin/blox_templates.php'], { root }), ['design']);
  assert.deepEqual(plan(['lang/ja.php'], { root }), ['locale']);
  assert.deepEqual(plan(['admin/blox_home_api.php'], { root }), ['core']);
  assert.deepEqual(plan(['tests/e2e/blox-banner-video.spec.js'], { root }), ['media']);
  assert.deepEqual(plan(['tests/e2e/theme-market.spec.js'], { root }), ['design']);
  assert.deepEqual(plan(['includes/builder/BloxAreaLanguageManager.php'], { root }), ['locale']);
  assert.deepEqual(plan(['templates/blox/areas/corporate-site-header.json'], { root }), ['design']);
  assert.deepEqual(plan(['includes/builder/BlockRenderer.php'], { root }), ['core', 'media', 'design', 'locale']);
  assert.deepEqual(plan(['includes/builder/HomeBloxRenderer.php'], { root }), ['core', 'media', 'design', 'locale']);
  assert.deepEqual(plan(['tests/e2e/template-market-server.php'], { root }), ['core', 'media', 'design', 'locale']);
  assert.deepEqual(plan(['config/defaults.php'], { root }), ['core', 'media', 'design', 'locale']);
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
  assert.deepEqual(Object.values(SHARDS).map((shard) => shard.port), [8100, 9100, 10100, 11100]);
  assert.equal(new Set(Object.values(SHARDS).map((shard) => shard.port)).size, 4);
  assert.equal(shardMatrix().include.length, 4);
  const firstInstance = Object.keys(SHARDS).flatMap((key) => [phasePort(key, 0), phasePort(key, 50)]);
  assert.equal(new Set(firstInstance).size, firstInstance.length);
  assert.notEqual(phasePort('core', 0, 1), phasePort('media', 0, 0));
  assert.notEqual(phasePort('core', 50, 0), phasePort('media', 0, 0));
  assert.equal(phasePort('media', 2, 1), 19120);
});

test('every executable shard owns at least one tracked CI spec', () => {
  for (const key of Object.keys(SHARDS)) {
    assert.ok(ciSpecsForShard(key).length > 0, `${key} has no executable specs`);
  }
});

test('locale extra phases have real language/free markers', () => {
  const phases = extraPhasesForShard('locale', path.resolve(root, 'tests/e2e'));
  assert.deepEqual(phases.map((phase) => phase.name), ['language-en', 'language-ja', 'free-mode']);
  assert.equal(phases[0].grep, '@language');
  assert.equal(phases[1].grep, '@language');
  assert.equal(phases[2].grep, '@ci');
  for (const phase of phases) {
    assert.equal(require('node:fs').existsSync(phase.spec), true);
    assert.match(require('node:fs').readFileSync(phase.spec, 'utf8'), new RegExp(phase.grep.replace('@', '\\@')));
  }
});

test('CI workflow keeps a planning job, four-shard fanout, and required aggregator', () => {
  const workflow = require('node:fs').readFileSync(path.resolve(root, '.github/workflows/ci.yml'), 'utf8');
  assert.match(workflow, /e2e_plan:/);
  assert.match(workflow, /e2e_shards:/);
  assert.match(workflow, /matrix: \$\{\{ fromJSON\(needs\.e2e_plan\.outputs\.matrix\) \}\}/);
  assert.match(workflow, /name: Blox Browser Regression\s*\n\s*if: always\(\)/);
  for (const key of Object.keys(SHARDS)) assert.match(workflow, new RegExp(`run-shard\.js \\$\\{\\{ matrix\.shard \\}\\}`));
});
