'use strict';

const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const path = require('node:path');
const test = require('node:test');

for (const args of [['0'], ['5001'], ['1', '--remote']]) {
  test(`transport probe rejects unsafe arguments before starting a server: ${args.join(' ')}`, () => {
    const result = spawnSync(process.execPath,
      [path.resolve(__dirname, '../../tools/browser-transport-probe.js'), ...args],
      { encoding: 'utf8', timeout: 10_000 });
    assert.equal(result.error, undefined);
    assert.equal(result.status, 1);
    assert.match(result.stderr, /Request count must|Only --php is supported/);
    assert.doesNotMatch(result.stdout, /Local-only transport artifacts/);
  });
}
