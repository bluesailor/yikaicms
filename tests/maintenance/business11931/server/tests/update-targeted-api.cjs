const fs = require('fs');
const path = require('path');
const os = require('os');
const net = require('net');
const assert = require('assert/strict');
const { spawn } = require('child_process');
const source = path.resolve(__dirname, '..');
const candidate = path.resolve(process.argv[2]);
const php = process.env.PHP_BINARY || 'D:/phpstudy_pro/Extensions/php/php8.0.2nts/php.exe';
const root = fs.mkdtempSync(path.join(os.tmpdir(), 'yikai-target-api-'));
let server, count = 0;
async function main() {
    fs.mkdirSync(path.join(root, 'api/update'), { recursive: true });
    fs.mkdirSync(path.join(root, 'data'));
    for (const file of ['check.php', '_channel.php', '_targeting.php', '_signature.php', '_installs.php', '_install-domain.php']) {
        fs.copyFileSync(path.join(source, 'api/update', file), path.join(root, 'api/update', file));
    }
    const official = JSON.parse(fs.readFileSync(path.join(source, 'data/releases.json'))).releases.find((r) => r.version === '1.19.3');
    const entry = JSON.parse(fs.readFileSync(path.join(candidate, 'release-entry.json')));
    const targetHost = entry.targeting.domains[0];
    const writeCatalog = (entries = [entry, official], latest = '1.19.3') => {
        fs.writeFileSync(path.join(root, 'data/releases.json'), JSON.stringify({ latest, releases: entries }));
        fs.writeFileSync(path.join(root, 'data/release-registry.json'), JSON.stringify({ schema: 1, versions: Object.fromEntries(entries.map((r) => [r.version, { channel: r.channel }])) }));
    };
    writeCatalog();
    const probe = net.createServer();
    await new Promise((r) => probe.listen(0, '127.0.0.1', r)); const port = probe.address().port;
    await new Promise((r) => probe.close(r));
    server = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', root], { cwd: root, stdio: 'ignore' });
    const base = `http://127.0.0.1:${port}/api/update/check.php`;
    const request = { version: '1.19.3', channel: 'stable', domain: targetHost, site_name: '', php: '8.0.2', t: String(Math.floor(Date.now() / 1000)) };
    async function check(query, expected, hasUpdate = false) {
        const response = await fetch(base + '?' + new URLSearchParams(query));
        assert.equal(response.status, 200);
        const data = await response.json(); assert.equal(data.code, 0);
        assert.equal(data.data.latest_version, expected, JSON.stringify(query));
        assert.equal(data.data.has_update, hasUpdate, 'has_update');
        if (expected === '1.19.3.1') {
            assert.equal(data.data.hash, entry.hash); assert.equal(data.data.sig, entry.sig);
            assert.equal(data.data.delta.from, '1.19.3'); assert.equal(data.data.delta.hash, entry.hash);
            assert(data.data.changelog.startsWith('【Business'));
        } else {
            assert(!JSON.stringify(data).includes(entry.package), 'Target package leaked');
        }
        count++;
    }
    let ready = false;
    for (let i = 0; i < 40; i++) {
        try { await fetch(base); ready = true; break; } catch { await new Promise((r) => setTimeout(r, 100)); }
    }
    assert(ready);
    for (const channel of ['stable', 'beta']) {
        await check({ ...request, channel }, '1.19.3.1', true);
        await check({ ...request, channel, domain: 'www.' + targetHost }, '1.19.3.1', true);
        for (const domain of ['', 'example.com', 'sub.' + targetHost, targetHost + '.evil']) await check({ ...request, channel, domain }, '1.19.3');
        for (const auto of ['0', '1', '']) await check({ ...request, channel, auto }, '1.19.3');
    }
    const health = { ...request }; delete health.channel;
    await check(health, '1.19.3');
    await check({ ...request, version: '1.19.3.1' }, '1.19.3');
    await check({ ...request, version: '1.19.2' }, '1.19.3', true);
    await check({ ...request, version: '1.19.3-customer' }, '1.19.3');
    // A signed future release is not fabricated; route comparison is covered by PHP policy tests.
    const expired = structuredClone(entry); expired.targeting.expires_at = '2020-01-01T00:00:00Z';
    writeCatalog([expired, official]); await check(request, '1.19.3');
    writeCatalog();
    const post = await fetch(base, { method: 'POST', body: new URLSearchParams(request) });
    const body = await post.json(); assert.equal(body.data.has_update, false); count++;
    fs.writeFileSync(path.join(candidate, 'verification/api-results.json'), JSON.stringify({ count, status: 'passed', php }, null, 2));
    console.log(`TARGETED API HTTP OK (${count} scenarios; no production registration written)`);
}
main().catch((e) => { console.error(e); process.exitCode = 1; }).finally(async () => {
    if (server) { const stopped = new Promise((r) => server.once('exit', r)); server.kill(); await stopped; }
    if (path.dirname(root) !== path.resolve(os.tmpdir()) || !path.basename(root).startsWith('yikai-target-api-')) throw new Error('Unsafe cleanup');
    fs.rmSync(root, { recursive: true, force: true, maxRetries: 3 });
});
