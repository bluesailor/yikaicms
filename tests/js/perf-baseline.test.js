'use strict';

const assert = require('node:assert/strict');
const test = require('node:test');
const { parseProfileOutput, percentile, summarize, compare } = require('../../tools/perf-baseline-lib');

test('profile lines become flat numeric metrics', () => {
  const values = parseProfileOutput([
    '  [profile:home] cold {"wallUntilCanvasMs":1200,"htmlKB":410,"heapMB":null}',
    '[bigdoc:before] {"docKB":900,"rawSame":true}',
    '[bigdoc:large] previewPostKB=812 previewRoundTripMs=640.5',
    'unrelated [profile line',
    '[profile:page] warm {broken json',
  ].join('\r\n'));
  assert.deepEqual(values, {
    'profile:home.cold.wallUntilCanvasMs': 1200,
    'profile:home.cold.htmlKB': 410,
    'bigdoc:before.docKB': 900,
    'bigdoc:large.previewPostKB': 812,
    'bigdoc:large.previewRoundTripMs': 640.5,
  });
});

test('nearest-rank percentiles only return observed samples', () => {
  const sorted = [10, 20, 30, 40, 50];
  assert.equal(percentile(sorted, 50), 30);
  assert.equal(percentile(sorted, 95), 50);
  assert.equal(percentile([], 50), null);
});

test('summary keeps sample count and range per metric', () => {
  const summary = summarize([{ a: 3 }, { a: 1, b: 7 }, { a: 2 }]);
  assert.deepEqual(summary.a, { n: 3, median: 2, p95: 3, min: 1, max: 3 });
  assert.equal(summary.b.n, 1);
});

test('comparison flags a median above the old p95 only with enough samples', () => {
  const baseline = { a: { n: 5, median: 100, p95: 120 }, b: { n: 2, median: 100, p95: 110 } };
  const current = { a: { n: 5, median: 130, p95: 140 }, b: { n: 5, median: 200, p95: 210 } };
  const rows = Object.fromEntries(compare(baseline, current).map((row) => [row.key, row]));
  assert.equal(rows.a.review, true);
  assert.equal(rows.a.delta, 0.3);
  assert.equal(rows.b.review, false, 'baseline with 2 samples is too thin to call');
});
