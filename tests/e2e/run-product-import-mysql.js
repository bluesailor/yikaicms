'use strict';
// 产品导入后台执行（2.3.0）的 MySQL 验收：一次性站点 + 一次性 MySQL 实例（run-product-import-mysql.ps1 起停）。
// 真实的多进程执行者、真杀进程、真浏览器：
//   1. 浏览器确认导入后马上关掉，任务由后台跑完；重新打开后台看得到结果与出错行
//   2. 后台正在跑时打开页面：自动打开这个任务、显示「后台执行中」、跑完自己变成「导入完成」
//   3. 两个执行者同时跑同一个任务 / 两个任务：只有一个执行者，不重复写
//   4. 写进库后、存进度前退出；执行中被强杀：下一个执行者接着做，不重复
//   5. 失败行重试；执行中请求撤销；中断的更新续跑后撤销恢复原值
//   6. 升级前的任务：看、撤销照旧；没跑完的不会自己跑，点「继续」后不重复
//   7. 建任务的人不再是超级管理员：任务停下
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const net = require('node:net');
const { spawn, spawnSync } = require('node:child_process');
const { chromium } = require('@playwright/test');
const { createSite, removeSite } = require('./isolated-site');

const source = path.resolve(__dirname, '../..');
const php = process.env.PHP_BINARY;
const outDir = process.env.YK_PIMP_MYSQL_OUTPUT;
const database = process.env.YK_PIMP_MYSQL_DATABASE;
const mysqlPort = process.env.YK_PIMP_MYSQL_PORT;
if (!php || !outDir || !/^s103_pimport_[a-z0-9_]+$/.test(database || '') || Number(mysqlPort) < 33080) throw new Error('Owned MySQL environment required');

const site = createSite(source);
const report = { site, database, checks: [], steps: [], passed: false };
const check = (ok, label, detail) => { report.checks.push({ ok: !!ok, label, ...(detail === undefined ? {} : { detail }) }); if (!ok) console.error('FAIL ' + label + (detail === undefined ? '' : ' ' + JSON.stringify(detail))); else console.log('PASS ' + label); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const adminPassword = 'Pimp-' + crypto.randomBytes(12).toString('hex');
const env = { ...process.env, YK_PIMP_MYSQL_DATADIR: process.env.YK_PIMP_MYSQL_DATADIR, YK_PIMP_ADMIN_PASSWORD: adminPassword };
fs.mkdirSync(outDir, { recursive: true });

function fixture(...args) {
  const r = spawnSync(php, ['-d', 'display_errors=stderr', '-d', 'error_reporting=-1', 'tests/fixtures/product-import-mysql.php', ...args], { cwd: site, env, encoding: 'utf8', timeout: 180000 });
  report.steps.push({ args: args.join(' '), exit: r.status, out: (r.stdout || '').trim().slice(-600), err: (r.stderr || '').trim().slice(-600) });
  if (r.status !== 0) throw new Error(`fixture ${args.join(' ')} failed (${r.status}): ${(r.stderr || r.stdout || '').slice(-1500)}`);
  return JSON.parse(r.stdout.trim().split('\n').pop());
}
// 独立的执行者进程（后台跑，可杀）
function startWorker(...args) {
  const child = spawn(php, ['-d', 'display_errors=stderr', 'tests/fixtures/product-import-mysql.php', 'worker', ...args], { cwd: site, env });
  let out = '';
  child.stdout.on('data', (d) => { out += d; });
  child.stderr.on('data', (d) => { out += d; });
  const done = new Promise((resolve) => child.on('exit', (code) => resolve({ code, lines: out.trim().split('\n').filter(Boolean) })));
  return { child, done };
}
const lastJson = (lines) => { for (let i = lines.length - 1; i >= 0; i--) { try { return JSON.parse(lines[i]); } catch { /* not json */ } } return null; };
async function waitFor(fn, label, timeoutMs = 120000, every = 500) {
  const end = Date.now() + timeoutMs;
  let last;
  while (Date.now() < end) { last = await fn(); if (last.ok) return last.value; await sleep(every); }
  throw new Error('timeout: ' + label + ' ' + JSON.stringify(last && last.value));
}
const freePort = () => new Promise((resolve, reject) => { const s = net.createServer(); s.once('error', reject); s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => resolve(port)); }); });
const clean = (r, n, extra = {}) => r.duplicate_tags === 0 && r.duplicate_import_keys === 0 && r.created_matches_tagged && r.db_products_tagged === n
  && r.db_products_by_import_key === n && r.db_categories_tagged === r.job_created_categories && r.db_media_tagged === r.job_created_media
  && Object.entries(extra).every(([k, v]) => JSON.stringify(r[k]) === JSON.stringify(v));

