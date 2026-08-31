const { test } = require('node:test');
const assert = require('node:assert/strict');
const panel = require('../../assets/js/blox-home-content-panel');
const about = { type: 'home-block', data: { block_type: 'about' } };
const cta = { type: 'home-block', data: { block_type: 'cta' } };

test('inheritance reads hints without materializing empty or absent overrides', () => {
    const seeds = { override_title: 'Original', override_image: '/original.jpg' };
    const node = { type: 'home-block', data: { block_type: 'about' } };
    const before = JSON.stringify({ node, seeds });
    assert.deepEqual(panel.fieldState(node, 'override_image', seeds), { inherited: true, value: '/original.jpg' });
    assert.equal(JSON.stringify({ node, seeds }), before);
    for (const value of ['', ' \t\n\r\v\0']) {
        node.data.override_title = value;
        assert.equal(panel.fieldState(node, 'override_title', seeds).inherited, true);
    }
    for (const value of ['0', '\u00a0', 'Custom']) {
        node.data.override_title = value;
        assert.deepEqual(panel.fieldState(node, 'override_title', seeds), { inherited: false, value });
    }
    assert.equal(panel.fieldState(cta, 'override_title', seeds), null);
    assert.equal(panel.fieldState(node, '__proto__', seeds), null);
    assert.equal(panel.fieldState(node, 'override_image', {}), null);
});

test('reset changes only the chosen field and remains one reversible command', () => {
    const node = { type: 'home-block', data: { block_type: 'about', override_title: 'Draft', override_image: '/custom.jpg' } };
    let before, commands = 0;
    const captures = [];
    const context = Object.assign({ selEl: node, homeFieldSeeds: { about: { override_title: 'Original', override_image: '/original.jpg' } },
        flushHistory: capture => { assert.equal(capture, true); captures.push(node.data.override_image); },
        runCommand: (_name, change) => { before = JSON.stringify(node.data); commands++; change(); } }, panel.methods);
    assert.equal(context.homeContentPlaceholder({ key: 'override_title', placeholder: 'Label' }), 'Label');
    assert.equal(context.homeContentImageValue('override_image'), '/custom.jpg');
    context.inheritHomeContentField('override_image');
    assert.equal(commands, 1);
    assert.deepEqual(captures, ['/custom.jpg', '']);
    assert.equal(node.data.override_title, 'Draft');
    assert.equal(node.data.override_image, '');
    assert.equal(context.homeContentImageValue('override_image'), '/original.jpg');
    context.inheritHomeContentField('override_image');
    context.inheritHomeContentField('block_type');
    assert.equal(commands, 1);
    node.data = JSON.parse(before);
    assert.equal(context.homeContentImageValue('override_image'), '/custom.jpg');
    node.data.override_title = '';
    assert.equal(context.homeContentPlaceholder({ key: 'override_title' }), 'Original');
    assert.equal(node.data.override_title, '');
});

test('home content groups partition controls without mutating source data', () => {
    const list = ['block_type', 'override_layout', 'override_title', 'title_decor_style', 'override_content', 'override_image', 'override_button_url', 'enabled', 'future_setting'].map(key => ({ key }));
    const before = JSON.stringify({ about, list });
    const groups = ['content', 'media', 'layout', 'more'].map(group => panel.controls(about, list, group, false));
    assert.deepEqual(groups[0].map(control => control.key), ['override_title', 'override_content', 'override_button_url']);
    assert.equal(groups.flat().length, list.length);
    assert.equal(new Set(groups.flat()).size, list.length);
    assert.equal(groups[3].at(-1).key, 'future_setting');
    assert.equal(JSON.stringify({ about, list }), before);
    assert.equal(panel.controls(about, list, 'media', true), list);
    assert.equal(panel.controls({ type: 'heading' }, list, 'media', false), list);
    assert.equal(panel.supports({ type: 'home-block', data: { block_type: 'banner' } }), false);
});

test('CTA background controls are discoverable together without changing schema tabs', () => {
    for (const key of ['bg_image', 'bg_color', 'bg_overlay_color', 'bg_overlay_opacity', 'text_light']) {
        const control = { key, tab: 'style' };
        assert.equal(panel.tabFor(cta, control), 'content');
        assert.equal(panel.groupFor(key), 'media');
        assert.equal(panel.tabFor(about, control), 'style');
        assert.equal(control.tab, 'style');
    }
    assert.equal(panel.tabFor(about, { key: 'title_decor_color', type: 'color', tab: 'content' }), 'content');
    assert.equal(panel.tabFor(null, { key: 'color', type: 'color' }), 'style');
});

test('switching groups clears field focus only and keeps document data intact', () => {
    const context = { selEl: about, selectedHomeField: 'override_image', selectedHomeColumn: 'image', homeContentGroup: 'media' };
    panel.methods.setHomeContentGroup.call(context, 'layout');
    assert.equal(context.homeContentGroup, 'layout');
    assert.equal(context.selectedHomeField, '');
    assert.equal(context.selectedHomeColumn, '');
    assert.equal(context.selEl, about);
    panel.methods.setHomeContentGroup.call(context, 'invalid');
    assert.equal(context.homeContentGroup, 'layout');
});

test('media selection uses existing source policy and rejects stale callbacks', () => {
    const node = { type: 'home-block', data: { block_type: 'cta', bg_image: '/old.jpg' } };
    let callback, options, commands = 0;
    const context = { selEl: node, openMedia: (fn, opts) => { callback = fn; options = opts; }, runCommand: (_name, fn) => { commands++; fn.call(context); } };
    panel.methods.replaceHomeContentImage.call(context, 'bg_image');
    assert.deepEqual(options, { usage: 'cta', source: 'official' });
    assert.equal(node.data.bg_image, '/old.jpg');
    context.selEl = about;
    callback('/wrong.jpg');
    assert.equal(commands, 0);
    assert.equal(node.data.bg_image, '/old.jpg');
    context.selEl = node;
    panel.methods.replaceHomeContentImage.call(context, 'bg_image');
    callback('/new.jpg');
    assert.equal(node.data.bg_image, '/new.jpg');
    assert.equal(commands, 1);
    context.selEl = about;
    panel.methods.replaceHomeContentImage.call(context, 'override_image');
    assert.deepEqual(options, {});
    assert.equal(panel.isImage(about, 'bg_image'), false);
});
