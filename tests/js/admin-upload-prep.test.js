/**
 * assets/js/admin-upload-prep.js：POST 到 /admin/upload.php 的图片先在浏览器里压缩。
 * vm 沙箱 + Node 自带的 FormData / Blob / File；BloxMediaClient 用桩代替真实的 canvas 压缩。
 */

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SRC = fs.readFileSync(
    path.join(__dirname, '..', '..', 'assets', 'js', 'admin-upload-prep.js'),
    'utf8'
);

function setup({ prepareImage } = {}) {
    const calls = [];
    const window = {
        fetch(input, init) {
            calls.push({ input, init });
            return Promise.resolve({ ok: true });
        },
        BloxMediaClient: {
            prepareImage: prepareImage || ((file) => Promise.resolve(new Blob(['small'], { type: file.type }))),
        },
    };
    vm.runInNewContext(SRC, { window, FormData, Blob, File, Promise, Array, Object, String });
    return { window, calls };
}

function photo(name = 'IMG_0001.jpg', type = 'image/jpeg', size = 2048) {
    return new File([new Uint8Array(size)], name, { type });
}

test('image fields posted to upload.php are compressed; other fields and order are kept', async () => {
    const { window, calls } = setup();
    const body = new FormData();
    body.append('type', 'images');
    body.append('file', photo());
    body.append('_token', 'abc');

    await window.fetch('/admin/upload.php', { method: 'POST', body, headers: { 'X-Test': '1' } });

    assert.strictEqual(calls.length, 1);
    const sent = calls[0].init.body;
    assert.notStrictEqual(sent, body, 'a rebuilt FormData is sent');
    assert.deepStrictEqual([...sent.keys()], ['type', 'file', '_token']);
    assert.strictEqual(sent.get('type'), 'images');
    assert.strictEqual(sent.get('_token'), 'abc');
    assert.strictEqual(sent.get('file').name, 'IMG_0001.jpg', 'original file name is kept for the extension check');
    assert.strictEqual(sent.get('file').size, 5);
    assert.deepStrictEqual(calls[0].init.headers, { 'X-Test': '1' });
});

test('query strings and absolute same-origin URLs still match the endpoint', async () => {
    const { window, calls } = setup();
    for (const url of ['/sub/admin/upload.php?type=image', 'https://example.com/admin/upload.php']) {
        const body = new FormData();
        body.append('file', photo());
        await window.fetch(url, { method: 'POST', body });
    }
    assert.ok(calls.every((c) => c.init.body.get('file').size === 5));
});

test('other endpoints, GET requests, non-image files and GIF/SVG pass through untouched', async () => {
    const { window, calls } = setup();
    const media = new FormData();
    media.append('file', photo());
    await window.fetch('/admin/media_api.php?action=upload', { method: 'POST', body: media });
    await window.fetch('/admin/upload.php');

    const doc = new FormData();
    doc.append('file', new File(['%PDF'], 'spec.pdf', { type: 'application/pdf' }));
    await window.fetch('/admin/upload.php', { method: 'POST', body: doc });

    const gif = new FormData();
    gif.append('file', photo('anim.gif', 'image/gif'));
    await window.fetch('/admin/upload.php', { method: 'POST', body: gif });

    assert.strictEqual(calls[0].init.body, media);
    assert.strictEqual(calls[1].init, undefined);
    assert.strictEqual(calls[2].init.body, doc);
    assert.strictEqual(calls[3].init.body, gif);
});

test('a failing compressor falls back to the original file', async () => {
    const { window, calls } = setup({ prepareImage: () => Promise.reject(new Error('decode failed')) });
    const body = new FormData();
    const original = photo('big.png', 'image/png', 4096);
    body.append('file', original);
    await window.fetch('/admin/upload.php', { method: 'POST', body });
    assert.strictEqual(calls[0].init.body.get('file').size, 4096);
    assert.strictEqual(calls[0].init.body.get('file').name, 'big.png');
});

test('prepareAll keeps order and processes files one at a time', async () => {
    let active = 0;
    let maxActive = 0;
    const { window } = setup({
        prepareImage: (file) => {
            active++;
            maxActive = Math.max(maxActive, active);
            return new Promise((resolve) => setTimeout(() => { active--; resolve(new Blob([file.name], { type: file.type })); }, 5));
        },
    });
    const out = await window.YkUploadPrep.prepareAll([photo('a.jpg'), photo('b.webp', 'image/webp'), photo('c.gif', 'image/gif')]);
    assert.strictEqual(out.length, 3);
    assert.strictEqual(maxActive, 1);
    assert.strictEqual(await out[0].text(), 'a.jpg');
    assert.strictEqual(await out[1].text(), 'b.webp');
    assert.strictEqual(out[2].name, 'c.gif', 'GIF is returned untouched');
});

test('loading twice does not double-wrap fetch', () => {
    const { window } = setup();
    const once = window.fetch;
    vm.runInNewContext(SRC, { window, FormData, Blob, File, Promise, Array, Object, String });
    assert.strictEqual(window.fetch, once);
});
