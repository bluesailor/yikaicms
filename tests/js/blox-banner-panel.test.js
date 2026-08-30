const test = require('node:test');
const assert = require('node:assert/strict');
const panel = require('../../assets/js/blox-banner-panel');
const banner = { type: 'home-block', data: { block_type: 'banner' } };
const controls = ['banner_height_mode', 'banner_mobile_mode', 'banner_autoplay', 'banner_speed', 'label'].map(key => ({ key }));

test('banner groups partition controls without changing data or order', () => {
    const original = JSON.stringify(controls);
    assert.deepEqual(panel.controls(banner, controls, 'common', false).map(c => c.key), ['banner_height_mode', 'banner_mobile_mode', 'label']);
    assert.deepEqual(panel.controls(banner, controls, 'playback', false).map(c => c.key), ['banner_autoplay']);
    assert.deepEqual(panel.controls(banner, controls, 'motion', false).map(c => c.key), ['banner_speed']);
    assert.equal(JSON.stringify(controls), original);
});

test('search and modified-only include every group; unrelated elements stay unchanged', () => {
    assert.equal(panel.controls(banner, controls, 'common', true), controls);
    assert.equal(panel.controls({ type: 'home-block', data: { block_type: 'about' } }, controls, 'common', false), controls);
    assert.equal(panel.controls(null, controls, 'common', false), controls);
});

test('slide content prioritizes image and text; links and motion retain all fields', () => {
    const slide = { type: 'home-banner-item', data: {} };
    const fields = ['title', 'subtitle', 'content_motion', 'image', 'image_mobile', 'background_motion', 'btn1_text', 'btn1_url', 'btn2_text', 'btn2_url', 'link_url', 'link_target'].map(key => ({ key }));
    assert.deepEqual(panel.controls(slide, fields, 'common', false).map(c => c.key), ['image', 'title', 'subtitle', 'image_mobile']);
    const all = ['common', 'playback', 'motion'].flatMap(group => panel.controls(slide, fields, group, false));
    assert.deepEqual(all.map(c => c.key).sort(), fields.map(c => c.key).sort());
    assert.equal(panel.controls(slide, fields, 'common', true), fields);
});
