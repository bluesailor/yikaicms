const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const { styleKeys } = require('../../assets/js/blox-style-clipboard.js');

test('style clipboard excludes background media assets', () => {
    const controls = [
        { key: 'bg_color', type: 'color', tab: 'style' },
        { key: 'bg_image', type: 'image', tab: 'style' },
        { key: 'bg_video', type: 'video_url', tab: 'style' },
    ];
    assert.deepEqual(styleKeys('text', { controls }), ['bg_color']);
});

test('dot navigation measures the bottom of a header below the admin bar', () => {
    const element = (top, height) => ({
        getBoundingClientRect: () => ({ top, height, bottom: top + height }),
    });
    const bar = element(0, 32);
    const header = element(32, 64);
    let offset;
    const listeners = {};
    const context = {
        window: {
            location: { hash: '' },
            getComputedStyle: () => ({ position: 'fixed' }),
            addEventListener: (name, callback) => { listeners[name] = callback; },
        },
        document: {
            readyState: 'complete',
            querySelector: (selector) => {
                if (selector === '[data-yk-dotnav]') return { querySelectorAll: () => [{ getAttribute: () => 'section' }] };
                if (selector === '#ik-adminbar') return bar;
                if (selector === '.yk-blox-header') return header;
                return null;
            },
            getElementById: () => null,
            documentElement: { classList: { add() {} }, style: { setProperty: (_, value) => { offset = value; } } },
        },
    };
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../assets/js/blox-dot-nav.js'), 'utf8'), context);
    assert.equal(offset, '104px');
});
