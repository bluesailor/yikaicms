const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.resolve(__dirname, '../../admin/blox_editor/partials/media-editing-methods.php'), 'utf8');
const start = source.indexOf('            closeRte() {');
const end = source.indexOf('\n            /**', start);
assert.ok(start > 0 && end > start, 'Rich-text dialog methods are present');

function fixture() {
    const calls = [];
    const editor = {
        getContent: () => '<p class="text-center">Saved</p>',
        remove: () => calls.push('remove'),
    };
    const tinymce = { get: () => editor };
    const state = vm.runInNewContext('({' + source.slice(start, end) + '})', {
        window: { tinymce }, tinymce,
    });
    Object.assign(state, {
        rteOpen: true, _rteInited: true,
        _rteTarget: (value) => calls.push(value),
        $refs: { rteDialog: {} },
        releaseDialog: () => calls.push('release'),
    });
    return { state, calls };
}

test('closing rich text removes detached popups and never applies cancelled content', () => {
    const { state, calls } = fixture();
    state.closeRte();
    assert.deepEqual(calls, ['remove', 'release']);
    assert.equal(state.rteOpen, false);
    assert.equal(state._rteInited, false);
    assert.equal(state._rteTarget, null);
    state.closeRte();
    assert.equal(calls.length, 2);
});

test('applying rich text writes once before removing the editor', () => {
    const { state, calls } = fixture();
    state.saveRte();
    assert.deepEqual(calls, ['<p class="text-center">Saved</p>', 'remove', 'release']);
    state.saveRte();
    assert.equal(calls.length, 3);
});

test('alignment controls use sanitizer-compatible classes instead of inline styles', () => {
    const match = source.match(/formats:\s*(\{\s*alignleft:[\s\S]*?\n\s*\}),\s*content_style:/);
    assert.ok(match, 'TinyMCE alignment formats are configured');
    const formats = vm.runInNewContext('(' + match[1] + ')');
    for (const align of ['left', 'center', 'right']) {
        assert.equal(formats['align' + align].classes, 'text-' + align);
        assert.equal(formats['align' + align].styles, undefined);
    }
});