let server = null;
(async () => {
  let webPort = 0;
  try {
    webPort = await freePort();
    const baseURL = `http://127.0.0.1:${webPort}`;
    let config = fs.readFileSync(path.join(site, 'config/config.php.example'), 'utf8');
    for (const [key, value] of Object.entries({ DB_HOST: '127.0.0.1', DB_PORT: mysqlPort, DB_NAME: database, DB_USER: 'root', DB_PASS: '', SITE_URL: baseURL })) {
      config = config.replace(new RegExp("define\\('" + key + "',\\s*'[^']*'\\)"), "define('" + key + "', '" + value + "')");
    }
    config = config.replace(/define\('DEBUG',\s*(?:true|false)\)/, "define('DEBUG', true)");   // 一次性测试站：出错要看得见
    config += `\nif (!defined('ENCRYPT_KEY')) define('ENCRYPT_KEY', '${crypto.randomBytes(32).toString('hex')}');\n`;
    fs.writeFileSync(path.join(site, 'config/config.php'), config);
    fs.writeFileSync(path.join(site, 'installed.lock'), new Date().toISOString());
    for (const d of ['storage/logs', 'storage/cache', 'storage/login_throttle', 'storage/backups', 'storage/security', 'storage/upgrade']) fs.mkdirSync(path.join(site, d), { recursive: true });
    const provision = fixture('provision');
    report.mysql = provision;
    check(provision.ok, `provisioned ${provision.server}, isolation ${provision.isolation}`);

    // 网站：PHP 内置服务器（Windows 上单线程，后台执行请求会和页面请求排队，正好也验证「页面关了后台照跑」）
    const serverLog = fs.openSync(path.join(outDir, 'php-server.log'), 'w');
    server = spawn(php, ['-S', `127.0.0.1:${webPort}`, '-t', '.', 'tests/e2e/router.php'], { cwd: site, env, stdio: ['ignore', serverLog, serverLog] });
    await waitFor(async () => { try { const r = await fetch(baseURL + '/admin/login.php'); return { ok: r.status === 200 }; } catch { return { ok: false }; } }, 'web server up', 30000);

    const browser = await chromium.launch();
    const login = async () => {
      const context = await browser.newContext({ baseURL });
      const page = await context.newPage();
      await page.goto('/admin/login.php');
      await page.locator('input[name="username"]').fill('admin');
      await page.locator('input[name="password"]').fill(adminPassword);
      await page.locator('button[type="submit"]').click();
      await page.waitForURL((u) => !u.pathname.endsWith('/admin/login.php'), { timeout: 20000 });
      return { context, page };
    };

    // ── 1. 确认导入后马上关掉浏览器 ──
    const pkg = fixture('package', 'browser-a', '30', 'zip', 'bad');
    {
      const { context, page } = await login();
      await page.goto('/admin/product_import.php');
      await page.locator('[data-pimp-file]').setInputFiles(pkg.zip);
      const preview = page.locator('[data-pimp-preview]');
      await page.locator('[data-pimp-summary="create"] .text-2xl').waitFor({ timeout: 60000 });
      check(await page.locator('[data-pimp-summary="create"] .text-2xl').textContent() === '30', 'preview: 30 products to create, 1 row with a missing image');
      await preview.locator('[data-pimp-start]').click();
      await page.locator('[data-pimp-job]').waitFor({ timeout: 30000 });
      await page.locator('[data-pimp-background]').waitFor({ state: 'visible', timeout: 30000 });
      check(true, 'confirming shows the background note');
      await context.close();   // 关掉浏览器：之后不再有任何页面请求
    }
    const jobA = fixture('newest').job;
    const doneA = await waitFor(async () => { const r = fixture('inspect', jobA); return { ok: r.status === 'done' && !r.busy, value: r }; }, 'job finished after the browser closed', 180000, 1000);
    check(doneA.counts.created === 30 && doneA.counts.error === 1 && clean(doneA, 30), 'job finished in the background after the browser closed; 30 created, 1 row error, no duplicates', doneA);
    check(doneA.verify && doneA.verify.checked === 30 && doneA.verify.problems === 0 && !doneA.active, 'post-import check ran in the background; job no longer queued', doneA.verify);
    {
      const { context, page } = await login();
      await page.goto('/admin/product_import.php');
      const row = page.locator('[data-pimp-history] tr', { hasText: 'browser-a' });
      await row.locator('[data-pimp-history-status="done"]').waitFor({ timeout: 20000 });
      await row.getByRole('button').click();
      const job = page.locator('[data-pimp-job]');
      await job.locator('[data-pimp-job-status][data-status="done"]').waitFor({ timeout: 20000 });
      const created = await job.locator('[data-pimp-count="created"] .text-xl').textContent();
      const errors = await job.locator('[data-pimp-count="error"] .text-xl').textContent();
      const problems = await job.locator('text=missing image').count();
      check(created === '30' && errors === '1' && problems === 1, 'reopened page shows the final result and the row error', { created, errors, problems });
      check(await job.locator('[data-pimp-verify]').evaluate((el) => el.classList.contains('bg-green-50')), 'reopened page shows the post-import check passed');
      await context.close();
    }

    // ── 2. 后台正在跑时打开页面 ──
    fixture('package', 'browser-b', '20');
    const jobB = fixture('queue', 'browser-b').job;
    const slow = startWorker(jobB, '350');
    await waitFor(async () => { const r = fixture('inspect', jobB); return { ok: r.busy && r.counts.created > 0, value: r }; }, 'slow worker started', 30000, 300);
    {
      const { context, page } = await login();
      await page.goto('/admin/product_import.php');
      const job = page.locator('[data-pimp-job]');
      await job.waitFor({ state: 'visible', timeout: 20000 });
      const shownId = await job.locator('span.text-xs.text-gray-400').first().textContent();
      check(shownId.includes(jobB), 'reopening the page opens the job that is still running', shownId);
      await page.locator('[data-pimp-background]').waitFor({ state: 'visible', timeout: 10000 });
      check((await page.locator('[data-pimp-background]').textContent()).includes('Running in the background'), 'page says the job is running in the background');
      const firstCount = Number(await job.locator('[data-pimp-count="created"] .text-xl').textContent());
      await job.locator('[data-pimp-job-status][data-status="done"]').waitFor({ timeout: 60000 });
      const finalCount = await job.locator('[data-pimp-count="created"] .text-xl').textContent();
      check(firstCount < 20 && finalCount === '20', 'page follows progress and shows the final result without user action', { firstCount, finalCount });
      await context.close();
    }
    const slowResult = await slow.done;
    const rB = fixture('inspect', jobB);
    check(slowResult.code === 0 && lastJson(slowResult.lines).result === 'ran' && clean(rB, 20, { status: 'done' }), 'the CLI worker owned the job; the page never wrote while it ran', { worker: lastJson(slowResult.lines), job: rB });

    // ── 3. 两个执行者同时跑 ──
    fixture('package', 'race', '24');
    const jobR = fixture('queue', 'race').job;
    const [w1, w2] = [startWorker(jobR, '120'), startWorker(jobR, '120')];
    const [r1, r2] = await Promise.all([w1.done, w2.done]);
    const results = [lastJson(r1.lines).result, lastJson(r2.lines).result].sort();
    const rR = fixture('inspect', jobR);
    check(JSON.stringify(results) === JSON.stringify(['busy', 'ran']) && clean(rR, 24, { status: 'done' }), 'two simultaneous workers on one job: one runs, the other gets busy; 24 products, no duplicates', { results, job: rR });

    fixture('package', 'pair-a', '12');
    fixture('package', 'pair-b', '12');
    const jobPA = fixture('queue', 'pair-a').job;
    const jobPB = fixture('queue', 'pair-b').job;
    const [d1, d2] = await Promise.all([startWorker('-', '150', '0', 'drain').done, startWorker('-', '150', '0', 'drain').done]);
    const [rPA, rPB] = [fixture('inspect', jobPA), fixture('inspect', jobPB)];
    check(d1.code === 0 && d2.code === 0 && clean(rPA, 12, { status: 'done' }) && clean(rPB, 12, { status: 'done' }), 'two background workers draining two jobs: each job done once, no duplicates', { a: rPA.counts, b: rPB.counts });

    // ── 4. 写进库后、存进度前退出；执行中被强杀 ──
    fixture('package', 'crash', '24');
    const jobC = fixture('queue', 'crash').job;
    const crashed = await startWorker(jobC, '0', '7').done;
    const midC = fixture('inspect', jobC);
    check(crashed.code === 3 && !midC.busy && midC.db_products_tagged === 7 && midC.job_created_products < 7, 'worker exited right after the 7th product write; the job file had not recorded it yet', { saved: midC.job_created_products, inDb: midC.db_products_tagged });
    const resumed = await startWorker(jobC, '0').done;
    const rC = fixture('inspect', jobC);
    check(resumed.code === 0 && clean(rC, 24, { status: 'done' }) && rC.job_created_media === rC.db_media_tagged, 'next worker resumed: 24 products, every row adopted once, no duplicate media', rC);

    fixture('package', 'kill', '24');
    const jobK = fixture('queue', 'kill').job;
    const victim = startWorker(jobK, '300');
    await waitFor(async () => { const r = fixture('inspect', jobK); return { ok: r.db_products_tagged >= 4, value: r.db_products_tagged }; }, 'worker made progress before the kill', 30000, 200);
    victim.child.kill('SIGKILL');
    const killed = await victim.done;
    const midK = fixture('inspect', jobK);
    check(killed.code !== 0 && !midK.busy && midK.status === 'running' && midK.active, 'worker was killed mid-run; lock released, job still queued', { code: killed.code, status: midK.status, inDb: midK.db_products_tagged });
    await startWorker('-', '0', '0', 'drain').done;
    const rK = fixture('inspect', jobK);
    check(clean(rK, 24, { status: 'done' }), 'after the kill the next background run finished the job without duplicates', rK);

    // ── 5. 失败行重试；执行中撤销；中断的更新 ──
    fixture('package', 'retry', '10');
    const jobT = fixture('queue', 'retry', 'break').job;
    await startWorker(jobT, '0').done;
    const t1 = fixture('inspect', jobT);
    check(t1.status === 'done' && t1.counts.failed === 1 && t1.counts.created === 9 && !t1.active, 'a row whose image disappeared after the preview fails; the rest are imported', t1.counts);
    fixture('restore', 'retry');
    const retried = fixture('retry', jobT);
    check(retried.result === 'queued', 'retry hands the failed row back to the background', retried);
    await startWorker('-', '0', '0', 'drain').done;
    const t2 = fixture('inspect', jobT);
    check(clean(t2, 10, { status: 'done' }) && t2.counts.failed === 0 && t2.verify.checked === 10, 'the retried row is imported once; the other nine are not written again', t2);

    fixture('package', 'undo', '24');
    const jobU = fixture('queue', 'undo').job;
    const running = startWorker(jobU, '250');
    await waitFor(async () => { const r = fixture('inspect', jobU); return { ok: r.busy && r.db_products_tagged >= 3, value: r.db_products_tagged }; }, 'worker running before undo', 30000, 200);
    const undoReq = fixture('undo', jobU);
    check(undoReq.result === 'queued' && undoReq.undo_requested, 'undo while another worker runs is queued, not executed concurrently', undoReq);
    const undoWorker = await running.done;
    const rU = fixture('inspect', jobU);
    check(lastJson(undoWorker.lines).result === 'undone' && rU.status === 'undone' && rU.db_products_tagged === 0 && rU.db_categories_tagged === 0
      && rU.db_media_tagged === 0 && rU.db_products_by_import_key === 0 && !rU.undo_requested && !rU.active, 'the running worker stopped and undid everything it had written', rU);

    fixture('rename', 'race', 'v2');
    const jobUp = fixture('queue', 'race').job;
    const before = fixture('titles', 'race').titles;
    const upCrash = await startWorker(jobUp, '0', '5').done;
    check(upCrash.code === 3, 'update job: worker exited right after the 5th update');
    await startWorker(jobUp, '0').done;
    const rUp = fixture('inspect', jobUp);
    check(rUp.status === 'done' && rUp.counts.updated === 24 && rUp.updated_files === 24 && rUp.duplicate_import_keys === 0, 'resumed update job: 24 updated, original values kept per product', rUp);
    fixture('undo', jobUp);
    const restored = fixture('titles', 'race').titles;
    check(JSON.stringify(restored) === JSON.stringify(before) && fixture('inspect', jobUp).undo_conflicts.length === 0, 'undo after the interrupted update restores all 24 titles', { first: restored[0] });

    // ── 6. 升级前的任务 ──
    fixture('legacy-done', jobPA);
    const legacyDone = fixture('inspect', jobPA);
    check(legacyDone.v === 1 && legacyDone.status === 'done', 'a job saved in the pre-upgrade format still loads', legacyDone.status);
    const legacyUndo = fixture('undo', jobPA);
    const rLU = fixture('inspect', jobPA);
    check(legacyUndo.result === 'undone' && rLU.status === 'undone' && rLU.db_products_by_import_key === 0, 'a pre-upgrade job can still be undone', rLU);

    fixture('package', 'legacy', '16');
    const lp = fixture('legacy-partial', 'legacy', '6', '4');
    check(lp.products_in_db === 10, 'pre-upgrade unfinished job: 6 rows saved, 4 more written but not saved', lp);
    await startWorker('-', '0', '0', 'drain').done;
    const l1 = fixture('inspect', lp.job);
    check(l1.status === 'running' && l1.v === 1 && l1.db_products_by_import_key === 10, 'the background worker does not start a pre-upgrade job on its own', l1);
    const resume = fixture('resume-legacy', lp.job);
    await startWorker('-', '0', '0', 'drain').done;
    const l2 = fixture('inspect', lp.job);
    check(resume.result === 'queued' && l2.status === 'done' && l2.db_products_by_import_key === 16 && l2.duplicate_import_keys === 0 && l2.job_created_products === 16,
      'after Continue the pre-upgrade job finishes; the 4 unsaved rows are adopted, not duplicated', l2);

    // ── 7. 建任务的人不再是超级管理员 ──
    fixture('package', 'blocked', '5');
    const jobX = fixture('queue', 'blocked').job;
    fixture('role', '3');
    await startWorker(jobX, '0').done;
    const rX = fixture('inspect', jobX);
    fixture('role', '1');
    check(rX.status === 'blocked' && rX.blocked_reason === 'admin_not_allowed' && rX.db_products_by_import_key === 0 && !rX.active, 'a job stops when its creator is no longer an active super admin', rX);

    await browser.close();
    report.passed = report.checks.every((c) => c.ok);
  } catch (error) {
    report.error = error.stack || error.message;
    console.error(error.stack || error.message);
  } finally {
    if (server) server.kill();
    await sleep(500);
    const logs = path.join(site, 'storage/logs');
    if (fs.existsSync(logs)) for (const f of fs.readdirSync(logs)) fs.copyFileSync(path.join(logs, f), path.join(outDir, 'site-' + f));
    removeSite(site);
    report.siteRemoved = !fs.existsSync(site);
    fs.writeFileSync(path.join(outDir, 'summary.json'), JSON.stringify(report, null, 2));
    console.log(JSON.stringify({ database, passed: report.passed, checks: report.checks.length, failed: report.checks.filter((c) => !c.ok).length, siteRemoved: report.siteRemoved }));
    process.exitCode = report.passed ? 0 : 1;
  }
})();
