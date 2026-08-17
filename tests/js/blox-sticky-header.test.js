const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const context = {};
vm.createContext(context);
vm.runInContext(
    fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'blox-sticky-header.js'), 'utf8'),
    context
);
const sticky = context.BloxStickyHeader;

test('scroll-up behavior hides down and reveals up', () => {
    assert.deepEqual(
        JSON.parse(JSON.stringify(sticky.stateFor({
            y: 160, lastY: 100, headerHeight: 64, behavior: 'scroll-up', enabled: true,
        }))),
        { stuck: true, hidden: true, state: 'stuck' }
    );
    assert.equal(sticky.stateFor({
        y: 150, lastY: 160, headerHeight: 64, behavior: 'scroll-up', enabled: true, wasHidden: true,
    }).hidden, false);
    assert.equal(sticky.stateFor({
        y: 160, lastY: 100, headerHeight: 64, behavior: 'always', enabled: true,
    }).hidden, false);
});

test('disabled device never enters the stuck state', () => {
    assert.deepEqual(
        JSON.parse(JSON.stringify(sticky.stateFor({
            y: 200, lastY: 100, behavior: 'scroll-up', enabled: false, overlay: true,
        }))),
        { stuck: false, hidden: false, state: 'overlay' }
    );
    const header = {
        getAttribute(name) {
            return name === 'data-yk-sticky-desktop' ? '1' : '0';
        },
    };
    assert.equal(sticky.enabledFor(header, 1440), true);
    assert.equal(sticky.enabledFor(header, 768), false);
    assert.equal(sticky.enabledFor(header, 390), false);
});
