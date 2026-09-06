const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const POLICY_SRC = fs.readFileSync(
    path.join(__dirname, '..', '..', 'assets', 'js', 'blox-video-policy.js'),
    'utf8'
);
const BANNER_SRC = fs.readFileSync(
    path.join(__dirname, '..', '..', 'assets', 'js', 'blox-banner.js'),
    'utf8'
);

function makeVideo(attributes = {}) {
    attributes = { 'data-blox-video-src': '/uploads/banner.mp4', ...attributes };
    const listeners = {};
    const classes = new Set();
    return {
        attributes,
        listeners,
        currentTime: 12,
        playCalls: 0,
        pauseCalls: 0,
        loadCalls: 0,
        classList: {
            add(name) { classes.add(name); },
            remove(name) { classes.delete(name); },
            contains(name) { return classes.has(name); },
        },
        getAttribute(name) { return Object.prototype.hasOwnProperty.call(attributes, name) ? String(attributes[name]) : null; },
        setAttribute(name, value) { attributes[name] = String(value); },
        removeAttribute(name) { delete attributes[name]; },
        addEventListener(type, fn) { listeners[type] = fn; },
        play() { this.playCalls += 1; return Promise.resolve(); },
        pause() { this.pauseCalls += 1; },
        load() { this.loadCalls += 1; },
    };
}

function makeSlider(attributes = {}, withWrapper = true, top = 0, slideCount = 3, slideVideos = []) {
    const slides = Array.from({ length: slideCount }, (_, index) => ({
        querySelector(selector) {
            return selector === '[data-blox-banner-video]' ? (slideVideos[index] || null) : null;
        },
    }));
    const nodes = {
        '.swiper-wrapper': withWrapper ? {} : null,
        '.swiper-pagination': { role: 'pagination' },
        '.swiper-button-prev': { role: 'previous' },
        '.swiper-button-next': { role: 'next' },
    };
    const classes = new Set(['swiper', 'banner-swiper']);
    const styleValues = {};
    return {
        attributes,
        style: { values: styleValues, setProperty(name, value) { styleValues[name] = value; } },
        classList: { add(name) { classes.add(name); }, contains(name) { return classes.has(name); } },
        getBoundingClientRect() { return { top }; },
        getAttribute(name) { return Object.prototype.hasOwnProperty.call(attributes, name) ? String(attributes[name]) : null; },
        setAttribute(name, value) { attributes[name] = String(value); },
        matches(selector) { return selector === '[data-blox-banner]'; },
        querySelector(selector) { return nodes[selector] || null; },
        querySelectorAll(selector) {
            if (selector === '.swiper-wrapper > .swiper-slide') return slides;
            if (selector === '[data-blox-banner-video]') return slideVideos.filter(Boolean);
            return [];
        },
    };
}

function run({ sliders = [], reduceMotion = false, mobile = false, scrollTop = 0, saveData = false, hidden = false,
    overlay = false, headerBottom = 0, headerStuck = false } = {}) {
    const listeners = {};
    const instances = [];
    const timers = new Map();
    const mediaListeners = {};
    const connectionListeners = {};
    const mediaState = { reduceMotion, mobile };
    let nextTimerId = 1;
    const rootClasses = new Set(overlay ? ['yk-home-header-overlay'] : []);
    const header = {
        classList: { contains(name) { return headerStuck && name === 'yk-stuck'; } },
        getBoundingClientRect() { return { top: 0, bottom: headerBottom }; },
    };
    const document = {
        readyState: 'complete',
        hidden,
        documentElement: { scrollTop, classList: { contains(name) { return rootClasses.has(name); } } },
        querySelector(selector) {
            if (selector.includes('[data-yk-overlay-enabled="1"]') && !headerStuck) return header;
            if (selector === '#siteHeader:not(.yk-blox-header)' && !headerStuck) return header;
            return null;
        },
        querySelectorAll(selector) { return selector === '[data-blox-banner]' ? sliders : []; },
        addEventListener(type, fn) { listeners[type] = fn; },
    };
    const window = {
        pageYOffset: scrollTop,
        navigator: { connection: { saveData, addEventListener(type, fn) { connectionListeners[type] = fn; } } },
        addEventListener(type, fn) { listeners['window:' + type] = fn; },
        setTimeout(fn) { const id = nextTimerId++; timers.set(id, fn); return id; },
        clearTimeout(id) { timers.delete(id); },
        matchMedia: query => {
            const type = query.includes('max-width') ? 'mobile' : 'motion';
            return {
                matches: type === 'mobile' ? mediaState.mobile : mediaState.reduceMotion,
                addEventListener(name, fn) { mediaListeners[type + ':' + name] = fn; },
            };
        },
        Swiper: function Swiper(element, options) {
            const instance = {
                element,
                options,
                destroyed: false,
                updated: false,
                activeIndex: 0,
                slideIndex: null,
                nextCalls: 0,
                autoplay: {
                    startCalls: 0,
                    stopCalls: 0,
                    start() { this.startCalls += 1; },
                    stop() { this.stopCalls += 1; },
                },
                destroy() { this.destroyed = true; },
                update() { this.updated = true; },
                slideTo(index) { this.slideIndex = index; },
                slideNext() { this.nextCalls += 1; },
            };
            instances.push(instance);
            return instance;
        },
    };
    const context = { window, document, WeakMap, Number, Math, parseInt };
    context.globalThis = context;
    vm.runInNewContext(POLICY_SRC, context);
    vm.runInNewContext(BANNER_SRC, context);
    return { window, document, listeners, instances, timers, mediaListeners, connectionListeners, mediaState };
}

