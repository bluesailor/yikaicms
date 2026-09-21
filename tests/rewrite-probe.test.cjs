const {readFileSync} = require('node:fs');
const {runInNewContext} = require('node:vm');
const {webcrypto} = require('node:crypto');
const assert = require('node:assert/strict');
const code = readFileSync(require('node:path').join(__dirname, '../assets/js/rewrite-probe.js'), 'utf8');
async function check(fetch, crypto = webcrypto) {
    const window = {};
    runInNewContext(code, {window, crypto, Uint8Array, AbortController, setTimeout, clearTimeout, fetch});
    return window.yikaiCheckRewrite();
}
(async () => {
    assert.equal(await check(async (url, options) => {
        assert.equal(options.redirect, 'error');
        assert.equal(options.cache, 'no-store');
        const parsed = new URL(url, 'http://site.test');
        return {ok: true, headers: {get: () => 'application/json'}, json: async () => ({probe: 'yikai-rewrite-v1', nonce: parsed.searchParams.get('__yk_route_probe'), path: parsed.pathname})};
    }), true);
    assert.equal(await check(async () => ({ok: false})), false);
    assert.equal(await check(async () => ({ok: true, headers: {get: () => 'text/html'}})), false);
    assert.equal(await check(async () => ({ok: true, headers: {get: () => 'application/json'}, json: async () => ({probe: 'yikai-rewrite-v1', nonce: 'stale'})})), false);
    assert.equal(await check(async () => {throw new Error('blocked');}), false);
    assert.equal(await check(async () => {}, null), false);
    assert.equal(await check((_url, options) => new Promise((_resolve, reject) => options.signal.addEventListener('abort', () => reject(new Error('timeout'))))), false);
    console.log('PASS: rewrite probe success, 404, soft 404, stale response, network failure, unavailable crypto and timeout');
})().catch(error => {console.error(error); process.exitCode = 1;});
