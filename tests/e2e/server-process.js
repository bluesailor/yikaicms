'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { spawn } = require('node:child_process');

function startLoggedServer(command, args, options, logPath) {
  fs.mkdirSync(path.dirname(logPath), { recursive: true });
  const fd = fs.openSync(logPath, 'w');
  try {
    // Direct file output cannot fill an unread pipe, and survives Playwright
    // clearing its output directory at startup. Logs live in the temporary site.
    return spawn(command, args, { ...options, stdio: ['ignore', fd, fd] });
  } finally {
    fs.closeSync(fd);
  }
}

function logTail(logPath, limit = 20_000) {
  if (!logPath || !fs.existsSync(logPath)) return '';
  const fd = fs.openSync(logPath, 'r');
  try {
    const size = fs.fstatSync(fd).size;
    const buffer = Buffer.alloc(Math.min(size, limit));
    const count = fs.readSync(fd, buffer, 0, buffer.length, Math.max(0, size - limit));
    return buffer.subarray(0, count).toString('utf8');
  } finally {
    fs.closeSync(fd);
  }
}

function stopServer(child, timeoutMs = 5_000) {
  if (!child || !child.pid || child.exitCode !== null || child.signalCode !== null) return Promise.resolve();
  return new Promise((resolve, reject) => {
    const done = () => { clearTimeout(timer); resolve(); };
    const timer = setTimeout(() => {
      child.removeListener('close', done);
      reject(new Error(`Test server ${child.pid} did not stop; retaining its temporary site`));
    }, timeoutMs);
    child.once('close', done);
    child.kill();
  });
}

module.exports = { startLoggedServer, logTail, stopServer };
