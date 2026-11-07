'use strict';
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync, spawnSync } = require('node:child_process');
const { createHash } = require('node:crypto');
const { createSite, removeSite } = require('./isolated-site');
const source = path.resolve(__dirname, '../..');
const php = process.env.PHP_BINARY;
const outDir = process.env.YK_PRODUCT_MYSQL_OUTPUT;
const database = process.env.YK_PRODUCT_MYSQL_DATABASE;
const port = process.env.YK_PRODUCT_MYSQL_PORT;
if (!php || !outDir || !/^s103_product_[a-z0-9_]+$/.test(database || '') || Number(port) < 33080) {
  throw new Error('Owned MySQL environment required');
}
const site = createSite(source);
const report = { site, database, port: Number(port), steps: [], passed: false };
fs.mkdirSync(outDir, { recursive: true });
function run(args, name, json = false) {
  const result = spawnSync(php, args, { cwd: site, env: process.env, encoding: 'utf8', timeout: 120000, maxBuffer: 8 * 1024 * 1024 });
  fs.writeFileSync(path.join(outDir, name + (json ? '.json' : '.log')), result.stdout || '');
  if (result.stderr) fs.writeFileSync(path.join(outDir, name + '.stderr.log'), result.stderr);
  report.steps.push({ name, exit: result.status, ...(json && result.status === 0 ? { result: JSON.parse(result.stdout) } : {}) });
  if (result.status !== 0) throw new Error(name + ' failed: ' + (result.stderr || result.error || result.stdout).slice(-2500));
}
try {
  const baseline = execFileSync('git', ['show', 'v2.2.0:install/sql/mysql.sql'], { cwd: source, encoding: 'utf8', maxBuffer: 8 * 1024 * 1024 });
  report.baseline = { tag: 'v2.2.0', commit: execFileSync('git', ['rev-parse', 'v2.2.0^{}'], { cwd: source, encoding: 'utf8' }).trim(),
    sha256: createHash('sha256').update(baseline).digest('hex') };
  fs.writeFileSync(path.join(site, 'storage/v220-mysql.sql'), baseline);
  let config = fs.readFileSync(path.join(site, 'config/config.php.example'), 'utf8');
  for (const [key, value] of Object.entries({ DB_HOST: '127.0.0.1', DB_PORT: port, DB_NAME: database, DB_USER: 'root', DB_PASS: '' })) {
    config = config.replace(new RegExp("define\\('" + key + "',\\s*'[^']*'\\)"), "define('" + key + "', '" + value + "')");
  }
  fs.writeFileSync(path.join(site, 'config/config.php'), config);
  run(['tests/fixtures/product-drafts-mysql.php', 'provision'], '01-baseline', true);
  for (const id of ['20261107_translation_batches', '20261108_translation_links']) {
    run(['bin/yikai.php', 'migrate:run', '--id=' + id, '--yes'], '02-' + id);
    run(['bin/yikai.php', 'migrate:run', '--id=' + id, '--yes'], '03-repeat-' + id);
  }
  run(['tests/fixtures/product-drafts-mysql.php', 'verify'], '04-upgrade-preservation', true);
  run(['tests/fixtures/product-drafts-mysql.php', 'partial'], '05-interrupted-ddl', true);
  run(['bin/yikai.php', 'migrate:run', '--id=20261107_translation_batches', '--yes'], '06-resume-interrupted-ddl');
  run(['tests/fixtures/product-drafts-mysql.php', 'verify-partial'], '07-resumed-upgrade-preservation', true);
  run(['tests/fixtures/product-drafts-mysql.php', 'concurrency'], '08-concurrency', true);
  report.passed = true;
} catch (error) {
  report.error = error.message;
  console.error(error.message);
  process.exitCode = 1;
} finally {
  removeSite(site);
  report.siteRemoved = !fs.existsSync(site);
  fs.writeFileSync(path.join(outDir, 'summary.json'), JSON.stringify(report, null, 2));
  console.log(JSON.stringify({ database, passed: report.passed, siteRemoved: report.siteRemoved, output: outDir }));
}
