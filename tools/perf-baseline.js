#!/usr/bin/env node
'use strict';

// 编辑器性能基线：把 profile 用例按次独立跑 N 遍（每遍新建隔离站点、重启 PHP 服务），
// 汇总中位数 / P95 / 最小 / 最大，连同环境信息写成 JSON。只采样，不设阈值。
//
//   node tools/perf-baseline.js --runs=5 --out=perf-baseline.json [--compare=old.json]
//
// 一次只能有一个 run-local.js 在跑（Windows 下并发会互相抢端口与站点目录）。

const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { spawnSync } = require('node:child_process');
const { parseProfileOutput, summarize, compare } = require('./perf-baseline-lib');

const root = path.resolve(__dirname, '..');
const SPECS = ['blox-editor-load-profile.spec.js', 'blox-editor-bigdoc-profile.spec.js'];

const args = Object.fromEntries(process.argv.slice(2).map((arg) => {
  const match = arg.match(/^--([\w-]+)(?:=(.*))?$/);
  return match ? [match[1], match[2] ?? '1'] : [arg, '1'];
}));
const runs = Math.max(1, Number(args.runs || 3));
const out = args.out || path.join(root, 'test-results', `perf-baseline-${Date.now()}.json`);
const php = process.env.PHP_BINARY || 'php';

function firstLine(command, commandArgs) {
  const result = spawnSync(command, commandArgs, { encoding: 'utf8' });
  return String(result.stdout || result.stderr || '').split(/\r?\n/)[0].trim();
}

const environment = {
  measuredAt: new Date().toISOString(),
  commit: firstLine('git', ['-C', root, 'rev-parse', 'HEAD']),
  dirty: firstLine('git', ['-C', root, 'status', '--porcelain']) !== '',
  node: process.version,
  php: firstLine(php, ['-v']),
  platform: `${os.platform()} ${os.release()}`,
  cpu: (os.cpus()[0] || {}).model || '',
  cpuCount: os.cpus().length,
  memoryGB: Math.round(os.totalmem() / 1073741824),
  fixtures: SPECS,
  runs,
};

const samples = [];
const failures = [];
for (let i = 1; i <= runs; i++) {
  process.stdout.write(`run ${i}/${runs} … `);
  const result = spawnSync(process.execPath, [path.join(root, 'tests/e2e/run-local.js'), ...SPECS, '--project=desktop-1440'],
    { cwd: root, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  const values = parseProfileOutput(`${result.stdout}\n${result.stderr}`);
  if (result.status !== 0 || Object.keys(values).length === 0) {
    failures.push({ run: i, status: result.status, tail: String(result.stdout || '').split(/\r?\n/).slice(-20).join('\n') });
    console.log(`failed (exit ${result.status})`);
    continue;
  }
  samples.push(values);
  console.log(`${Object.keys(values).length} metrics`);
}

const metrics = summarize(samples);
const report = { environment, metrics, failures };
fs.mkdirSync(path.dirname(out), { recursive: true });
fs.writeFileSync(out, `${JSON.stringify(report, null, 2)}\n`);
console.log(`\n${samples.length}/${runs} runs ok → ${out}`);

const headline = Object.entries(metrics).filter(([key]) => /wallUntilCanvasMs|longTaskMs|heapMB|docKB|previewPostKB|previewRoundTripMs|untilSettledMs/.test(key));
for (const [key, m] of headline) console.log(`${key.padEnd(52)} median ${m.median}  p95 ${m.p95}  [${m.min}–${m.max}] n=${m.n}`);

if (args.compare) {
  const baseline = JSON.parse(fs.readFileSync(args.compare, 'utf8')).metrics || {};
  const rows = compare(baseline, metrics).filter((row) => row.review);
  console.log(rows.length === 0 ? '\nno metric median above the baseline p95'
    : `\nmedian above baseline p95 (rerun before treating as a regression):\n${rows.map((r) => `  ${r.key}: ${r.before} → ${r.now}`).join('\n')}`);
}
process.exitCode = samples.length === 0 ? 1 : 0;
