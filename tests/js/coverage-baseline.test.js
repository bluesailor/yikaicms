'use strict';

const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

test('coverage baseline compares percentage points only within the same source scope', (t) => {
  const root = path.resolve(__dirname, '../..');
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'yikai-coverage-'));
  t.after(() => fs.rmSync(directory, { recursive: true, force: true }));
  const source = fs.readFileSync(path.join(root, 'tests/fixtures/coverage-baseline.xml'), 'utf8');
  const script = path.join(root, 'tools/coverage-baseline.php');
  const baselineXml = path.join(directory, 'baseline.xml');
  const currentXml = path.join(directory, 'current.xml');
  const baselineJson = path.join(directory, 'baseline.json');
  const currentJson = path.join(directory, 'current.json');
  fs.writeFileSync(baselineXml, source);
  execFileSync('php', [script, baselineXml, baselineJson]);
  const baseline = JSON.parse(fs.readFileSync(baselineJson, 'utf8'));
  assert.equal(baseline.line_coverage_percent, 80);
  assert.equal(baseline.source_file_count, 1);

  fs.writeFileSync(currentXml, source.replaceAll('coveredstatements="80"', 'coveredstatements="78"'));
  execFileSync('php', [script, currentXml, currentJson, baselineJson]);
  assert.equal(JSON.parse(fs.readFileSync(currentJson, 'utf8')).change_pp, -2);

  fs.writeFileSync(currentXml, source.replaceAll('coveredstatements="80"', 'coveredstatements="77"'));
  assert.throws(() => execFileSync('php', [script, currentXml, currentJson, baselineJson], { stdio: 'pipe' }), /Coverage drop exceeds 2 percentage points/);

  fs.writeFileSync(currentXml, source.replace('includes/models/Example.php', 'controllers/Other.php'));
  assert.throws(() => execFileSync('php', [script, currentXml, currentJson, baselineJson], { stdio: 'pipe' }), /Coverage source scope changed/);

  fs.writeFileSync(currentXml, source.replaceAll('statements="100"', 'statements="101"'));
  assert.throws(() => execFileSync('php', [script, currentXml, currentJson, baselineJson], { stdio: 'pipe' }), /Coverage source scope changed/);

  // 77.99% is a 2.01 pp drop; comparison must use counts, not rounded display.
  const preciseBaselineXml = path.join(directory, 'precise-baseline.xml');
  const preciseBaselineJson = path.join(directory, 'precise-baseline.json');
  const preciseSource = source.replaceAll('statements="100"', 'statements="10000"').replaceAll('coveredstatements="80"', 'coveredstatements="8000"');
  fs.writeFileSync(preciseBaselineXml, preciseSource);
  execFileSync('php', [script, preciseBaselineXml, preciseBaselineJson]);
  fs.writeFileSync(currentXml, preciseSource.replaceAll('coveredstatements="8000"', 'coveredstatements="7799"'));
  assert.throws(() => execFileSync('php', [script, currentXml, currentJson, preciseBaselineJson], { stdio: 'pipe' }), /Coverage drop exceeds 2 percentage points/);
});
