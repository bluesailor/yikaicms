const { test } = require('node:test');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');

test('PHP stdin exception retains the original diagnostic without STDERR constant', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'yikai-error-handler-'));
  const source = path.resolve(__dirname, '../../includes/ErrorHandler.php').replaceAll('\\', '/');
  try {
    const result = spawnSync(process.env.PHP_BINARY || 'php', [], {
      input: `<?php define('ROOT_PATH', '${root.replaceAll('\\', '/')}'); require '${source}'; ErrorHandler::install(); throw new RuntimeException('stdin-regression-marker');`,
      encoding: 'utf8',
    });
    assert.equal(result.status, 1, result.stderr);
    assert.match(result.stderr, /stdin-regression-marker/);
    assert.doesNotMatch(result.stderr, /Undefined constant|STDERR/);
    const directory = path.join(root, 'storage/logs');
    const log = fs.readFileSync(path.join(directory, fs.readdirSync(directory)[0]), 'utf8');
    assert.match(log, /stdin-regression-marker/);
    assert.doesNotMatch(log, /\[FATAL\]/);
  } finally {
    fs.rmSync(root, { recursive: true, force: true });
  }
});
