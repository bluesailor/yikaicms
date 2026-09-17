const test = require('node:test');
const assert = require('node:assert');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const context = { globalThis: {} };
vm.runInNewContext(
    fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'blox-responsive.js'), 'utf8'),
    context
);
const responsive = context.globalThis.BloxResponsive;
const fixtures = JSON.parse(fs.readFileSync(
    path.join(__dirname, '..', 'fixtures', 'blox-responsive-values.json'),
    'utf8'
));
const options = { none: true, sm: true, md: true, lg: true };

test('PHP and browser normalizers share the same fixtures', () => {
    fixtures.forEach((fixture) => {
        assert.deepStrictEqual(
            JSON.parse(JSON.stringify(responsive.normalize(fixture.value, options, fixture.fallback))),
            fixture.expected,
            fixture.name
        );
    });
});

test('editing one device promotes a scalar without losing inherited tiers', () => {
    assert.deepStrictEqual(
        JSON.parse(JSON.stringify(responsive.setFor('md', 'mobile', 'sm', options, 'md'))),
        { d: 'md', m: 'sm' }
    );
    assert.strictEqual(responsive.valueFor({ d: 'lg', t: 'md' }, 'mobile', options, 'sm'), 'md');
});

test('responsive state identifies explicit overrides and inheritance source', () => {
    assert.deepStrictEqual(
        JSON.parse(JSON.stringify(responsive.stateFor({ d: 'lg', t: 'md' }, 'tablet', options, 'sm'))),
        { device: 't', value: 'md', source: 't', overridden: true, inherited: false }
    );
    assert.deepStrictEqual(
        JSON.parse(JSON.stringify(responsive.stateFor({ d: 'lg', t: 'md' }, 'mobile', options, 'sm'))),
        { device: 'm', value: 'md', source: 't', overridden: false, inherited: true }
    );
});

test('restoring inheritance removes only the active device override', () => {
    assert.deepStrictEqual(
        JSON.parse(JSON.stringify(responsive.inheritFor({ d: 'lg', t: 'md', m: 'sm' }, 'tablet', options, 'sm'))),
        { d: 'lg', m: 'sm' }
    );
    assert.strictEqual(
        responsive.inheritFor({ d: 'lg', m: 'sm' }, 'mobile', options, 'sm'),
        'lg'
    );
});

test('editing a parent tier updates inherited descendants and preserves explicit ones', () => {
    assert.deepStrictEqual(
        JSON.parse(JSON.stringify(responsive.setFor({ d: 'lg', m: 'sm' }, 'tablet', 'md', options, 'sm'))),
        { d: 'lg', m: 'sm', t: 'md' }
    );
    assert.deepStrictEqual(
        JSON.parse(JSON.stringify(responsive.setFor({ d: 'lg', t: 'md' }, 'desktop', 'sm', options, 'sm'))),
        { d: 'sm', t: 'md' }
    );
});

// ---- R2B：数值预览宽度钳制（0=自动；320–2560 取整钳制） ----
test('R2B: clampPreviewWidth clamps to 320-2560 and treats empty/invalid as auto', () => {
    const clamp = responsive.clampPreviewWidth;
    assert.equal(clamp(''), 0);
    assert.equal(clamp(null), 0);
    assert.equal(clamp('abc'), 0);
    assert.equal(clamp(0), 0);
    assert.equal(clamp(-50), 0);
    assert.equal(clamp('100'), 320);
    assert.equal(clamp(320), 320);
    assert.equal(clamp('1024.9'), 1024);
    assert.equal(clamp(2560), 2560);
    assert.equal(clamp(99999), 2560);
    assert.equal(responsive.PREVIEW_WIDTH_MIN, 320);
    assert.equal(responsive.PREVIEW_WIDTH_MAX, 2560);
});

test('breakpoint ranges and preview width tiers share one definition', () => {
    assert.deepStrictEqual(
        ['mobile', 'tablet', 'desktop', 'wide'].map((device) => responsive.rangeLabel(device)),
        ['<768px', '768\u20131023px', '1024\u20131439px', '\u22651440px']
    );
    assert.deepStrictEqual(
        [320, 767, 768, 1023, 1024, 1439, 1440, 2560, 'x'].map((width) => responsive.deviceForWidth(width)),
        ['mobile', 'mobile', 'tablet', 'tablet', 'desktop', 'desktop', 'wide', 'wide', 'desktop']
    );
});

test('widescreen inherits desktop until it is set explicitly', () => {
    const options = { sm: true, md: true, lg: true };
    assert.deepStrictEqual(JSON.parse(JSON.stringify(responsive.normalize({ d: 'md', m: 'sm' }, options, 'sm'))), { d: 'md', t: 'md', m: 'sm', w: 'md' });
    const state = responsive.stateFor({ d: 'md' }, 'wide', options, 'sm');
    assert.strictEqual(state.device, 'w');
    assert.strictEqual(state.source, 'd');
    assert.strictEqual(state.inherited, true);
    const set = responsive.setFor('md', 'wide', 'lg', options, 'sm');
    assert.deepStrictEqual(JSON.parse(JSON.stringify(set)), { d: 'md', w: 'lg' });
    assert.strictEqual(responsive.stateFor(set, 'wide', options, 'sm').overridden, true);
});
