const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.resolve(__dirname, '../../includes/builder/BloxCanvasPreview.php'), 'utf8');
const marker = "window.addEventListener('message', function (e) {";
const start = source.indexOf(marker);
const end = source.indexOf('        if (Number.isInteger(d.ykBannerSlide))', start);
assert.ok(start > 0 && end > start, 'Canvas message admission and drag handlers are present');

function fixture() {
    const parent = {};
    const context = {
        window: { parent }, editorOrigin: 'https://cms.example',
        ykDragRules: null, ykDragType: '',
        handlePaletteDragMessage: () => {},
    };
    const handle = vm.runInNewContext('(function(e){' + source.slice(start + marker.length, end) + '})', context);
    return { context, handle, event: (data) => ({ source: parent, origin: context.editorOrigin, data }) };
}

test('canvas ignores scalar and array messages without throwing', () => {
    const { context, handle, event } = fixture();
    for (const data of [null, undefined, '', 'clipboard-tool-message', 12, true, []]) {
        assert.doesNotThrow(() => handle(event(data)));
        assert.equal(context.ykDragType, '');
    }
});

test('canvas accepts structured drag messages only from its trusted parent', () => {
    const { context, handle, event } = fixture();
    handle({ ...event({ ykDragType: 'heading' }), origin: 'https://other.example' });
    handle({ ...event({ ykDragType: 'heading' }), source: {} });
    assert.equal(context.ykDragType, '');
    handle(event({ ykDragType: 'heading' }));
    assert.equal(context.ykDragType, 'heading');
    handle(event({ ykDragType: '' }));
    assert.equal(context.ykDragType, '');
});