test('creates one scoped Swiper instance per banner', () => {
    const first = makeSlider({
        'data-blox-effect': 'slide',
        'data-blox-autoplay': '8',
        'data-blox-speed': '900',
        'data-blox-navigation': '1',
        'data-blox-pagination': '1',
        'data-blox-pause-hover': '1',
    });
    const second = makeSlider({ 'data-blox-autoplay': '0', 'data-blox-navigation': '0' });
    const { instances } = run({ sliders: [first, second] });

    assert.strictEqual(instances.length, 2);
    assert.strictEqual(instances[0].options.effect, 'slide');
    assert.strictEqual(instances[0].options.speed, 900);
    assert.strictEqual(instances[0].options.autoplay.delay, 8000);
    assert.strictEqual(instances[0].options.autoplay.pauseOnMouseEnter, true);
    assert.strictEqual(instances[0].options.navigation.prevEl.role, 'previous');
    assert.strictEqual(instances[1].options.autoplay, false);
    assert.strictEqual(instances[1].options.navigation, false);
});

test('reduced-motion disables autoplay and animated transitions', () => {
    const slider = makeSlider({ 'data-blox-effect': 'fade', 'data-blox-autoplay': '5' });
    const { instances } = run({ sliders: [slider], reduceMotion: true });

    assert.strictEqual(instances[0].options.effect, 'slide');
    assert.strictEqual(instances[0].options.speed, 0);
    assert.strictEqual(instances[0].options.autoplay, false);
});

test('static hero receives the active class without creating Swiper', () => {
    const hero = makeSlider({}, false);
    const { instances } = run({ sliders: [hero] });

    assert.strictEqual(instances.length, 0);
    assert.strictEqual(hero.classList.contains('blox-banner-static-active'), true);
});

test('preview refresh event remains available for replaced banner markup', () => {
    const { listeners, window } = run();
    assert.strictEqual(typeof window.BloxBanner.init, 'function');
    assert.strictEqual(typeof listeners['blox:content-updated'], 'function');
});

test('same banner node is rebuilt when runtime settings change', () => {
    const slider = makeSlider({ 'data-blox-effect': 'fade', 'data-blox-autoplay': '5' });
    const { window, instances } = run({ sliders: [slider] });
    const first = instances[0];

    slider.attributes['data-blox-effect'] = 'slide';
    window.BloxBanner.init(slider);

    assert.strictEqual(first.destroyed, true);
    assert.strictEqual(instances.length, 2);
    assert.strictEqual(instances[1].options.effect, 'slide');
});

test('screen height mode measures the banner offset below the header', () => {
    const slider = makeSlider({ 'data-blox-height-mode': 'screen' }, true, 86);
    const { listeners, window } = run({ sliders: [slider], scrollTop: 14 });

    assert.strictEqual(slider.style.values['--blox-banner-offset'], '100px');
    assert.strictEqual(typeof listeners['window:resize'], 'function');
    assert.strictEqual(typeof window.BloxBanner.refreshViewportHeights, 'function');
});

test('overlay header height becomes a banner content safety inset', () => {
    const slider = makeSlider();
    const { window } = run({ sliders: [slider], overlay: true, headerBottom: 92 });

    assert.strictEqual(slider.style.values['--blox-banner-safe-top'], '92px');
    assert.strictEqual(slider.attributes['data-blox-overlay-safe'], '1');
    window.BloxBanner.refreshHeaderSafety(slider);
    assert.strictEqual(slider.style.values['--blox-banner-safe-top'], '92px');
});

