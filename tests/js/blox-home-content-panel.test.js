const { test } = require('node:test');
const assert = require('node:assert/strict');
const panel = require('../../assets/js/blox-home-content-panel');
const about = { type: 'home-block', data: { block_type: 'about' } };
const cta = { type: 'home-block', data: { block_type: 'cta' } };

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
