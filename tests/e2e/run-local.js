const fs = require('fs');
const http = require('http');
const net = require('net');
const path = require('path');
const { spawn, spawnSync } = require('child_process');

const root = path.resolve(__dirname, '../..');
const php = process.env.PHP_BINARY || 'php';
let port = Number(process.env.BLOX_E2E_PORT || 0);
let baseURL = '';
const runId = `${process.pid}-${Date.now()}`;
const requestedArgs = process.argv.slice(2);
const freeMode = requestedArgs.includes('--free');
const languageArg = requestedArgs.find((arg) => /^--lang=(?:zh-CN|en|ja)$/.test(arg));
const smokeLang = languageArg ? languageArg.slice('--lang='.length) : 'zh-CN';
const playwrightArgs = requestedArgs.filter((arg) => arg !== '--free' && arg !== languageArg);
const outputDir = process.env.BLOX_E2E_OUTPUT_DIR
  || path.join(root, 'test-results', `e2e-${runId}`);
const reportDir = process.env.BLOX_E2E_REPORT_DIR
  || path.join(root, 'playwright-report', runId);
const serverLogPath = process.env.BLOX_E2E_SERVER_LOG
  || path.join(outputDir, 'php-server.log');
let server = null;
let playwright = null;
let setupAttempted = false;
let serverLog = '';

function runPhp(args, env = process.env) {
  return spawnSync(php, args, { cwd: root, env, stdio: 'inherit' });
}

function persistServerLog() {
  if (!serverLog) return;
  fs.mkdirSync(path.dirname(serverLogPath), { recursive: true });
  fs.writeFileSync(serverLogPath, serverLog, 'utf8');
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
  for (let candidate = 8080; candidate <= 8099; candidate += 1) {
    if (await canListen(candidate)) return candidate;
  }
  throw new Error('No free local port found between 8080 and 8099');
}

function waitForServer(timeoutMs = 15_000) {
  const startedAt = Date.now();
  return new Promise((resolve, reject) => {
    const probe = () => {
      const request = http.get(`${baseURL}/admin/login.php`, (response) => {
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

async function main() {
  let exitCode = 1;
  let interrupted = false;
  const onInterrupt = () => {
    interrupted = true;
    if (playwright && !playwright.killed) playwright.kill('SIGINT');
  };
  process.once('SIGINT', onInterrupt);
  try {
    port = await choosePort();
    baseURL = `http://127.0.0.1:${port}`;
    setupAttempted = true;
    const setup = runPhp(['tests/smoke/setup.php', `--lang=${smokeLang}`], {
      ...process.env,
      SMOKE_SITE_URL: baseURL,
      SMOKE_BLOX_ADVANCED: freeMode ? '0' : (process.env.SMOKE_BLOX_ADVANCED || '1'),
    });
    if (setup.status !== 0) throw new Error('Disposable smoke setup failed');

    server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', '.'], {
      cwd: root,
      env: process.env,
      stdio: ['ignore', 'pipe', 'pipe'],
    });
    const collectLog = (chunk) => {
      serverLog = (serverLog + String(chunk)).slice(-20_000);
    };
    server.stdout.on('data', collectLog);
    server.stderr.on('data', collectLog);
    await waitForServer();

    const playwrightCli = require.resolve('@playwright/test/cli');
    playwright = spawn(process.execPath, [playwrightCli, 'test', ...playwrightArgs], {
      cwd: root,
      env: {
        ...process.env,
        BLOX_E2E_BASE_URL: baseURL,
        BLOX_E2E_STORAGE_STATE: path.join(root, 'test-results', `e2e-auth-${runId}.json`),
        BLOX_E2E_OUTPUT_DIR: outputDir,
        BLOX_E2E_REPORT_DIR: reportDir,
        SMOKE_BLOX_ADVANCED: freeMode ? '0' : (process.env.SMOKE_BLOX_ADVANCED || '1'),
        BLOX_E2E_SITE_LANG: smokeLang,
      },
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
    if (setupAttempted) {
      const restore = runPhp(['tests/smoke/setup.php', '--restore']);
      if (restore.status !== 0) exitCode = 1;
    }
    if (exitCode !== 0) persistServerLog();
    process.removeListener('SIGINT', onInterrupt);
  }
  process.exitCode = exitCode;
}

main();
