const { test } = require('node:test');
const assert = require('node:assert/strict');
const { requestForm } = require('../e2e/request-form');

test('preview request reader preserves multipart JSON, repeated fields and CSRF', async () => {
  const document = JSON.stringify({ schema: 1, sections: [{ text: '中文😀 "quote"\nnext', html: '<a href="/a?x=1&y=2">Link</a>' }] });
  const form = new FormData();
  form.set('action', 'preview');
  form.set('blocks_data', document);
  form.set('_token', 'fixture-token');
  form.append('tag', 'first');
  form.append('tag', 'second');
  form.set('file', new Blob(['ignored']), 'image.txt');
  const request = new Request('http://127.0.0.1/preview', { method: 'POST', body: form });
  const raw = Buffer.from(await request.arrayBuffer());
  const decoded = requestForm({ postDataBuffer: () => raw, headers: () => Object.fromEntries(request.headers) });
  assert.equal(decoded.get('blocks_data'), document);
  assert.equal(decoded.get('action'), 'preview');
  assert.equal(decoded.get('_token'), 'fixture-token');
  assert.deepEqual(decoded.getAll('tag'), ['first', 'second']);
  assert.equal(decoded.has('file'), false);
});

test('request reader retains urlencoded and empty request support', () => {
  const raw = new URLSearchParams({ action: 'save_draft', blocks_data: '{"title":"中文"}', _token: 'fixture-token' }).toString();
  const decoded = requestForm({ postDataBuffer: () => Buffer.from(raw), headers: () => ({ 'content-type': 'application/x-www-form-urlencoded' }) });
  assert.equal(decoded.get('action'), 'save_draft');
  assert.equal(decoded.get('blocks_data'), '{"title":"中文"}');
  assert.equal(requestForm({ postDataBuffer: () => null }).get('action'), null);
});
