const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SRC = fs.readFileSync(
    path.join(__dirname, '..', '..', 'assets', 'js', 'blox-banner.js'),
    'utf8'
);

function makeSlider(attributes = {}, withWrapper = true, top = 0, slideCount = 3) {
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
        matches(selector) { return selector === '[data-blox-banner]'; },
        querySelector(selector) { return nodes[selector] || null; },
        querySelectorAll(selector) {
            return selector === '.swiper-wrapper > .swiper-slide'
                ? Array.from({ length: slideCount }, () => ({}))
                : [];
        },
    };
}

function run({ sliders = [], reduceMotion = false, scrollTop = 0 } = {}) {
    const listeners = {};
    const instances = [];
    const document = {
        readyState: 'complete',
        documentElement: { scrollTop },
        querySelectorAll(selector) { return selector === '[data-blox-banner]' ? sliders : []; },
        addEventListener(type, fn) { listeners[type] = fn; },
    };
    const window = {
        pageYOffset: scrollTop,
        addEventListener(type, fn) { listeners['window:' + type] = fn; },
        matchMedia: () => ({ matches: reduceMotion }),
        Swiper: function Swiper(element, options) {
            const instance = {
                element,
                options,
                destroyed: false,
                updated: false,
                slideIndex: null,
                destroy() { this.destroyed = true; },
                update() { this.updated = true; },
                slideTo(index) { this.slideIndex = index; },
            };
            instances.push(instance);
            return instance;
        },
    };
    const context = { window, document, WeakMap, Number, Math, parseInt };
    context.globalThis = context;
    vm.runInNewContext(SRC, context);
    return { window, listeners, instances };
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

test('programmatic selection reuses one instance and clamps stale indexes', () => {
    const slider = makeSlider({}, true, 0, 2);
    const { window, instances } = run({ sliders: [slider] });

    assert.strictEqual(window.BloxBanner.show(slider, 99), true);
    assert.strictEqual(instances.length, 1);
    assert.strictEqual(instances[0].updated, true);
    assert.strictEqual(instances[0].slideIndex, 1);
});
