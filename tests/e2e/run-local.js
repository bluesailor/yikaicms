const fs = require('fs');
const http = require('http');
const net = require('net');
const path = require('path');
const { spawn, spawnSync } = require('child_process');

const { createSite, removeSite } = require('./isolated-site');
const { startLoggedServer, logTail, stopServer } = require('./server-process');
const sourceRoot = path.resolve(__dirname, '../..');
let root = '';
const php = process.env.PHP_BINARY || 'php';
let port = Number(process.env.BLOX_E2E_PORT || 0);
const portBase = Number(process.env.BLOX_E2E_PORT_BASE || 0);
const portRange = Number(process.env.BLOX_E2E_PORT_RANGE || 0);
let baseURL = '';
const runId = `${process.pid}-${Date.now()}`;
const requestedArgs = process.argv.slice(2);
const freeMode = requestedArgs.includes('--free');
const adminSmoke = requestedArgs.includes('--admin-smoke');
const permissionSmoke = requestedArgs.includes('--permission-smoke');
const languageArg = requestedArgs.find((arg) => /^--lang=(?:zh-CN|en|ja)$/.test(arg));
const smokeLang = languageArg ? languageArg.slice('--lang='.length) : 'zh-CN';
const playwrightArgs = requestedArgs.filter((arg) => arg !== '--free' && arg !== '--admin-smoke' && arg !== '--permission-smoke' && arg !== languageArg);
const outputDir = process.env.BLOX_E2E_OUTPUT_DIR
  || path.join(sourceRoot, 'test-results', `e2e-${runId}`);
const reportDir = process.env.BLOX_E2E_REPORT_DIR
  || path.join(sourceRoot, 'playwright-report', runId);
const serverLogPath = process.env.BLOX_E2E_SERVER_LOG
  || path.join(outputDir, 'php-server.log');
const expectTests = process.env.BLOX_E2E_EXPECT_TESTS === '1';
const jsonReportPath = path.join(outputDir, 'phase-results.json');
let server = null;
let fixtureServer = null;
let playwright = null;
let setupAttempted = false;
let temporaryServerLog = '';
let temporaryFixtureLog = '';
const localVideoSampleNames = ['blox-test-flower.mp4', 'blox-test-friday.mp4'];

function runPhp(args, env = process.env) {
  return spawnSync(php, args, { cwd: root, env, stdio: 'inherit' });
}

function persistServerLog() {
  for (const [source, target] of [
    [temporaryServerLog, serverLogPath],
    [temporaryFixtureLog, path.join(path.dirname(serverLogPath), 'template-server.log')],
  ]) {
    if (!source || !fs.existsSync(source)) continue;
    fs.mkdirSync(path.dirname(target), { recursive: true });
    fs.copyFileSync(source, target);
  }
  const netLogDir = root && path.join(root, 'storage/e2e-netlog');
  if (netLogDir && fs.existsSync(netLogDir)) {
    fs.cpSync(netLogDir, path.join(path.dirname(serverLogPath), 'chromium-netlog'), { recursive: true });
  }
}

function copyLocalVideoSamples() {
  const configured = String(process.env.BLOX_E2E_VIDEO_SAMPLES || '').trim();
  if (!configured) return;

  const sourceDir = path.resolve(configured);
  const targetDir = path.join(root, 'uploads', 'videos');
  fs.mkdirSync(targetDir, { recursive: true });
  for (const name of localVideoSampleNames) {
    const source = path.join(sourceDir, name);
    if (!fs.existsSync(source) || !fs.statSync(source).isFile()) {
      throw new Error(`Missing local video sample: ${source}`);
    }
    fs.copyFileSync(source, path.join(targetDir, name));
  }
  console.log(`Local video samples copied from: ${sourceDir}`);
}

function canListen(candidate) {
  return new Promise((resolve) => {
    const probe = net.createServer();
    probe.once('error', () => resolve(false));
    probe.listen(candidate, '127.0.0.1', () => probe.close(() => resolve(true)));
  });
}

