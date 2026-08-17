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