test('non-overlay and stuck headers do not reserve banner space', () => {
    const ordinary = makeSlider();
    const stuck = makeSlider();
    run({ sliders: [ordinary], overlay: false, headerBottom: 92 });
    run({ sliders: [stuck], overlay: true, headerBottom: 92, headerStuck: true });

    assert.strictEqual(ordinary.style.values['--blox-banner-safe-top'], '0px');
    assert.strictEqual(stuck.style.values['--blox-banner-safe-top'], '0px');
});

test('programmatic selection reuses one instance and clamps stale indexes', () => {
    const slider = makeSlider({}, true, 0, 2);
    const { window, instances } = run({ sliders: [slider] });

    assert.strictEqual(window.BloxBanner.show(slider, 99), true);
    assert.strictEqual(instances.length, 1);
    assert.strictEqual(instances[0].updated, true);
    assert.strictEqual(instances[0].slideIndex, 1);
});

test('only the active video plays and its full duration owns autoplay timing', async () => {
    const firstVideo = makeVideo({ 'data-blox-mobile-video': 'video' });
    const secondVideo = makeVideo({ 'data-blox-mobile-video': 'video' });
    const slider = makeSlider({ 'data-blox-autoplay': '5' }, true, 0, 2, [firstVideo, secondVideo]);
    const { instances } = run({ sliders: [slider] });
    const instance = instances[0];

    assert.strictEqual(firstVideo.playCalls, 1);
    assert.strictEqual(firstVideo.getAttribute('src'), '/uploads/banner.mp4');
    assert.ok(firstVideo.pauseCalls >= 1);
    assert.strictEqual(secondVideo.playCalls, 0);
    assert.strictEqual(secondVideo.getAttribute('src'), null);
    assert.strictEqual(instance.autoplay.stopCalls, 1);

    firstVideo.listeners.playing();
    assert.strictEqual(firstVideo.classList.contains('blox-banner-video-ready'), true);
    firstVideo.listeners.ended();
    assert.strictEqual(instance.nextCalls, 1);
});

test('slide transitions pause the previous video and play the newly active video', () => {
    const firstVideo = makeVideo({ 'data-blox-mobile-video': 'video' });
    const secondVideo = makeVideo({ 'data-blox-mobile-video': 'video' });
    const slider = makeSlider({ 'data-blox-autoplay': '5' }, true, 0, 2, [firstVideo, secondVideo]);
    const { instances } = run({ sliders: [slider] });
    const instance = instances[0];

    instance.options.on.slideChangeTransitionStart();
    instance.activeIndex = 1;
    instance.options.on.slideChangeTransitionEnd(instance);

    assert.ok(firstVideo.pauseCalls >= 2);
    assert.strictEqual(firstVideo.currentTime, 0);
    assert.strictEqual(firstVideo.getAttribute('src'), null);
    assert.strictEqual(secondVideo.playCalls, 1);
    assert.strictEqual(secondVideo.getAttribute('src'), '/uploads/banner.mp4');
});

test('an inactive video load error does not restart autoplay over the active video', () => {
    const firstVideo = makeVideo({ 'data-blox-mobile-video': 'video' });
    const secondVideo = makeVideo({ 'data-blox-mobile-video': 'video' });
    const slider = makeSlider({ 'data-blox-autoplay': '5' }, true, 0, 2, [firstVideo, secondVideo]);
    const { instances } = run({ sliders: [slider] });
    const instance = instances[0];

    secondVideo.listeners.error();

    assert.strictEqual(instance.autoplay.startCalls, 0);
    assert.strictEqual(instance.autoplay.stopCalls, 1);
});

test('a stalled active video restores carousel timing after no playback progress', () => {
    const video = makeVideo({ 'data-blox-mobile-video': 'video' });
    const slider = makeSlider({ 'data-blox-autoplay': '5' }, true, 0, 2, [video]);
    const { instances, timers } = run({ sliders: [slider] });

    video.currentTime = 4;
    video.listeners.waiting();
    assert.strictEqual(timers.size, 1);
    Array.from(timers.values())[0]();

    assert.strictEqual(instances[0].autoplay.startCalls, 1);
    assert.ok(video.pauseCalls >= 2);
    assert.strictEqual(video.classList.contains('blox-banner-video-ready'), false);
});

test('stalled callback from an inactive video cannot restart carousel timing', () => {
    const firstVideo = makeVideo({ 'data-blox-mobile-video': 'video' });
    const secondVideo = makeVideo({ 'data-blox-mobile-video': 'video' });
    const slider = makeSlider({ 'data-blox-autoplay': '5' }, true, 0, 2, [firstVideo, secondVideo]);
    const { instances, timers } = run({ sliders: [slider] });

    firstVideo.listeners.stalled();
    const callback = Array.from(timers.values())[0];
    instances[0].activeIndex = 1;
    callback();

    assert.strictEqual(instances[0].autoplay.startCalls, 0);
});