async function choosePort() {
  if (port > 0) {
    if (!await canListen(port)) throw new Error(`BLOX_E2E_PORT ${port} is already in use`);
    return port;
  }
  const first = portBase > 0 ? portBase : 8080;
  const last = portBase > 0 && portRange > 0 ? portBase + portRange - 1 : 8099;
  for (let candidate = first; candidate <= last; candidate += 1) {
    // The adjacent fixture port must also be free. This keeps a phase's
    // main server and template fixture inside its own port window.
    if (await canListen(candidate) && await canListen(candidate + 1)) return candidate;
  }
  throw new Error(`No free local port found between ${first} and ${last}`);
}

function waitForServerAt(url, timeoutMs = 15_000) {
  const startedAt = Date.now();
  return new Promise((resolve, reject) => {
    const probe = () => {
      const request = http.get(url, (response) => {
        response.resume();
        if (response.statusCode && response.statusCode < 500) {
          resolve();
          return;
        }
        retry();
      });
      request.on('error', retry);
      request.setTimeout(1_000, () => request.destroy());
    };
    const retry = () => {
      if (Date.now() - startedAt >= timeoutMs) {
        reject(new Error(`PHP server did not become ready at ${baseURL}`));
        return;
      }
      setTimeout(probe, 250);
    };
    probe();
  });
}

function waitForServer(timeoutMs = 15_000) {
  return waitForServerAt(`${baseURL}/admin/login.php`, timeoutMs);
}

