'use strict';

// A bounded localhost-only diagnostic, not a CMS test or release gate.
const fs = require('node:fs');
const http = require('node:http');
const net = require('node:net');
const path = require('node:path');
const { chromium } = require('playwright');
const { startLoggedServer, stopServer } = require('../tests/e2e/server-process');

async function startPhp(outputDir) {
  const reservation = net.createServer();
  await new Promise((resolve, reject) => {
    reservation.once('error', reject);
    reservation.listen(0, '127.0.0.1', resolve);
  });
  const port = reservation.address().port;
  await new Promise(resolve => reservation.close(resolve));
  const router = path.join(outputDir, 'router.php');
  fs.writeFileSync(router, `<?php declare(strict_types=1);
header('Cache-Control: no-store');
header('Content-Type: ' . ($_SERVER['REQUEST_URI'] === '/' ? 'text/html' : 'text/plain'));
echo $_SERVER['REQUEST_URI'] === '/' ? '<!doctype html><title>Local transport probe</title>' : 'ok';
`);
  const log = path.join(outputDir, 'php-server.log');
  const child = startLoggedServer(process.env.PHP_BINARY || 'php',
    ['-S', `127.0.0.1:${port}`, '-t', outputDir, router], {}, log);
  let spawnError;
  child.once('error', error => { spawnError = error; });
  const baseURL = `http://127.0.0.1:${port}`;
  try {
    for (let attempt = 0; attempt < 50; attempt++) {
      if (spawnError) throw spawnError;
      try {
        const response = await fetch(baseURL, { signal: AbortSignal.timeout(500) });
        if (response.ok && (await response.text()).includes('Local transport probe')) {
          return { baseURL, child, log };
        }
      } catch { /* Only the server readiness probe is retried. */ }
      await new Promise(resolve => setTimeout(resolve, 100));
    }
    throw new Error('Local PHP transport server did not start');
  } catch (error) {
    await stopServer(child);
    throw error;
  }
}

async function sample(mode, requests, outputDir) {
  let connections = 0;
  const server = http.createServer((req, res) => {
    res.setHeader('Cache-Control', 'no-store');
    if (mode === 'close') res.setHeader('Connection', 'close');
    res.setHeader('Content-Type', req.url === '/' ? 'text/html' : 'text/plain');
    res.end(req.url === '/' ? '<!doctype html><title>Local transport probe</title>' : 'ok');
  });
  server.on('connection', () => { connections++; });
  let browser, phpServer;
  try {
    if (mode === 'php') phpServer = await startPhp(outputDir);
    else await new Promise((resolve, reject) => {
        server.once('error', reject);
        server.listen(0, '127.0.0.1', resolve);
      });
    const baseURL = phpServer?.baseURL || `http://127.0.0.1:${server.address().port}`;
    browser = await chromium.launch({ args: [
      `--log-net-log=${path.join(outputDir, `${mode}-netlog.json`)}`,
    ] });
    const page = await browser.newPage();
    const failures = [];
    page.on('requestfailed', request => failures.push({
      url: request.url(), error: request.failure()?.errorText,
    }));
    await page.goto(baseURL);
    const result = await page.evaluate(async count => {
      let next = 0, passed = 0, failed = 0;
      const start = performance.now();
      // Each request is made once. Six workers match HTTP/1.1 browser fanout.
      await Promise.all(Array.from({ length: 6 }, async () => {
        while (next < count) {
          const id = next++;
          try {
            const response = await fetch(`/asset?id=${id}`, {
              cache: 'no-store', signal: AbortSignal.timeout(5000),
            });
            if (!response.ok || await response.text() !== 'ok') throw new Error('Bad response');
            passed++;
          } catch { failed++; }
        }
      }));
      return { passed, failed, durationMs: Math.round(performance.now() - start) };
    }, requests);
    if (phpServer) connections = (fs.readFileSync(phpServer.log, 'utf8').match(/ Accepted/g) || []).length;
    return { mode, requests, connections, ...result, failures };
  } finally {
    try { if (browser) await browser.close(); } finally {
      server.closeAllConnections();
      await new Promise(resolve => server.close(resolve));
      if (phpServer) await stopServer(phpServer.child);
    }
  }
}

async function main() {
  const requests = Number(process.argv[2] || 3000);
  if (!Number.isInteger(requests) || requests < 1 || requests > 5000) {
    throw new Error('Request count must be an integer between 1 and 5000');
  }
  if (process.argv[3] && process.argv[3] !== '--php') throw new Error('Only --php is supported');
  const outputDir = path.resolve(__dirname, '../test-results', `transport-${Date.now()}`);
  fs.mkdirSync(outputDir, { recursive: true });
  console.log(`Local-only transport artifacts: ${outputDir}`);
  const results = [];
  for (const mode of process.argv[3] === '--php' ? ['php'] : ['keep-alive', 'close']) {
    const result = await sample(mode, requests, outputDir);
    results.push(result);
    fs.writeFileSync(path.join(outputDir, 'results.json'), JSON.stringify(results, null, 2));
    console.log(JSON.stringify({ ...result, failures: result.failures.length }));
  }
  if (results.some(result => result.failed > 0)) process.exitCode = 1;
}

main().catch(error => { console.error(error.message); process.exitCode = 1; });
