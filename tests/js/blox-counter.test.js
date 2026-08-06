/**
 * assets/js/blox-counter.js 的零依赖回归测试。
 *
 * 该脚本随免费版分发，被默认主题统计区块、StatsGroupElement 与 HomeBlockElement 引用。
 * 用最小假 DOM 在 vm 沙箱里加载 IIFE，只验对外契约，不碰内部实现细节。
 */

const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SRC = fs.readFileSync(
    path.join(__dirname, '..', '..', 'assets', 'js', 'blox-counter.js'),
    'utf8'
);

/** 造一个刚好够用的元素；querySelectorAll 只认 '.stat-number[data-count]'。 */
function makeNumber(count, text) {
    return {
        attrs: { 'data-count': String(count) },
        textContent: text === undefined ? String(count) : text,
        getAttribute(name) { return name in this.attrs ? this.attrs[name] : null; },
    };
}

function makeGroup(config, numbers) {
    return {
        attrs: { 'data-blox-counter': config },
        getAttribute(name) { return name in this.attrs ? this.attrs[name] : null; },
        matches(sel) { return sel === '[data-blox-counter]'; },
        querySelectorAll() { return numbers; },
    };
}

/** 在沙箱里跑一次脚本；reduceMotion / 有无 IntersectionObserver 可控。 */
function run({ groups = [], reduceMotion = false, withObserver = false } = {}) {
    const listeners = {};
    const observed = [];
    const document = {
        readyState: 'complete',
        querySelectorAll(sel) { return sel === '[data-blox-counter]' ? groups : []; },
        addEventListener(type, fn) { listeners[type] = fn; },
    };
    const window = {
        matchMedia: () => ({ matches: reduceMotion }),
        requestAnimationFrame: (fn) => { fn(1e9); return 1; },   // 立即推进到动画末帧
        performance: { now: () => 0 },
    };
    if (withObserver) {
        window.IntersectionObserver = class {
            observe(el) { observed.push(el); }
            unobserve() {}
        };
    }
    // 浏览器里 window 与全局同一对象，脚本用的是裸 performance / IntersectionObserver，
    // 沙箱里必须同时挂到全局，否则报 ReferenceError（是测试环境差异，不是脚本缺陷）。
    const ctx = { window, document, Math, JSON, Number, parseInt, WeakSet, Date };
    ctx.performance = window.performance;
    ctx.requestAnimationFrame = window.requestAnimationFrame;
    if (window.IntersectionObserver) ctx.IntersectionObserver = window.IntersectionObserver;
    ctx.globalThis = ctx;
    vm.runInNewContext(SRC, ctx);
    return { window, document, listeners, observed };
}

test('对外暴露 BloxCounter.init', () => {
    const { window } = run();
    assert.strictEqual(typeof window.BloxCounter.init, 'function');
});

test('监听 blox:content-updated，供预览刷新后重新初始化', () => {
    const { listeners } = run();
    assert.strictEqual(typeof listeners['blox:content-updated'], 'function');
});

test('data-blox-counter 是坏 JSON 时不抛异常，且不改动文案', () => {
    const el = makeNumber(1200, '1200+');
    const group = makeGroup('{ 这不是 JSON', [el]);
    assert.doesNotThrow(() => run({ groups: [group] }));
    assert.strictEqual(el.textContent, '1200+', '解析失败应视为禁用，保持原文案');
});

test('无 IntersectionObserver 时直接激活并写入目标值', () => {
    const el = makeNumber(1200, '1200+');
    const group = makeGroup(JSON.stringify({ enabled: true, start: 0, duration: 100 }), [el]);
    run({ groups: [group] });
    assert.match(String(el.textContent), /1200/, '末帧应落到目标数字');
});

test('有 IntersectionObserver 时只登记观察，不立即改数字', () => {
    const el = makeNumber(1200, '0');
    const group = makeGroup(JSON.stringify({ enabled: true, start: 0, duration: 100 }), [el]);
    const { observed } = run({ groups: [group], withObserver: true });
    assert.strictEqual(observed.length, 1, '应把区块交给观察器');
    assert.strictEqual(el.textContent, '0', '进入视口前不应改动');
});

test('prefers-reduced-motion 时跳过动画', () => {
    const el = makeNumber(1200, '1200+');
    const group = makeGroup(JSON.stringify({ enabled: true, start: 0, duration: 100 }), [el]);
    run({ groups: [group], reduceMotion: true });
    assert.strictEqual(el.textContent, '1200+', '减少动画偏好下应保持原样');
});

test('enabled:false 时不动数字', () => {
    const el = makeNumber(1200, '1200+');
    const group = makeGroup(JSON.stringify({ enabled: false }), [el]);
    run({ groups: [group] });
    assert.strictEqual(el.textContent, '1200+');
});
