'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { once } = require('node:events');
const test = require('node:test');
const { startLoggedServer, logTail, stopServer } = require('../e2e/server-process');

test('server output larger than a pipe buffer is preserved, with a bounded console tail', async t => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'yikai-server-log-'));
  t.after(() => fs.rmSync(root, { recursive: true, force: true }));
  const log = path.join(root, 'server.log');
  const child = startLoggedServer(process.execPath, ['-e',
    "require('fs').writeSync(2, 'BEGIN' + 'x'.repeat(1024 * 1024) + 'END');"], {}, log);
  t.after(() => stopServer(child));
  assert.equal((await once(child, 'close'))[0], 0);
  const content = fs.readFileSync(log, 'utf8');
  assert.equal(content, 'BEGIN' + 'x'.repeat(1024 * 1024) + 'END');
  assert.equal(logTail(log).length, 20_000);
  assert.ok(logTail(log).endsWith('END'));
  assert.equal(logTail(log, 3), 'END');
  assert.equal(logTail(path.join(root, 'missing')), '');
});

test('server shutdown waits for close and can be repeated', async t => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'yikai-server-log-'));
  t.after(() => fs.rmSync(root, { recursive: true, force: true }));
  const child = startLoggedServer(process.execPath, ['-e', 'setInterval(() => {}, 1000)'], {}, path.join(root, 'server.log'));
  t.after(() => stopServer(child));
  await once(child, 'spawn');
  let closed = false;
  child.once('close', () => { closed = true; });
  await stopServer(child);
  assert.equal(closed, true);
  await stopServer(child);
  await stopServer(null);
});
