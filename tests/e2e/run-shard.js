'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { SHARDS, phasesForShard, phasePort } = require('./shards');

const sourceRoot = path.resolve(__dirname, '../..');
const shardKey = String(process.argv[2] || '');
const shard = SHARDS[shardKey];
const listOnly = process.argv.slice(3).includes('--list');
const slotArg = process.argv.slice(3).find((arg) => arg.startsWith('--slot='));
const slot = Number(slotArg ? slotArg.slice('--slot='.length) : (process.env.BLOX_E2E_RUN_SLOT || 0));

if (!shard) {
  console.error(`Usage: node tests/e2e/run-shard.js <${Object.keys(SHARDS).join('|')}> [--list] [--slot=0..4]`);
  process.exit(2);
}
if (!Number.isInteger(slot) || slot < 0 || slot > 4) {
  console.error('BLOX_E2E_RUN_SLOT/--slot must be an integer from 0 to 4');
  process.exit(2);
}

const phases = phasesForShard(shardKey, __dirname);
if (phases.length === 0) {
  console.error(`Browser shard ${shardKey} has no @ci spec files`);
  process.exit(1);
}

const artifactRoot = path.resolve(
  process.env.BLOX_E2E_ARTIFACT_ROOT
    || path.join(sourceRoot, 'test-results', 'e2e-shards', `${shardKey}-${process.pid}-${Date.now()}`)
);
fs.mkdirSync(artifactRoot, { recursive: true });

if (listOnly) {
  console.log(JSON.stringify({
    shard: shardKey,
    phases: phases.map((phase) => ({
      name: phase.name || path.basename(phase.spec),
      spec: path.basename(phase.spec),
      grep: phase.grep,
    })),
  }, null, 2));
  process.exit(0);
}

const defaultProjects = ['desktop-1440', 'tablet-768', 'mobile-390'];

function runPhase(name, spec, phaseIndex, options = {}) {
  const outputDir = path.join(artifactRoot, name);
  const reportDir = path.join(artifactRoot, `${name}-report`);
  const projects = options.projects || defaultProjects;
  const result = spawnSync(process.execPath, [
    path.join(__dirname, 'run-local.js'),
    '--grep',
    options.grep || '@ci',
    ...(options.args || []),
    ...(options.free ? ['--free'] : []),
    ...projects.flatMap((project) => ['--project', project]),
  ], {
    cwd: sourceRoot,
    env: {
      ...process.env,
      ...(options.env || {}),
      // A phase owns its own site and DB. The shard range and instance slot
      // prevent fixture/main-port collisions during same-host parallel runs.
      BLOX_E2E_PORT: '0',
      BLOX_E2E_PORT_BASE: String(phasePort(shardKey, phaseIndex, slot)),
      BLOX_E2E_PORT_RANGE: '8',
      BLOX_E2E_SPEC_FILTER: path.basename(spec),
      BLOX_E2E_EXPECT_TESTS: '1',
      BLOX_E2E_OUTPUT_DIR: outputDir,
      BLOX_E2E_REPORT_DIR: reportDir,
      BLOX_E2E_SERVER_LOG: path.join(outputDir, 'php-server.log'),
    },
    stdio: 'inherit',
  });
  if (result.error) throw result.error;
  if (result.status !== 0) process.exit(result.status === null ? 1 : result.status);
}

phases.forEach((phase, index) => {
  const stem = phase.name || path.basename(phase.spec, '.spec.js');
  runPhase(stem.startsWith('language-') || stem === 'free-mode'
    ? stem
    : `${String(index + 1).padStart(2, '0')}-${stem}`,
  phase.spec,
  index,
  phase);
});
