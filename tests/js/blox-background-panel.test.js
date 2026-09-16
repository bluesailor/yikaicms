const { test } = require('node:test');
const assert = require('node:assert/strict');
const panel = require('../../assets/js/blox-background-panel');

test('background layer state exposes the effective stored value for both visual layers', () => {
    const section = { settings: {
        bg_color: '#f8fafc',
        container_bg_image: '/themes/default/assets/images/cta/cta-smart-manufacturing.webp',
    } };
    assert.deepEqual(panel.layerState(section, 'section'), { kind: 'color', value: '#f8fafc' });
    assert.deepEqual(panel.layerState(section, 'container'), {
        kind: 'image',
        value: '/themes/default/assets/images/cta/cta-smart-manufacturing.webp',
    });
    assert.deepEqual(panel.layerState(null, 'section'), { kind: 'none', value: '' });
});

test('preferred background layer opens the layer that already carries the visible background', () => {
    assert.equal(panel.preferredLayer({ settings: { container_bg_image: '/cta.jpg' } }), 'container');
    assert.equal(panel.preferredLayer({ settings: { bg_color: '#fff', container_bg_image: '/cta.jpg' } }), 'container');
    assert.equal(panel.preferredLayer({ settings: { bg_image: '/wide.jpg', container_bg_image: '/cta.jpg' } }), 'section');
    assert.equal(panel.preferredLayer({ settings: { bg_gradient: 'linear-gradient(90deg,#000,#fff)' } }), 'section');
    assert.equal(panel.preferredLayer({ settings: {} }), 'section');
});

test('background layer navigation preserves the selected section and opens style settings', () => {
    const section = { settings: { container_bg_image: '/cta.jpg' } };
    const calls = [];
    const context = {
        sel: section,
        selectedSi: 3,
        panelTab: 'content',
        selectSection: (index, notify) => { calls.push(['section', index, notify]); context.selLayer = 'sec'; },
        selectContainer: (index, notify) => { calls.push(['container', index, notify]); context.selLayer = 'con'; context.panelTab = 'style'; },
        highlightCanvasSelection: scroll => calls.push(['highlight', scroll]),
    };
    Object.assign(context, panel.methods);
    context.openPreferredBackgroundLayer();
    assert.equal(context.selLayer, 'con');
    assert.equal(context.panelTab, 'style');
    assert.deepEqual(calls, [['container', 3, false], ['highlight', false]]);
    context.selectBackgroundLayer('section');
    assert.equal(context.selLayer, 'sec');
    assert.equal(context.panelTab, 'style');
    context.selectBackgroundLayer('invalid');
    assert.equal(calls.length, 4);
});