test('mobile poster mode and reduced motion do not start banner video', () => {
    const mobileVideo = makeVideo({ 'data-blox-mobile-video': 'poster' });
    const mobileSlider = makeSlider({ 'data-blox-autoplay': '5' }, true, 0, 1, [mobileVideo]);
    run({ sliders: [mobileSlider], mobile: true });
    assert.strictEqual(mobileVideo.playCalls, 0);
    assert.strictEqual(mobileVideo.getAttribute('src'), null);

    const reducedVideo = makeVideo({ 'data-blox-mobile-video': 'video' });
    const reducedSlider = makeSlider({ 'data-blox-autoplay': '5' }, true, 0, 1, [reducedVideo]);
    const reduced = run({ sliders: [reducedSlider], reduceMotion: true });
    assert.strictEqual(reducedVideo.playCalls, 0);
    assert.strictEqual(reducedVideo.getAttribute('src'), null);
    assert.strictEqual(reduced.instances[0].autoplay.startCalls, 0);
});

test('save-data keeps the poster and leaves image carousel timing available', () => {
    const video = makeVideo({ 'data-blox-mobile-video': 'video' });
    const slider = makeSlider({ 'data-blox-autoplay': '5' }, true, 0, 1, [video]);
    const { instances } = run({ sliders: [slider], saveData: true });

    assert.strictEqual(video.playCalls, 0);
    assert.strictEqual(video.getAttribute('src'), null);
    assert.strictEqual(instances[0].autoplay.startCalls, 1);
});

test('a delayed playing event from an inactive slide cannot reveal its video', () => {
    const firstVideo = makeVideo({ 'data-blox-mobile-video': 'video' });
    const secondVideo = makeVideo({ 'data-blox-mobile-video': 'video' });
    const slider = makeSlider({ 'data-blox-autoplay': '5' }, true, 0, 2, [firstVideo, secondVideo]);
    const { instances } = run({ sliders: [slider] });

    instances[0].activeIndex = 1;
    firstVideo.listeners.playing();

    assert.strictEqual(firstVideo.classList.contains('blox-banner-video-ready'), false);
});

test('crossing into a mobile poster viewport unloads the active source', () => {
    const video = makeVideo({ 'data-blox-mobile-video': 'poster' });
    const slider = makeSlider({ 'data-blox-autoplay': '5' }, true, 0, 1, [video]);
    const state = run({ sliders: [slider] });
    assert.strictEqual(video.getAttribute('src'), '/uploads/banner.mp4');

    state.mediaState.mobile = true;
    state.mediaListeners['mobile:change']();

    assert.strictEqual(video.getAttribute('src'), null);
    assert.ok(video.loadCalls >= 2);
});

test('enabling reduced motion rebuilds the slider without animation or video', () => {
    const video = makeVideo({ 'data-blox-mobile-video': 'video' });
    const slider = makeSlider({ 'data-blox-effect': 'fade', 'data-blox-autoplay': '5' }, true, 0, 1, [video]);
    const state = run({ sliders: [slider] });
    const first = state.instances[0];

    state.mediaState.reduceMotion = true;
    state.mediaListeners['motion:change']();

    assert.strictEqual(first.destroyed, true);
    assert.strictEqual(state.instances.length, 2);
    assert.strictEqual(state.instances[1].options.effect, 'slide');
    assert.strictEqual(state.instances[1].options.speed, 0);
    assert.strictEqual(state.instances[1].options.autoplay, false);
    assert.strictEqual(video.getAttribute('src'), null);
});

test('page visibility pauses and resumes the active video without losing position', () => {
    const video = makeVideo({ 'data-blox-mobile-video': 'video' });
    const slider = makeSlider({ 'data-blox-autoplay': '5' }, true, 0, 1, [video]);
    const { document, listeners, instances } = run({ sliders: [slider] });
    const initialPauseCalls = video.pauseCalls;

    video.currentTime = 1.25;
    document.hidden = true;
    listeners.visibilitychange();
    assert.ok(video.pauseCalls > initialPauseCalls);
    assert.strictEqual(video.currentTime, 1.25);

    document.hidden = false;
    listeners.visibilitychange();
    assert.strictEqual(video.playCalls, 2);
    assert.strictEqual(video.currentTime, 1.25);
    assert.ok(instances[0].autoplay.stopCalls >= 3);
});
