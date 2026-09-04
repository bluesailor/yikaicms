'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { SHARDS } = require('./shards');

const sourceRoot = path.resolve(__dirname, '../..');
const shardKey = String(process.argv[2] || '');
const shard = SHARDS[shardKey];
const listOnly = process.argv.slice(3).includes('--list');

if (!shard) {
  console.error(`Usage: node tests/e2e/run-shard.js <${Object.keys(SHARDS).join('|')}>`);
  process.exit(2);
}

const artifactRoot = path.resolve(
  process.env.BLOX_E2E_ARTIFACT_ROOT
    || path.join(sourceRoot, 'test-results', 'e2e-shards', shardKey)
);
fs.mkdirSync(artifactRoot, { recursive: true });

function runPhase(name, args, phaseIndex = 0, extraEnv = {}) {
  const outputDir = path.join(artifactRoot, name);
  const reportDir = path.join(artifactRoot, `${name}-report`);
  const result = spawnSync(process.execPath, [
    path.join(__dirname, 'run-local.js'),
    ...args,
  ], {
    cwd: sourceRoot,
    env: {
      ...process.env,
      ...extraEnv,
      // Reusing one destination port across rapid Windows phases can exhaust
      // sockets still in TIME_WAIT. Each phase therefore owns a stable port.
      BLOX_E2E_PORT: String(shard.port + (phaseIndex * 10)),
      BLOX_E2E_OUTPUT_DIR: outputDir,
      BLOX_E2E_REPORT_DIR: reportDir,
      BLOX_E2E_SERVER_LOG: path.join(outputDir, 'php-server.log'),
    },
    stdio: 'inherit',
  });
  if (result.error) throw result.error;
  if (result.status !== 0) process.exit(result.status === null ? 1 : result.status);
}

runPhase('baseline', [
  '--grep',
  `(?=.*@ci)(?=.*@shard-${shardKey})`,
  ...(listOnly ? ['--list'] : []),
]);

if (shardKey === 'locale' && !listOnly) {
  runPhase('language-en', [
    '--lang=en',
    'tests/e2e/blox-home-language.spec.js',
    '--project=desktop-1440',
  ], 1);
  runPhase('language-ja', [
    '--lang=ja',
    'tests/e2e/blox-home-language.spec.js',
    '--project=desktop-1440',
  ], 2);
  runPhase('free-mode', [
    '--free',
    'tests/e2e/blox-page.spec.js',
    '--project=desktop-1440',
  ], 3);
}
