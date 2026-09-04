const test = require('node:test');
const assert = require('node:assert/strict');
const panel = require('../../assets/js/blox-banner-panel');
const banner = { type: 'home-block', data: { block_type: 'banner' } };
const controls = ['banner_height_mode', 'banner_mobile_mode', 'banner_autoplay', 'banner_speed', 'label'].map(key => ({ key }));

test('banner groups partition controls without changing data or order', () => {
    const original = JSON.stringify(controls);
    assert.deepEqual(panel.controls(banner, controls, 'common', false).map(c => c.key), ['banner_height_mode', 'label']);
    assert.deepEqual(panel.controls(banner, controls, 'mobile', false).map(c => c.key), ['banner_mobile_mode']);
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
    const fields = ['title', 'subtitle', 'content_motion', 'media_type', 'video', 'image', 'image_mobile', 'video_mobile_mode', 'background_motion', 'btn1_text', 'btn1_url', 'btn2_text', 'btn2_url', 'link_url', 'link_target'].map(key => ({ key }));
    assert.deepEqual(panel.controls(slide, fields, 'common', false).map(c => c.key), ['media_type', 'image', 'title', 'subtitle']);
    const all = ['common', 'playback', 'motion', 'mobile'].flatMap(group => panel.controls(slide, fields, group, false));
    assert.deepEqual(all.map(c => c.key).sort(), fields.filter(c => !['video', 'video_mobile_mode'].includes(c.key)).map(c => c.key).sort());
    assert.equal(panel.controls(slide, fields, 'common', true), fields);

    slide.data.media_type = 'video';
    assert.deepEqual(panel.controls(slide, fields, 'common', false).map(c => c.key), ['media_type', 'video', 'image', 'title', 'subtitle']);
    assert.deepEqual(panel.controls(slide, fields, 'mobile', false).map(c => c.key), ['image_mobile', 'video_mobile_mode']);
});

test('mobile thumbnail fallback never materializes an override in the document', () => {
    const context = { selEl: { type: 'home-banner-item', data: { image: '/desktop.jpg', image_mobile: '' } } };
    assert.equal(panel.methods.bannerImageUrl.call(context, 'image_mobile'), '/desktop.jpg');
    assert.equal(context.selEl.data.image_mobile, '');
    context.selEl.data.image_mobile = '/mobile.jpg';
    assert.equal(panel.methods.bannerImageUrl.call(context, 'image_mobile'), '/mobile.jpg');
    assert.equal(panel.methods.bannerImageUrl.call(context, 'image'), '/desktop.jpg');
});

test('banner image picker can switch the main slide to video without changing mobile or poster pickers', () => {
    const node = { type: 'home-banner-item', data: { media_type: 'image', image: '/desktop.jpg', image_mobile: '', video: '' } };
    let callback;
    let options;
    const context = {
        selEl: node,
        openMedia: (apply, opts) => { callback = apply; options = opts; },
        runCommand: (_name, fn) => fn.call(context),
    };
    panel.methods.replaceBannerControlImage.call(context, 'image_mobile');
    assert.deepEqual(options, {});
    assert.equal(node.data.image_mobile, '');
    context.selEl = { type: 'heading', data: {} };
    callback('/wrong.jpg');
    assert.equal(node.data.image_mobile, '');
    context.selEl = node;
    panel.methods.replaceBannerControlImage.call(context, 'image');
    assert.equal(options.usage, 'hero-bg');
    assert.equal(typeof options.targets.image, 'function');
    assert.equal(typeof options.targets.video, 'function');
    callback('/replacement.jpg');
    assert.equal(node.data.image, '/replacement.jpg');
    assert.equal(node.data.media_type, 'image');
    options.targets.video('/uploads/videos/launch.mp4');
    assert.equal(node.data.media_type, 'video');
    assert.equal(node.data.video, '/uploads/videos/launch.mp4');

    panel.methods.replaceBannerControlImage.call(context, 'image');
    assert.deepEqual(options, {});
    callback('/poster.jpg');
    assert.equal(node.data.media_type, 'video');
    assert.equal(node.data.image, '/poster.jpg');
});

test('video picker can browse both media types and applies the selected type', () => {
    const node = { type: 'home-banner-item', data: { media_type: 'image', video: '' } };
    let callback;
    let options;
    const context = {
        selEl: node,
        openMedia: (apply, opts) => { callback = apply; options = opts; },
        runCommand: (_name, fn) => fn.call(context),
    };
    panel.methods.replaceBannerControlVideo.call(context);
    assert.equal(options.type, 'video');
    assert.equal(options.usage, 'hero-bg');
    assert.equal(typeof options.targets.image, 'function');
    assert.equal(typeof options.targets.video, 'function');
    callback('/uploads/videos/launch.mp4');
    assert.equal(node.data.media_type, 'video');
    assert.equal(node.data.video, '/uploads/videos/launch.mp4');
    options.targets.image('/replacement.jpg');
    assert.equal(node.data.media_type, 'image');
    assert.equal(node.data.image, '/replacement.jpg');
});

test('invalid indices and directions cannot mutate slides', () => {
    const context = {
        hasCustomBannerItems: () => true,
        bannerItems: () => [{ id: 'one', data: {} }],
        runCommand: () => { throw new Error('invalid action reached the mutation'); },
    };
    panel.methods.moveBannerItem.call(context, 0, -1);
    panel.methods.moveBannerItem.call(context, 0, 1);
    panel.methods.moveBannerItem.call(context, 0, 0);
    panel.methods.duplicateBannerItem.call(context, -1);
    panel.methods.deleteBannerItem.call(context, 2);
});

test('inherited runtime is displayed accurately and only materialized on editing a setting', () => {
    const context = Object.assign({}, panel.methods, {
        isHomeBannerHost: node => node && node.type === 'home-block',
        selEl: { type: 'home-block', data: { banner_height_mode: 'inherit', banner_mobile_mode: 'hidden', banner_height_mobile: 280 } },
        homeBannerRuntime: { banner_height_mode: 'screen', banner_mobile_mode: 'inherit', banner_height_mobile: 250, banner_autoplay: 8, banner_speed: 900 },
    });
    const before = JSON.stringify(context.selEl.data);
    assert.equal(context.bannerControlValue('banner_autoplay', 5), 8);
    assert.equal(context.bannerControlValue('banner_height_mode', 'inherit'), 'inherit');
    assert.equal(context.bannerControlValue('banner_height_mobile', 280), 280);
    context.prepareBannerControlEdit('banner_mobile_mode', 'fixed');
    assert.equal(JSON.stringify(context.selEl.data), before);
    context.prepareBannerControlEdit('banner_speed', 1000);
    assert.equal(context.selEl.data.banner_height_mode, 'screen');
    assert.equal(context.selEl.data.banner_autoplay, 8);
    assert.equal(context.selEl.data.banner_mobile_mode, 'hidden');
    assert.equal(context.selEl.data.banner_height_mobile, 280);
});
