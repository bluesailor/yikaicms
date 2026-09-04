const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const POLICY_SRC = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'blox-video-policy.js'), 'utf8');
const BACKGROUND_SRC = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'blox-background-video.js'), 'utf8');

function video(mode = 'poster') {
    const attrs = { 'data-blox-video-src': '/uploads/background.mp4', 'data-blox-mobile-video': mode };
    const listeners = {};
    const classes = new Set();
    return {
        listeners, playCalls: 0, pauseCalls: 0, loadCalls: 0,
        matches(selector) { return selector === '[data-blox-background-video]'; },
        querySelectorAll() { return []; },
        getAttribute(name) { return Object.prototype.hasOwnProperty.call(attrs, name) ? attrs[name] : null; },
        setAttribute(name, value) { attrs[name] = String(value); },
        removeAttribute(name) { delete attrs[name]; },
        addEventListener(name, fn) { listeners[name] = fn; },
        play() { this.playCalls++; return Promise.resolve(); },
        pause() { this.pauseCalls++; },
        load() { this.loadCalls++; },
        classList: { add(name) { classes.add(name); }, remove(name) { classes.delete(name); }, contains(name) { return classes.has(name); } },
    };
}

function run({ item = video(), mobile = false, reduced = false, saveData = false, hidden = false, observer = false } = {}) {
    let items = [item];
    const listeners = {};
    const mediaListeners = {};
    const connectionListeners = {};
    const mediaState = { mobile, reduced };
    const observed = [];
    const unobserved = [];
    let observerCallback = null;
    const document = {
        readyState: 'complete', hidden,
        querySelectorAll(selector) { return selector === '[data-blox-background-video]' ? items : []; },
        addEventListener(name, fn) { listeners[name] = fn; },
    };
    const window = {
        navigator: { connection: { saveData, addEventListener(name, fn) { connectionListeners[name] = fn; } } },
        addEventListener(name, fn) { listeners['window:' + name] = fn; },
        matchMedia(query) {
            const key = query.includes('max-width') ? 'mobile' : 'motion';
            return {
                matches: key === 'mobile' ? mediaState.mobile : mediaState.reduced,
                addEventListener(name, fn) { mediaListeners[key + ':' + name] = fn; },
            };
        },
    };
    if (observer) {
        window.IntersectionObserver = class {
            constructor(callback) { observerCallback = callback; }
            observe(target) { observed.push(target); }
            unobserve(target) { unobserved.push(target); }
        };
    }
    const context = { window, document, WeakMap, Set };
    vm.runInNewContext(POLICY_SRC, context);
    vm.runInNewContext(BACKGROUND_SRC, context);
    return {
        window, document, listeners, mediaListeners, connectionListeners, mediaState, observed, unobserved, item,
        replaceItems(next) { items = next; },
        intersect(target, isIntersecting) { observerCallback([{ target, isIntersecting }]); },
    };
}

test('desktop video receives its source only when runtime playback is allowed', () => {
    const state = run({ item: video('poster') });
    assert.equal(state.item.getAttribute('src'), '/uploads/background.mp4');
    assert.equal(state.item.playCalls, 1);
    state.item.listeners.playing();
    assert.equal(state.item.classList.contains('blox-bg-video-ready'), true);
});

for (const options of [
    { mobile: true, item: video('poster') },
    { reduced: true, item: video('video') },
    { saveData: true, item: video('video') },
]) {
    test('blocked playback leaves the video source unset', () => {
        const state = run(options);
        assert.equal(state.item.getAttribute('src'), null);
        assert.equal(state.item.playCalls, 0);
    });
}

test('page visibility pauses and resumes without discarding the loaded source', () => {
    const state = run({ item: video('video') });
    state.document.hidden = true;
    state.listeners.visibilitychange();
    assert.ok(state.item.pauseCalls >= 1);
    assert.equal(state.item.getAttribute('src'), '/uploads/background.mp4');
    state.document.hidden = false;
    state.listeners.visibilitychange();
    assert.equal(state.item.playCalls, 2);
});

test('mobile policy changes unload once without binding resize playback churn', () => {
    const state = run({ item: video('poster') });
    assert.equal(state.item.getAttribute('src'), '/uploads/background.mp4');
    assert.equal(state.listeners['window:resize'], undefined);

    state.mediaState.mobile = true;
    state.mediaListeners['mobile:change']();

    assert.equal(state.item.getAttribute('src'), null);
    assert.ok(state.item.loadCalls >= 1);
});

test('viewport observer defers the source, pauses far videos, and resumes nearby videos', () => {
    const state = run({ item: video('video'), observer: true });
    assert.deepEqual(state.observed, [state.item]);
    assert.equal(state.item.getAttribute('src'), null);
    assert.equal(state.item.playCalls, 0);

    state.intersect(state.item, true);
    assert.equal(state.item.getAttribute('src'), '/uploads/background.mp4');
    assert.equal(state.item.playCalls, 1);

    state.intersect(state.item, false);
    assert.ok(state.item.pauseCalls >= 1);
    assert.equal(state.item.getAttribute('src'), '/uploads/background.mp4');

    state.intersect(state.item, true);
    assert.equal(state.item.playCalls, 2);
});

test('content replacement unloads detached background videos before binding replacements', () => {
    const first = video('video');
    const second = video('video');
    const state = run({ item: first });
    assert.equal(first.getAttribute('src'), '/uploads/background.mp4');

    state.replaceItems([second]);
    state.listeners['blox:content-updated']();

    assert.ok(first.pauseCalls >= 1);
    assert.equal(first.getAttribute('src'), null);
    assert.ok(first.loadCalls >= 1);
    assert.equal(second.getAttribute('src'), '/uploads/background.mp4');
    assert.equal(second.playCalls, 1);

    state.replaceItems([first]);
    state.listeners['blox:content-updated']();
    assert.equal(first.getAttribute('src'), '/uploads/background.mp4');
    const pausesBeforeSecondRemoval = first.pauseCalls;
    state.replaceItems([]);
    state.listeners['blox:content-updated']();
    assert.ok(first.pauseCalls > pausesBeforeSecondRemoval);
    assert.equal(first.getAttribute('src'), null);
});

test('content replacement transfers viewport observation and release ownership', () => {
    const first = video('video');
    const second = video('video');
    const state = run({ item: first, observer: true });
    state.intersect(first, true);

    state.replaceItems([second]);
    state.listeners['blox:content-updated']();
    assert.deepEqual(state.unobserved, [first]);
    assert.deepEqual(state.observed, [first, second]);
    assert.equal(first.getAttribute('src'), null);
    assert.equal(second.getAttribute('src'), null);

    state.intersect(first, true);
    assert.equal(first.getAttribute('src'), null);
    state.intersect(second, true);
    assert.equal(second.getAttribute('src'), '/uploads/background.mp4');
    assert.equal(second.playCalls, 1);
});
