const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function fixture() {
    const probes = [];
    const warning = { hidden: true, textContent: '' };
    const dimensions = { textContent: '' };
    const samples = { hidden: true };
    const images = [16, 32, 48].map(size => ({ size, removeAttribute() { this.src = ''; } }));
    const input = { value: '', setAttribute() {}, addEventListener(name, fn) { this[name] = fn; } };
    const attributes = { 'data-initial-preview': '0', 'data-shape-message': 'Not square', 'data-small-message': 'Too small', 'data-error-message': 'Unavailable', 'data-size-message': ':width x :height' };
    const host = {
        setAttribute: (key, value) => { attributes[key] = value; },
        getAttribute: key => attributes[key],
        querySelector: key => ({ '[data-favicon-warning]': warning, '[data-favicon-dimensions]': dimensions, '[data-favicon-samples]': samples })[key],
        querySelectorAll: () => images
    };
    const document = { getElementById: key => ({ faviconPreview: host, input_site_favicon: input })[key] };
    const window = { Image: function () { probes.push(this); }, clearTimeout() {}, setTimeout(fn) { input.pending = fn; } };
    vm.runInNewContext(fs.readFileSync(path.resolve(__dirname, '../../assets/js/admin-favicon-preview.js'), 'utf8'), { window, document });
    return { api: window.YikaiFaviconPreview, input, warning, dimensions, samples, images, probes };
}

test('wide logo warns while preserving all three previews', () => {
    const f = fixture();
    f.input.value = '/uploads/logo.png'; f.input.change();
    Object.assign(f.probes[0], { naturalWidth: 320, naturalHeight: 96 }); f.probes[0].onload();
    assert.equal(f.warning.textContent, 'Not square');
    assert.equal(f.warning.hidden, false);
    assert.equal(f.dimensions.textContent, '320 x 96');
    assert.equal(f.samples.hidden, false);
    assert.ok(f.images.every(image => image.src === '/uploads/logo.png'));
});

test('square icons including 16px ICO frames are accepted', () => {
    const f = fixture();
    for (const size of [16, 32, 48, 512]) assert.equal(f.api.issue(size, size), '');
    assert.equal(f.api.issue(8, 8), 'small');
    assert.equal(f.api.issue(0, 0), 'error');
});

test('typing refreshes after debounce and blur refreshes immediately', () => {
    const f = fixture();
    f.input.value = '/typed.png'; f.input.input();
    assert.equal(f.probes.length, 0);
    f.input.pending();
    assert.equal(f.probes[0].src, '/typed.png');
    f.input.value = ''; f.input.blur();
    assert.equal(f.samples.hidden, true);
    assert.equal(f.warning.hidden, true);
});

test('late image responses cannot restore stale warnings or previews', () => {
    const f = fixture();
    f.input.value = '/old.png'; f.api.refresh();
    f.input.value = '/new.png'; f.api.refresh();
    Object.assign(f.probes[1], { naturalWidth: 32, naturalHeight: 32 }); f.probes[1].onload();
    Object.assign(f.probes[0], { naturalWidth: 300, naturalHeight: 80 }); f.probes[0].onload();
    f.probes[0].onerror();
    assert.equal(f.warning.hidden, true);
    assert.ok(f.images.every(image => image.src === '/new.png'));
});

test('empty, failed and unsupported URLs clear old preview state', () => {
    const f = fixture();
    f.input.value = 'javascript:alert(1)'; f.api.refresh();
    assert.equal(f.probes.length, 0);
    assert.equal(f.warning.textContent, 'Unavailable');
    f.input.value = '/missing.png'; f.api.refresh(); f.probes[0].onerror();
    assert.equal(f.samples.hidden, true);
    f.input.value = ''; f.api.refresh();
    assert.equal(f.warning.hidden, true);
    assert.equal(f.dimensions.textContent, '');
});
