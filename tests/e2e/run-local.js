const fs = require('fs');
const http = require('http');
const net = require('net');
const path = require('path');
const { spawn, spawnSync } = require('child_process');

const { createSite, removeSite } = require('./isolated-site');
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
let server = null;
let fixtureServer = null;
let playwright = null;
let setupAttempted = false;
let serverLog = '';
const localVideoSampleNames = ['blox-test-flower.mp4', 'blox-test-friday.mp4'];

function runPhp(args, env = process.env) {
  return spawnSync(php, args, { cwd: root, env, stdio: 'inherit' });
}

function persistServerLog() {
  if (!serverLog) return;
  fs.mkdirSync(path.dirname(serverLogPath), { recursive: true });
  fs.writeFileSync(serverLogPath, serverLog, 'utf8');
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
      fixtureServer = spawn(php, ['-S', `127.0.0.1:${fixturePort}`, 'tests/e2e/template-market-server.php'], {
        cwd: root,
        env: e2eEnv,
        stdio: ['ignore', 'ignore', 'pipe'],
      });
      await waitForServerAt(`http://127.0.0.1:${fixturePort}/template-market-fixture.php`);
    }

    // Use the same catch-all shape as the supported Nginx/Apache rules. Plain
    // `php -S -t .` returns 404 for `/en/foo.html`, so it cannot detect
    // regressions that only appear after a prefixed URL is handed to index.php.
    server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', '.', 'tests/e2e/router.php'], {
      cwd: root,
      env: e2eEnv,
      stdio: ['ignore', 'pipe', 'pipe'],
    });
    const collectLog = (chunk) => {
      serverLog = (serverLog + String(chunk)).slice(-20_000);
    };
    server.stdout.on('data', collectLog);
    server.stderr.on('data', collectLog);
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
    };
    if (process.env.BLOX_E2E_EXPECT_TESTS === '1') {
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
    if (exitCode !== 0 && serverLog) {
      console.error('\n=== PHP development server (last 20 KB) ===\n' + serverLog);
    }
    if (interrupted) exitCode = 130;
  } catch (error) {
    console.error(error instanceof Error ? error.message : String(error));
    if (serverLog) console.error(serverLog);
  } finally {
    if (server && !server.killed) {
      const stopped = new Promise((resolve) => {
        server.once('exit', resolve);
        setTimeout(resolve, 2_000);
      });
      server.kill();
      await stopped;
    }
    if (fixtureServer && !fixtureServer.killed) {
      fixtureServer.kill();
    }
    if (setupAttempted) {
      const restore = runPhp(['tests/smoke/setup.php', '--restore']);
      if (restore.status !== 0) exitCode = 1;
    }
    if (exitCode !== 0) persistServerLog();
    if (root) removeSite(root);
    process.removeListener('SIGINT', onInterrupt);
    process.exitCode = exitCode;
  }
  process.exitCode = exitCode;
}

main();
