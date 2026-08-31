const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../marketplace/themes/business/assets/js/header.js'), 'utf8');

function run(rect = null, mainTop = 80, toolbarHeight = 0) {
    const classes = new Set(['nav-solid', 'shadow-lg']);
    const listeners = {};
    const header = { style: {}, classList: { toggle(name, enabled) { enabled ? classes.add(name) : classes.delete(name); } } };
    const main = {
        querySelector: () => rect ? { getBoundingClientRect: () => rect } : null,
        getBoundingClientRect: () => ({ top: mainTop }),
    };
    vm.runInNewContext(source, {
        document: { querySelector: (selector) => selector === 'main' ? main
            : selector === '[data-business-home-header]' ? header
                : toolbarHeight ? { getBoundingClientRect: () => ({ height: toolbarHeight }) } : null },
        window: { addEventListener: (name, callback) => { listeners[name] = callback; } },
    });
    return { classes, listeners, header };
}

test('missing banner keeps readable solid navigation', () => {
    const { classes } = run();
    assert.ok(classes.has('nav-solid'));
    assert.ok(!classes.has('nav-transparent'));
});

test('leading visible banner preserves the Business overlay', () => {
    const { classes } = run({ top: 80, width: 1440, height: 650 });
    assert.ok(classes.has('nav-transparent'));
    assert.ok(!classes.has('nav-solid'));
});

test('banner below ordinary content cannot make the header transparent', () => {
    assert.ok(run({ top: 450, width: 1440, height: 650 }).classes.has('nav-solid'));
});

test('mobile-hidden banner and resize back restore the correct presentation', () => {
    const rect = { top: 80, width: 1440, height: 650 };
    const { classes, listeners } = run(rect);
    rect.height = 0;
    listeners.resize();
    assert.ok(classes.has('nav-solid'));
    rect.height = 650;
    listeners.resize();
    assert.ok(classes.has('nav-transparent'));
});

test('load rechecks a banner whose layout was initially unavailable', () => {
    const rect = { top: 80, width: 0, height: 650 };
    const { classes, listeners } = run(rect);
    assert.ok(classes.has('nav-solid'));
    rect.width = 1440;
    listeners.load();
    assert.ok(classes.has('nav-transparent'));
});

test('admin toolbar does not cover the mobile menu button', () => {
    assert.equal(run({ top: 80, width: 390, height: 300 }, 80, 34).header.style.top, '34px');
});
