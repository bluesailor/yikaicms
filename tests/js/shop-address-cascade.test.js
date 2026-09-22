const assert = require('node:assert/strict');
const test = require('node:test');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.join(__dirname, '../../plugins/shop/assets/address-cascade.js'), 'utf8');
const rawTree = require('../../plugins/shop/data/cn-division/pca.json');
function normalize(nodes) {
    return nodes.map(node => ({ c: String(node.c), n: node.n, ...(node.ch ? { ch: normalize(node.ch) } : {}) }));
}
const tree = normalize(rawTree);

class Target {
    constructor() { this.listeners = {}; }
    addEventListener(type, callback) { (this.listeners[type] ||= []).push(callback); }
    dispatchEvent(event) {
        (this.listeners[event.type] || []).forEach(callback => callback(event));
        return !event.defaultPrevented;
    }
}

class Select extends Target {
    constructor(label) {
        super();
        this.options = [{ value: '', textContent: label }];
        this.disabled = true;
        this.value = '';
    }
    set textContent(value) { this.options = []; this.value = ''; }
    appendChild(option) { this.options.push(option); }
    choose(value) { this.value = value; this.dispatchEvent(new Event('change')); }
}

function fixture(json = JSON.stringify(tree), before) {
    const form = new Target();
    form.elements = {
        province: new Select('Province'),
        city: new Select('City'),
        district: new Select('District'),
    };
    const submit = { disabled: true };
    const error = { hidden: true };
    form.querySelector = selector => selector.includes('region-error') ? error : submit;
    const window = new Target();
    const document = {
        querySelector: () => form,
        getElementById: () => json === null ? null : { textContent: json },
        createElement: () => ({ value: '', textContent: '' }),
    };
    const context = { document, window, Event };
    if (before) before(form.elements);
    vm.runInNewContext(source, context, { filename: 'address-cascade.js' });
    return { form, ...form.elements, submit, error, window };
}

test('all mainland regions load locally; lower levels and submit start disabled', () => {
    const f = fixture();
    assert.equal(f.province.options.length, 32);
    assert.equal(f.province.disabled, false);
    assert.equal(f.city.disabled, true);
    assert.equal(f.district.disabled, true);
    assert.equal(f.submit.disabled, true);
    assert.equal(f.error.hidden, true);
});

test('municipality selects its sole city, but requires an explicit district', () => {
    const f = fixture();
    f.province.choose('北京市');
    assert.equal(f.city.value, '北京市');
    assert.equal(f.city.disabled, false);
    assert.equal(f.district.disabled, false);
    assert.equal(f.district.value, '');
    assert.equal(f.submit.disabled, true);
    f.district.choose('东城区');
    assert.equal(f.submit.disabled, false);
});

test('changing province clears stale descendants before the last shipping notification', () => {
    let shippingPath = [];
    const f = fixture(undefined, elements => {
        Object.values(elements).forEach(select => select.addEventListener('change', () => {
            shippingPath = Object.values(elements).map(item => item.value);
        }));
    });
    f.province.choose('广东省');
    f.city.choose('广州市');
    f.district.choose('天河区');
    assert.equal(f.submit.disabled, false);
    f.province.choose('浙江省');
    assert.equal(f.city.value, '');
    assert.equal(f.district.value, '');
    assert.equal(f.district.disabled, true);
    assert.equal(f.submit.disabled, true);
    assert.deepEqual(shippingPath, ['浙江省', '', '']);
});

test('changing city clears the old district and refreshes shipping', () => {
    const f = fixture();
    f.province.choose('广东省');
    f.city.choose('广州市');
    f.district.choose('天河区');
    f.city.choose('深圳市');
    assert.equal(f.district.value, '');
    assert.equal(f.submit.disabled, true);
    assert.ok(f.district.options.some(option => option.value === '南山区'));
    assert.ok(!f.district.options.some(option => option.value === '天河区'));
});

test('Dongguan and Zhongshan expose their town / street children', () => {
    const f = fixture();
    f.province.choose('广东省');
    for (const name of ['东莞市', '中山市']) {
        f.city.choose(name);
        assert.ok(f.district.options.length > 10);
        assert.equal(f.district.disabled, false);
        f.district.choose(f.district.options[1].value);
        assert.equal(f.submit.disabled, false);
    }
});

test('directly administered county-level city selects its sole district', () => {
    const f = fixture();
    f.province.choose('湖北省');
    f.city.choose('仙桃市');
    assert.equal(f.district.value, '仙桃市');
    assert.equal(f.submit.disabled, false);
});

test('emptying province disables and clears all descendants', () => {
    const f = fixture();
    f.province.choose('北京市');
    f.district.choose('东城区');
    f.province.choose('');
    assert.equal(f.city.value, '');
    assert.equal(f.district.value, '');
    assert.equal(f.city.disabled, true);
    assert.equal(f.district.disabled, true);
    assert.equal(f.submit.disabled, true);
});

test('missing, malformed, incomplete or duplicate region data fails closed', () => {
    const duplicate = [{ c: '11', n: 'P', ch: [
        { c: '1101', n: 'C', ch: [{ c: '110101', n: 'D' }, { c: '110102', n: 'D' }] },
    ] }];
    for (const json of [null, '', '{', '[]', '{}', '[{"c":"11","n":"P","ch":[]}]', JSON.stringify(duplicate)]) {
        const f = fixture(json);
        assert.equal(f.province.disabled, true);
        assert.equal(f.city.disabled, true);
        assert.equal(f.district.disabled, true);
        assert.equal(f.submit.disabled, true);
        assert.equal(f.error.hidden, false);
    }
});

test('pageshow preserves only valid selections and revalidates the submit button', () => {
    const f = fixture();
    f.province.choose('北京市');
    f.district.choose('东城区');
    f.window.dispatchEvent(new Event('pageshow'));
    assert.equal(f.district.value, '东城区');
    assert.equal(f.submit.disabled, false);
    f.city.value = '广州市';
    f.district.value = '天河区';
    f.window.dispatchEvent(new Event('pageshow'));
    assert.equal(f.city.value, '北京市');
    assert.equal(f.district.value, '');
    assert.equal(f.submit.disabled, true);
});

test('submit guard rejects mismatched address even if fields are programmatically changed', () => {
    const f = fixture();
    f.province.choose('北京市');
    f.district.choose('东城区');
    f.district.value = '天河区';
    const event = new Event('submit', { cancelable: true });
    assert.equal(f.form.dispatchEvent(event), false);
    assert.equal(f.submit.disabled, true);
});

test('labels use textContent so data values cannot create markup', () => {
    const maliciousName = '<img src=x onerror=alert(1)>';
    const f = fixture(JSON.stringify([{ c: '11', n: maliciousName, ch: [{ c: '1101', n: 'C', ch: [{ c: '110101', n: 'D' }] }] }]));
    assert.equal(f.province.options[1].textContent, maliciousName);
    assert.equal(f.province.options[1].value, maliciousName);
});
