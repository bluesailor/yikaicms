const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const { createSite, removeSite } = require('../e2e/isolated-site');

test('test snapshot copies pending edits but never uses site config or runtime data', () => {
    const source = fs.mkdtempSync(path.join(os.tmpdir(), 'yikai-e2e-fixture-'));
    let site;
    try {
        execFileSync('git', ['init', '--quiet', source]);
        const write = (file, value) => {
            fs.mkdirSync(path.dirname(path.join(source, file)), { recursive: true });
            fs.writeFileSync(path.join(source, file), value);
        };
        write('index.php', 'original');
        execFileSync('git', ['add', 'index.php'], { cwd: source });
        write('index.php', 'pending edit');
        write('new.php', 'new file');
        write('config/config.php', 'private');
        write('storage/live.db', 'live database');
        write('vendor/example.txt', 'shared dependency');
        site = createSite(source);
        assert.equal(fs.readFileSync(path.join(site, 'index.php'), 'utf8'), 'pending edit');
        assert.equal(fs.readFileSync(path.join(site, 'new.php'), 'utf8'), 'new file');
        assert.equal(fs.existsSync(path.join(site, 'config/config.php')), false);
        assert.deepEqual(fs.readdirSync(path.join(site, 'storage')), []);
        fs.mkdirSync(path.join(site, 'config'), { recursive: true });
        fs.writeFileSync(path.join(site, 'config/config.php'), 'test config');
        assert.equal(fs.readFileSync(path.join(source, 'config/config.php'), 'utf8'), 'private');
        removeSite(site);
        site = null;
        assert.equal(fs.readFileSync(path.join(source, 'vendor/example.txt'), 'utf8'), 'shared dependency');
    } finally {
        if (site) removeSite(site);
        removeSite(source);
    }
});

test('cleanup refuses non-test roots', () => {
    for (const target of [os.tmpdir(), process.cwd(), path.join(os.tmpdir(), 'unrelated'), path.join(os.tmpdir(), 'yikai-e2e-a', 'nested')]) {
        assert.throws(() => removeSite(target), /unsafe cleanup/);
    }
});
