'use strict';

const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const { createHash } = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const root = path.resolve(__dirname, '../..');
const workflow = fs.readFileSync(path.join(root, '.github/workflows/ci.yml'), 'utf8').replace(/\r\n/g, '\n');
const bash = process.platform === 'win32'
  ? path.join(process.env.ProgramFiles || 'C:\\Program Files', 'Git/bin/bash.exe') : 'bash';

function job(name) {
  const start = workflow.indexOf(`\n  ${name}:\n`);
  assert.ok(start >= 0, `Missing CI job: ${name}`);
  const body = workflow.slice(start + name.length + 5);
  const end = body.search(/^  [\w-]+:/m);
  return end < 0 ? body : body.slice(0, end);
}

function step(source, name) {
  const marker = `      - name: ${name}\n`;
  const start = source.indexOf(marker);
  assert.ok(start >= 0, `Missing CI step: ${name}`);
  const body = source.slice(start + marker.length);
  const end = body.search(/^      - /m);
  return end < 0 ? body : body.slice(0, end);
}

function script(source) {
  const lines = source.split('\n');
  const index = lines.findIndex(line => line.startsWith('        run: '));
  assert.ok(index >= 0, 'Missing shell command');
  const command = lines[index].slice('        run: '.length);
  return command === '|' ? lines.slice(index + 1).map(line => line.replace(/^ {10}/, '')).join('\n') : command;
}

function run(command, options = {}) {
  return spawnSync(bash, ['--noprofile', '--norc', '-e', '-o', 'pipefail', '-c', command], {
    encoding: 'utf8', ...options,
  });
}

test('Blox assertions share the PHP 8.2 suite and the retained check fails closed', () => {
  const suite = job('phpunit');
  assert.match(suite, /php: \['8\.2', '8\.3', '8\.4'\]/);
  assert.match(step(suite, 'Inject Blox paid sources'), /if: matrix\.php == '8\.2'/);
  for (const name of ['Verify Blox paid asset composition', 'Blox i18n gate (strict scope incl. paid sources)']) {
    assert.match(step(suite, name), /if: matrix\.php == '8\.2' && steps\.blox_src\.outputs\.present == 'true'/);
  }
  assert.match(suite, /php bin\/blox-assets\.php verify-source \./);
  assert.match(suite, /php tools\/check_blox_i18n\.php/);
  assert.equal((workflow.match(/run: vendor\/bin\/phpunit --colors=never/g) || []).length, 1);

  const gate = job('blox-suite');
  assert.match(gate, /name: Blox Suite \(injected\)/);
  assert.match(gate, /if: always\(\)/);
  assert.match(gate, /needs: phpunit/);
  assert.doesNotMatch(gate, /composer install|actions\/checkout|setup-php/);
  const command = script(step(gate, 'Enforce Blox suite result'));
  for (const result of ['success', 'failure', 'cancelled', 'skipped', '']) {
    const execution = run(command, { env: { ...process.env, PHPUNIT_RESULT: result } });
    assert.equal(execution.status === 0, result === 'success', `${result}: ${execution.stderr}`);
  }
});

test('both upgrade runtimes consume the verified package and do not hide download failures', () => {
  const upgrade = job('n-minus-one-upgrade');
  assert.match(upgrade, /needs: free-package/);
  assert.match(upgrade, /php: \['8\.2', '8\.4'\]/);
  const download = step(upgrade, 'Download verified current package');
  assert.match(download, /if: needs\.free-package\.outputs\.has_package == 'true'/);
  assert.match(download, /uses: actions\/download-artifact@v4/);
  assert.match(download, /name: yikaicms-free-\$\{\{ github\.sha \}\}/);
  assert.match(download, /path: releases/);
  assert.doesNotMatch(upgrade, /continue-on-error/);
  const fallback = step(upgrade, 'Build current full package (legacy source fallback)');
  assert.match(fallback, /if: needs\.free-package\.outputs\.has_package != 'true'/);
  assert.doesNotMatch(fallback, /always\(\)|failure\(\)/);
  assert.match(fallback, /bash build\.sh "\$VERSION" --no-delta/);
  assert.match(job('free-package'), /has_package: \$\{\{ steps\.blox_src\.outputs\.present \}\}/);
});

