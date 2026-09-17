const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const pricing = require('../../assets/js/blox-pricing.js');

function classList(initial) {
    const set = new Set(initial);
    return {
        toggle(name, on) { if (on) set.add(name); else set.delete(name); },
        contains(name) { return set.has(name); },
    };
}

function fakeRoot() {
    const attrs = {};
    const prices = ['monthly', 'yearly'].map(cycle => ({ cycle, hidden: cycle === 'yearly', getAttribute: () => cycle }));
    const buttons = ['monthly', 'yearly'].map(cycle => {
        const button = {
            attrs: { 'data-yk-pricing-cycle-button': cycle, 'aria-pressed': cycle === 'monthly' ? 'true' : 'false' },
            classList: classList(cycle === 'monthly' ? ['bg-white'] : ['text-gray-500']),
            getAttribute(name) { return this.attrs[name]; },
            setAttribute(name, value) { this.attrs[name] = value; },
        };
        return button;
    });
    const root = {
        setAttribute(name, value) { attrs[name] = value; },
        attrs,
        prices,
        buttons,
        querySelectorAll(selector) { return selector.includes('price') ? prices : buttons; },
    };
    buttons.forEach(button => { button.closest = selector => (selector === '[data-yk-pricing]' ? root : button); });
    return root;
}

test('switching to yearly shows yearly prices and marks the pressed button', () => {
    const root = fakeRoot();
    pricing.handleClick({ target: root.buttons[1] });

    assert.equal(root.attrs['data-yk-pricing-cycle'], 'yearly');
    assert.deepEqual(root.prices.map(p => p.hidden), [true, false]);
    assert.deepEqual(root.buttons.map(b => b.getAttribute('aria-pressed')), ['false', 'true']);
    assert.equal(root.buttons[1].classList.contains('bg-white'), true);
    assert.equal(root.buttons[0].classList.contains('bg-white'), false);

    pricing.handleClick({ target: root.buttons[0] });
    assert.deepEqual(root.prices.map(p => p.hidden), [false, true]);
});

test('clicks outside a pricing switch are ignored', () => {
    assert.doesNotThrow(() => pricing.handleClick({ target: { closest: () => null } }));
    assert.doesNotThrow(() => pricing.handleClick({ target: null }));
});

// 编辑器套餐方法：yikai-builder 作者端模块
const { methods } = require('../../plugins/yikai-builder/assets/blox-pro-pricing.js');

function editor(plans) {
    return Object.assign({
        selEl: { type: 'pricing-table', data: plans === undefined ? {} : { plans } },
        elSchema: () => ({ controls: [{ key: 'plans', max: 3, default: [{ name: 'Seed', price: '1', featured: true }] }] }),
    }, methods);
}

test('plan editing starts from the seed plans and stores a full copy', () => {
    const app = editor();
    assert.equal(app.pricingPlans()[0].name, 'Seed');
    app.setPricingPlan(0, 'price', 99);
    assert.equal(app.selEl.data.plans[0].price, '99');
    assert.equal(app.selEl.data.plans[0].featured, true);
    app.setPricingPlan(0, 'unknown_field', 'x');
    assert.equal('unknown_field' in app.selEl.data.plans[0], false);
});

test('plans can be added up to the limit, reordered and deleted but never emptied', () => {
    const app = editor([{ name: 'A', period: '/ mo', button_text: 'Buy' }, { name: 'B' }]);
    app.addPricingPlan('New');
    assert.deepEqual(app.pricingPlans().map(p => p.name), ['A', 'B', 'New']);
    assert.equal(app.pricingPlans()[2].button_text, '');
    app.addPricingPlan('Too many');
    assert.equal(app.pricingPlans().length, 3, 'respects the control max');
    app.movePricingPlan(2, -2);
    assert.deepEqual(app.pricingPlans().map(p => p.name), ['New', 'A', 'B']);
    app.deletePricingPlan(0);
    app.deletePricingPlan(0);
    app.deletePricingPlan(0);
    assert.deepEqual(app.pricingPlans().map(p => p.name), ['B']);
});
