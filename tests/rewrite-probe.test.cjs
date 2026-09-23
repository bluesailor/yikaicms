const {readFileSync} = require('node:fs');
const {runInNewContext} = require('node:vm');
const {webcrypto} = require('node:crypto');
const assert = require('node:assert/strict');
const code = readFileSync(require('node:path').join(__dirname, '../assets/js/rewrite-probe.js'), 'utf8');
async function check(fetch, crypto = webcrypto, probe = 'yikaiCheckRewrite', base = undefined) {
    const window = base === undefined ? {} : {YK_BASE: base};
    runInNewContext(code, {window, crypto, Uint8Array, AbortController, setTimeout, clearTimeout, fetch});
    return window[probe]();
}
// A server mounted at `base` strips the prefix before PHP answers, so the echoed path is site-relative.
function server(base, seen) {
    return async (url, options) => {
        assert.equal(options.redirect, 'error');
        assert.equal(options.cache, 'no-store');
        const parsed = new URL(url, 'http://site.test');
        seen.push(parsed.pathname);
        if (!parsed.pathname.startsWith(base + '/')) return {ok: false};
        return {ok: true, headers: {get: () => 'application/json'}, json: async () => ({probe: 'yikai-rewrite-v1', nonce: parsed.searchParams.get('__yk_route_probe'), path: parsed.pathname.slice(base.length)})};
    };
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
    const seen = [];
    assert.equal(await check(server('', seen), webcrypto, 'yikaiCheckHome'), true);
    assert.deepEqual(seen, ['/'], 'home probe asks for the site root only');
    seen.length = 0;
    assert.equal(await check(server('/sub', seen), webcrypto, 'yikaiCheckRewrite', '/sub'), true);
    assert.deepEqual(seen.sort(), ['/sub/contact.html', '/sub/en/contact.html'], 'subdirectory installs probe under the mount');
    seen.length = 0;
    assert.equal(await check(server('/sub', seen), webcrypto, 'yikaiCheckHome', '/sub'), true);
    assert.deepEqual(seen, ['/sub/']);
    // A panel placeholder page at the root is HTML, not the probe answer.
    assert.equal(await check(async () => ({ok: true, headers: {get: () => 'text/html'}}), webcrypto, 'yikaiCheckHome'), false);
    console.log('PASS: home probe, subdirectory mount, rewrite probe success, 404, soft 404, stale response, network failure, unavailable crypto and timeout');
})().catch(error => {console.error(error); process.exitCode = 1;});
