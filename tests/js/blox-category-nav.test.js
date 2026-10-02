/**
 * assets/js/blox-category-nav.js 的零依赖回归测试（栏目 / 分类导航元素的前台脚本）。
 * 最小假 DOM + vm 沙箱：手风琴按钮开合子列表；小屏下拉框选中即跳转，编辑器画布里不跳。
 */

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SRC = fs.readFileSync(
    path.join(__dirname, '..', '..', 'assets', 'js', 'blox-category-nav.js'),
    'utf8'
);

function element(attrs, extra = {}) {
    return Object.assign({
        attrs: Object.assign({}, attrs),
        hidden: false,
        getAttribute(name) { return name in this.attrs ? this.attrs[name] : null; },
        setAttribute(name, value) { this.attrs[name] = String(value); },
    }, extra);
}

function run({ panels = {}, inCanvas = false } = {}) {
    const listeners = {};
    const location = { href: '/start/' };
    const document = {
        addEventListener(type, fn) { listeners[type] = fn; },
        getElementById(id) { return panels[id] || null; },
        querySelector(sel) { return sel === '.yk-canvas-region' && inCanvas ? {} : null; },
    };
    vm.runInNewContext(SRC, { document, window: { location } });
    return { listeners, location };
}

test('toggle button opens and closes its panel', () => {
    const panel = element({ id: 'n-c3' }, { hidden: true });
    const button = element({ 'aria-expanded': 'false', 'aria-controls': 'n-c3' });
    const { listeners } = run({ panels: { 'n-c3': panel } });
    const target = { closest: (sel) => (sel === '[data-yk-category-nav-toggle]' ? button : null) };

    listeners.click({ target });
    assert.strictEqual(button.getAttribute('aria-expanded'), 'true');
    assert.strictEqual(panel.hidden, false);

    listeners.click({ target });
    assert.strictEqual(button.getAttribute('aria-expanded'), 'false');
    assert.strictEqual(panel.hidden, true);
});

test('clicks elsewhere are ignored', () => {
    const { listeners } = run();
    assert.doesNotThrow(() => listeners.click({ target: { closest: () => null } }));
    assert.doesNotThrow(() => listeners.click({ target: null }));
});

function select(value) {
    return { value, matches: (sel) => sel === '[data-yk-category-nav-select]' };
}

test('mobile select navigates to the chosen section', () => {
    const { listeners, location } = run();
    listeners.change({ target: select('/product/pumps/') });
    assert.strictEqual(location.href, '/product/pumps/');
    listeners.change({ target: select('#') });
    assert.strictEqual(location.href, '/product/pumps/');
});

test('the editor canvas only previews the select', () => {
    const { listeners, location } = run({ inCanvas: true });
    listeners.change({ target: select('/news/') });
    assert.strictEqual(location.href, '/start/');
});

test('other selects are left alone', () => {
    const { listeners, location } = run();
    listeners.change({ target: { value: '/x/', matches: () => false } });
    assert.strictEqual(location.href, '/start/');
});
