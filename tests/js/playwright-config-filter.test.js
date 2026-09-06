'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');

test('spec filter matches one basename at a path boundary', () => {
  const previous = process.env.BLOX_E2E_SPEC_FILTER;
  process.env.BLOX_E2E_SPEC_FILTER = 'blox-page.spec.js';
  delete require.cache[require.resolve('../../playwright.config')];
  const config = require('../../playwright.config');
  const pattern = config.testMatch;

  assert.ok(pattern instanceof RegExp);
  assert.equal(pattern.test('/workspace/tests/e2e/blox-page.spec.js'), true);
  assert.equal(pattern.test('C:\\workspace\\tests\\e2e\\blox-page.spec.js'), true);
  assert.equal(pattern.test('/workspace/tests/e2e/prefix-blox-page.spec.js'), false);
  assert.equal(pattern.test('/workspace/tests/e2e/blox-page.spec.js.bak'), false);

  if (previous === undefined) delete process.env.BLOX_E2E_SPEC_FILTER;
  else process.env.BLOX_E2E_SPEC_FILTER = previous;
});