test('reused package hash check rejects tampered and missing artifacts', t => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'yikai-ci-budget-'));
  t.after(() => {
    assert.equal(path.dirname(directory), path.resolve(os.tmpdir()));
    assert.ok(path.basename(directory).startsWith('yikai-ci-budget-'));
    fs.rmSync(directory, { recursive: true, force: true });
  });
  fs.mkdirSync(path.join(directory, 'config'));
  fs.mkdirSync(path.join(directory, 'releases'));
  fs.writeFileSync(path.join(directory, 'config/version.php'), "<?php define('CMS_VERSION', '9.9.9');");
  const zip = path.join(directory, 'releases/yikaicms-v9.9.9.zip');
  const sha = path.join(directory, 'releases/yikaicms-v9.9.9.sha256');
  const payload = Buffer.from('verified package fixture');
  fs.writeFileSync(zip, payload);
  fs.writeFileSync(sha, `${createHash('sha256').update(payload).digest('hex')}  releases/yikaicms-v9.9.9.zip\n`);
  const command = script(step(job('n-minus-one-upgrade'), 'Verify current package hash'));
  assert.equal(run(command, { cwd: directory }).status, 0);
  fs.writeFileSync(zip, 'tampered package');
  assert.notEqual(run(command, { cwd: directory }).status, 0);
  fs.unlinkSync(zip);
  assert.notEqual(run(command, { cwd: directory }).status, 0);
  fs.writeFileSync(zip, payload);
  fs.unlinkSync(sha);
  assert.notEqual(run(command, { cwd: directory }).status, 0);
});

test('artifact lifetimes are bounded without dropping release validation or failure diagnostics', () => {
  const packageJob = job('free-package');
  const upload = step(packageJob, 'Upload verified free package');
  assert.match(upload, /retention-days: 1\s*$/m);
  assert.match(upload, /compression-level: 0/);
  assert.match(upload, /overwrite: true/);
  assert.match(upload, /if-no-files-found: error/);
  assert.match(packageJob, /php tools\/release-artifact-smoke\.php "\$ZIP"/);
  assert.match(packageJob, /php bin\/blox-assets\.php verify-free "\$PACKAGE_ROOT"/);
  const diagnostics = step(job('e2e_shards'), 'Upload Playwright diagnostics');
  assert.match(diagnostics, /if: failure\(\)/);
  assert.match(diagnostics, /retention-days: 3\s*$/m);
  const coverage = fs.readFileSync(path.join(root, '.github/workflows/coverage-baseline.yml'), 'utf8');
  assert.match(coverage, /retention-days: 30/);
});

test('existing compatibility, database, browser and language checks remain bounded and blocking', () => {
  assert.match(job('lint'), /php: \['8\.0', '8\.1', '8\.2', '8\.3', '8\.4', '8\.5'\]/);
  assert.match(job('smoke'), /php: '8\.2'[\s\S]*lang: 'zh-CN'[\s\S]*php: '8\.4'[\s\S]*lang: 'zh-CN'[\s\S]*php: '8\.2'[\s\S]*lang: 'en'/);
  assert.match(job('mysql57'), /image: mysql:5\.7/);
  assert.match(job('e2e_shards'), /run: node tests\/e2e\/run-shard\.js \$\{\{ matrix\.shard \}\}/);
  for (const name of ['lint', 'phpunit', 'smoke', 'mysql57', 'blox-suite', 'n-minus-one-upgrade', 'free-package', 'e2e_plan', 'e2e_shards', 'e2e', 'psalm', 'js-tests', 'lang-keys']) {
    const body = job(name);
    assert.doesNotMatch(body, /continue-on-error/);
    const minutes = Number((body.match(/timeout-minutes: (\d+)/) || [])[1]);
    assert.ok(minutes > 0 && minutes <= 75, `${name} must have a finite timeout`);
  }
  assert.match(job('psalm'), /key: composer-8\.2-/);
});