async function main() {
  let exitCode = 1;
  let interrupted = false;
  const onInterrupt = () => {
    interrupted = true;
    if (playwright && !playwright.killed) playwright.kill('SIGINT');
  };
  process.once('SIGINT', onInterrupt);
  try {
    root = createSite(sourceRoot);
    temporaryServerLog = path.join(root, 'storage/e2e-php-server.log');
    temporaryFixtureLog = path.join(root, 'storage/e2e-template-server.log');
    console.log(`Isolated test site: ${root}`);
    copyLocalVideoSamples();
    port = await choosePort();
    baseURL = `http://127.0.0.1:${port}`;
    const useTemplateFixture = process.env.BLOX_E2E_REMOTE !== '1';
    let fixturePort = 0;
    if (useTemplateFixture) {
      fixturePort = port + 1;
      while (!await canListen(fixturePort)) fixturePort += 1;
    }
    setupAttempted = true;
    const setup = runPhp(['tests/smoke/setup.php', `--lang=${smokeLang}`], {
      ...process.env,
      SMOKE_SITE_URL: baseURL,
      SMOKE_BLOX_ADVANCED: freeMode ? '0' : (process.env.SMOKE_BLOX_ADVANCED || '1'),
    });
    if (setup.status !== 0) throw new Error('Disposable smoke setup failed');
    const e2eEnv = { ...process.env };

    if (useTemplateFixture) {
      const fixtureQuery = process.env.BLOX_E2E_REMOTE_FAILURE === '1' ? '?mode=unavailable' : '';
      e2eEnv.YIKAI_BLOX_TEMPLATE_API_BASE = `http://127.0.0.1:${fixturePort}/template-market-fixture.php${fixtureQuery}`;
      fixtureServer = startLoggedServer(php, ['-S', `127.0.0.1:${fixturePort}`, 'tests/e2e/template-market-server.php'], {
        cwd: root,
        env: e2eEnv,
      }, temporaryFixtureLog);
      await waitForServerAt(`http://127.0.0.1:${fixturePort}/template-market-fixture.php`);
    }

    // Use the same catch-all shape as the supported Nginx/Apache rules. Plain
    // `php -S -t .` returns 404 for `/en/foo.html`, so it cannot detect
    // regressions that only appear after a prefixed URL is handed to index.php.
    server = startLoggedServer(php, ['-S', `127.0.0.1:${port}`, '-t', '.', 'tests/e2e/router.php'], {
      cwd: root,
      env: e2eEnv,
    }, temporaryServerLog);
    await waitForServer();

    if (adminSmoke || permissionSmoke) {
      for (const script of [adminSmoke && 'admin_pages.php', permissionSmoke && 'permission_matrix.php'].filter(Boolean)) {
        exitCode = runPhp(['tests/smoke/' + script], { ...process.env, SMOKE_BASE: baseURL }).status === 0 ? 0 : 1;
        if (exitCode !== 0) throw new Error(script + ' failed');
      }
      return;
    }

    const playwrightCli = require.resolve('@playwright/test/cli');
    const playwrightEnv = {
      ...e2eEnv,
      BLOX_E2E_BASE_URL: baseURL,
      BLOX_E2E_STORAGE_STATE: path.join(sourceRoot, 'test-results', `e2e-auth-${runId}.json`),
      BLOX_E2E_OUTPUT_DIR: outputDir,
      BLOX_E2E_REPORT_DIR: reportDir,
      SMOKE_BLOX_ADVANCED: freeMode ? '0' : (process.env.SMOKE_BLOX_ADVANCED || '1'),
      BLOX_E2E_SITE_LANG: smokeLang,
      ...(expectTests ? { BLOX_E2E_JSON_REPORT: jsonReportPath } : {}),
    };
    if (expectTests) {
      const listing = spawnSync(process.execPath, [playwrightCli, 'test', ...playwrightArgs, '--list'], {
        cwd: root,
        env: playwrightEnv,
        encoding: 'utf8',
      });
      const listingOutput = `${listing.stdout || ''}${listing.stderr || ''}`;
      const totalMatch = listingOutput.match(/Total:\s*(\d+)\s+tests?/i);
      const total = totalMatch ? Number(totalMatch[1]) : 0;
      if (listing.status !== 0 || total < 1) {
        console.error(`Expected at least one executable browser test, got ${total}.`);
        console.error(listingOutput.slice(-8_000));
        throw new Error('Browser phase selected no executable tests');
      }
      console.log(`Browser phase test list: ${total} test(s)`);
    }
    playwright = spawn(process.execPath, [playwrightCli, 'test', ...playwrightArgs], {
      cwd: root,
      env: playwrightEnv,
      stdio: 'inherit',
    });
    exitCode = await new Promise((resolve, reject) => {
      playwright.once('error', reject);
      playwright.once('exit', (code) => resolve(code === null ? 1 : code));
    });
    if (exitCode === 0 && expectTests) {
      // --list 只证明「选中了用例」。运行期全部 test.skip() 的 phase 同样退出 0，
      // 于是缺 env 或缺夹具会让整个 phase 空转成绿。浏览器门禁必须要求真的跑通过用例。
      let executed = -1;
      try {
        const report = JSON.parse(fs.readFileSync(jsonReportPath, 'utf8'));
        executed = Number(report && report.stats && report.stats.expected) || 0;
      } catch (error) {
        console.error(`Cannot read browser phase result report: ${jsonReportPath}`);
      }
      if (executed < 1) {
        console.error(executed < 0
          ? 'Browser phase produced no result report; refusing to count it as a pass.'
          : 'Browser phase executed 0 tests — every selected test was skipped at runtime.');
        console.error('A phase that runs nothing is not a passing phase; check its runtime skip conditions.');
        exitCode = 1;
      } else {
        console.log(`Browser phase executed: ${executed} test(s)`);
      }
    }
    if (exitCode !== 0) {
      console.error('\n=== PHP development server (last 20 KB) ===\n' + logTail(temporaryServerLog));
    }
    if (interrupted) exitCode = 130;
  } catch (error) {
    exitCode = 1;
    console.error(error instanceof Error ? error.message : String(error));
    console.error(logTail(temporaryServerLog));
  } finally {
    let serversStopped = true;
    for (const child of [server, fixtureServer]) {
      try { await stopServer(child); } catch (error) {
        serversStopped = false;
        exitCode = 1;
        console.error(error.message);
      }
    }
    if (setupAttempted && serversStopped) {
      const restore = runPhp(['tests/smoke/setup.php', '--restore']);
      if (restore.status !== 0) exitCode = 1;
    }
    persistServerLog();
    if (root && serversStopped) removeSite(root);
    process.removeListener('SIGINT', onInterrupt);
    process.exitCode = exitCode;
  }
  process.exitCode = exitCode;
}

main();
