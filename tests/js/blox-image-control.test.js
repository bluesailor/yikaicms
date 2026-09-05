const { test } = require('node:test');
const assert = require('node:assert/strict');
const { methods } = require('../../assets/js/blox-image-control');

function editor() {
    const app = Object.assign({
        sel: { settings: {} }, selEl: { data: {} }, col: {}, snapshots: [], flushes: 0,
        selectedCol() { return this.col; },
        flushHistory() { this.flushes++; },
        runCommand(_name, change) { this.snapshots.push(JSON.stringify([this.sel, this.selEl, this.col])); change(); },
        openMedia(callback, options) { this.pick = callback; this.options = options; },
    }, methods);
    return app;
}

for (const [scope, key] of [['element', 'src'], ['section', 'bg_image'], ['container', 'container_bg_image'], ['column', 'card_bg_image']]) {
    test(`${scope}: replace/clear are isolated undo actions; a stale picker cannot change another target`, () => {
        const app = editor();
        app.pickImageControl(scope, key);
        assert.deepEqual(app.options, scope === 'element' ? {} : { usage: 'hero-bg' });
        app.pick('/image.jpg');
        assert.equal(app.imageControlValue(scope, key), '/image.jpg');
        app.setImageControl(scope, key, '');
        assert.equal(app.imageControlValue(scope, key), '');
        assert.equal(app.snapshots.length, 2);
        assert.equal(app.flushes, 4);
        [app.sel, app.selEl, app.col] = JSON.parse(app.snapshots.pop());
        assert.equal(app.imageControlValue(scope, key), '/image.jpg');
        app.setImageControl(scope, key, '/image.jpg');
        assert.equal(app.snapshots.length, 1);
        app.pickImageControl(scope, key);
        app.sel = { settings: {} }; app.selEl = { data: {} }; app.col = {};
        app.pick('/wrong-target.jpg');
        assert.equal(app.imageControlValue(scope, key), '');
    });
}

test('background defaults are applied once without replacing explicit zero opacity', () => {
    const app = editor();
    app.sel.settings.bg_overlay_opacity = 0;
    app.setSectionBackgroundImage('/a.jpg');
    assert.equal(app.sel.settings.bg_overlay_opacity, 0);
    assert.equal(app.sel.settings.bg_overlay_color, '#000000');
    assert.equal(app.sel.settings.bg_position, 'center');
    app.setContainerBackgroundImage('/b.jpg');
    assert.equal(app.sel.settings.container_bg_overlay_opacity, 45);
    app.setColumnBackgroundImage('/c.jpg');
    assert.equal(app.col.card_bg_overlay_opacity, 45);
    app.setImageControl('section', 'bg_overlay_opacity', '0');
    app.setImageControl('element', '__proto__', '/a.jpg');
    assert.equal(app.snapshots.length, 3);
});

test('typing updates the document immediately but leaves history batching to the editor', () => {
    const app = editor();
    app.setImageControl('element', 'src', '/typed.jpg', false);
    assert.equal(app.selEl.data.src, '/typed.jpg');
    assert.equal(app.flushes, 0);
});

for (const [scope, key] of [['section', 'bg_video'], ['element', 'bg_video']]) {
    test(`${scope}: video picker is type-scoped and ignores a stale target`, () => {
        const app = editor();
        app.pickVideoControl(scope, key);
        assert.deepEqual(app.options, { type: 'video' });
        app.pick('/uploads/videos/background.mp4');
        assert.equal(app.videoControlValue(scope, key), '/uploads/videos/background.mp4');
        app.pickVideoControl(scope, key);
        app.sel = { settings: {} }; app.selEl = { data: {} };
        app.pick('/uploads/videos/wrong-target.mp4');
        assert.equal(app.videoControlValue(scope, key), '');
    });
}

test('section video detects and clears child backgrounds without changing its own media', () => {
    const app = editor();
    app.backgroundVideoObstructionText = { cleared: 'cleared :count' };
    app.toast = (message) => { app.message = message; };
    app.sel = {
        settings: {
            bg_video: '/uploads/videos/parent.mp4',
            bg_image: '/uploads/parent-poster.jpg',
            bg_overlay_opacity: 40,
            container_bg: '#ffffff',
            container_bg_overlay_opacity: 45,
        },
        columns: [{
            card_bg_image: '/uploads/column.jpg',
            card_bg_overlay_color: '#000000',
            card_bg_overlay_opacity: 30,
            elements: [{
                type: 'home-block',
                data: {
                    bg_color: '#f8fafc',
                    bg_overlay_color: '#111827',
                    bg_overlay_opacity: 55,
                    children: [{
                        type: 'container',
                        data: { bg_gradient: 'linear-gradient(red, blue)', bg_overlay: '60' },
                    }],
                },
            }],
        }],
    };

    assert.equal(app.sectionBackgroundVideoObstructionCount(), 4);
    assert.equal(app.clearSectionBackgroundVideoObstructions(), 4);
    assert.equal(app.sel.settings.bg_video, '/uploads/videos/parent.mp4');
    assert.equal(app.sel.settings.bg_image, '/uploads/parent-poster.jpg');
    assert.equal(app.sel.settings.bg_overlay_opacity, 40);
    assert.equal(app.sel.settings.container_bg, '');
    assert.equal(app.sel.settings.container_bg_overlay_opacity, 0);
    assert.equal(app.sel.columns[0].card_bg_image, '');
    assert.equal(app.sel.columns[0].card_bg_overlay_color, '');
    assert.equal(app.sel.columns[0].elements[0].data.bg_color, '');
    assert.equal(app.sel.columns[0].elements[0].data.bg_overlay_opacity, 0);
    assert.equal(app.sel.columns[0].elements[0].data.children[0].data.bg_gradient, '');
    assert.equal(app.sel.columns[0].elements[0].data.children[0].data.bg_overlay, '');
    assert.equal(app.sectionBackgroundVideoObstructionCount(), 0);
    assert.equal(app.flushes, 2);
    assert.equal(app.message, 'cleared 4');
});

test('child backgrounds are ignored until the selected section has a video', () => {
    const app = editor();
    app.sel = {
        settings: {},
        columns: [{ elements: [{ type: 'home-block', data: { bg_image: '/child.jpg' } }] }],
    };
    assert.equal(app.sectionBackgroundVideoObstructionCount(), 0);
    assert.equal(app.clearSectionBackgroundVideoObstructions(), 0);
    assert.equal(app.sel.columns[0].elements[0].data.bg_image, '/child.jpg');
});
