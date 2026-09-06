'use strict';

const assert = require('node:assert/strict');
const path = require('node:path');
const test = require('node:test');
const { plan } = require('../../tools/ci-e2e-plan');
const {
  SHARDS, shardMatrix, ciSpecsForShard, extraPhasesForShard, phasePort, phasesForShard,
  SHARD_KEYS, SHARD_PORT_WINDOW, PHASE_PORT_STEP,
} = require('../e2e/shards');

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

// R9 审计 P2-2：tests/e2e 下的支撑件曾经一律落到 core，于是只改 banner-helpers.js
// 的 PR 不跑 media，只改 router.php（每个分片一次性站点的前端控制器）也只跑 core。
test('shared e2e support files invalidate every browser lane', () => {
  const ALL = ['core', 'media', 'design', 'locale'];
  for (const file of [
    'tests/e2e/router.php',
    'tests/e2e/banner-helpers.js',
    'tests/e2e/theme-market-fixture.js',
    'tests/e2e/minimal-footer-fixture.php',
    'tests/e2e/channel-pagination-fixture.php',
    'tests/e2e/set-lang.php',
    'tests/e2e/business-surfaces-page.php',
    'tests/e2e/site-diagnostics.js',
    'tests/e2e/helpers.js',
    'tests/e2e/run-local.js',
    'tests/e2e/shards.js',
  ]) {
    assert.deepEqual(plan([file], { root }), ALL, `${file} must fan out to every shard`);
  }
});

// 截图基线属于它自己的 spec，不该把整个矩阵拖起来。
test('snapshot baselines stay with their own spec shard', () => {
  assert.deepEqual(
    plan(['tests/e2e/blox-default-areas.spec.js-snapshots/header-mobile-390-win32.png'], { root }),
    ['design'],
  );
  assert.deepEqual(
    plan(['tests/e2e/blox-banner-video.spec.js-snapshots/frame-desktop-1440-linux.png'], { root }),
    ['media'],
  );
});

// R9 审计 P3-2：注释声明「每分片 900 端口窗口」，但越界从不报错，
// phase 长起来会静默借用下一个分片的基址。
test('phase ports stay inside each shard 900-port window', () => {
  const lastUsable = Math.floor(SHARD_PORT_WINDOW / PHASE_PORT_STEP) - 1;
  assert.equal(phasePort('core', lastUsable, 0), SHARDS.core.port + (lastUsable * PHASE_PORT_STEP));
  assert.throws(() => phasePort('core', lastUsable + 1, 0), /900-port window/);
  for (const key of SHARD_KEYS) {
    const phases = phasesForShard(key).length;
    assert.ok(phases > 0, `${key} has no phases`);
    assert.ok(
      (phases - 1) * PHASE_PORT_STEP + PHASE_PORT_STEP <= SHARD_PORT_WINDOW,
      `${key} has ${phases} phases and no longer fits its port window`,
    );
  }
});

// R9 审计 P2-1：--list 只证明选中了用例，运行期全部 skip 的 phase 同样退出 0。
test('run-local refuses a phase that executed no tests', () => {
  const runner = require('node:fs').readFileSync(path.resolve(root, 'tests/e2e/run-local.js'), 'utf8');
  assert.match(runner, /BLOX_E2E_JSON_REPORT/);
  assert.match(runner, /report\.stats\.expected/);
  assert.match(runner, /executed 0 tests/);
  const config = require('node:fs').readFileSync(path.resolve(root, 'playwright.config.js'), 'utf8');
  assert.match(config, /jsonReport \? \[\['json'/);
});

// R9 审计 P3-1：--list 是纯查询，不该建产物目录。
test('run-shard --list does not create artifact directories', () => {
  const runner = require('node:fs').readFileSync(path.resolve(root, 'tests/e2e/run-shard.js'), 'utf8');
  const listIndex = runner.indexOf('if (listOnly) {');
  const mkdirIndex = runner.indexOf('fs.mkdirSync(artifactRoot');
  assert.ok(listIndex > 0 && mkdirIndex > 0);
  assert.ok(mkdirIndex > listIndex, 'artifactRoot must be created after the --list early return');
});

// R9 审计 P3-4：重跑失败 job 时 artifact 同名会冲突。
test('CI browser diagnostics artifacts are unique per run attempt', () => {
  const workflow = require('node:fs').readFileSync(path.resolve(root, '.github/workflows/ci.yml'), 'utf8');
  assert.match(workflow, /name: blox-playwright-diagnostics-\$\{\{ matrix\.shard \}\}-\$\{\{ github\.run_id \}\}-\$\{\{ github\.run_attempt \}\}/);
});
