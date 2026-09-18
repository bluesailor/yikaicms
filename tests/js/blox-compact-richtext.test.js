const test = require('node:test');
const assert = require('node:assert/strict');
const api = require('../../assets/js/blox-compact-richtext.js');

function fixture(value = { id: 'card-a', text: 'Old <b>literal</b> & text', allowLinks: false }) {
    let current = { ...value }, html = '', writes = [], removed = 0, config, pending;
    const events = {};
    const editor = {
        setContent: value => { html = value; }, getContent: () => html,
        undoManager: { clear() {}, add() {} },
        hide() {}, show() {}, focus() {}, remove() { removed++; },
        ui: { registry: { addButton() {} } },
        on(name, fn) { name.split(' ').forEach(event => events[event] = fn); }, off() {},
    };
    global.document = { documentElement: { lang: 'en' } };
    global.hugerte = { init(options) {
        config = options; options.setup(editor);
        events.init();
        return new Promise(resolve => pending = resolve);
    } };
    const state = api.create(() => current, (text, format) => {
        current = { ...current, text, format }; writes.push({ text, format });
    });
    state.$refs = { editor: {}, source: { focus() {} } };
    state.$nextTick = fn => fn();
    state.$watch = () => {};
    state.init();
    return { state, editor, events, writes,
        config: () => config, value: () => current, removed: () => removed,
        change: next => { current = next; state.sync(); },
        finish: () => pending([editor]) };
}

test('legacy plain text is escaped, including entities and line breaks', () => {
    assert.equal(api.plainHtml('<b>A & B</b>\nNext'), '<p>&lt;b&gt;A &amp; B&lt;/b&gt;<br>Next</p>');
    assert.equal(api.plainHtml(''), '');
    assert.equal(api.content({ text: '&amp;', format: 'plain' }), '<p>&amp;amp;</p>');
    assert.equal(api.content({ text: '<p>A</p>', format: 'html' }), '<p>A</p>');
});

test('initialization and source toggles do not convert or dirty old content', () => {
    const f = fixture();
    assert.match(f.editor.getContent(), /&lt;b&gt;/);
    f.events.change();
    f.state.toggleSource(); f.state.toggleSource();
    assert.equal(f.writes.length, 0);
    assert.equal(f.value().format, undefined);
    f.state.destroy();
});

test('visual edits commit text and format immediately without losing the caret to reload', () => {
    const f = fixture();
    f.editor.setContent('<p><strong>New</strong></p>'); f.events.input();
    f.state.sync(); f.events.change();
    assert.deepEqual(f.writes, [{ text: '<p><strong>New</strong></p>', format: 'html' }]);
    f.state.destroy();
});

test('source-mode edits are committed before blur and survive mode switching', () => {
    const f = fixture();
    f.state.toggleSource(); f.state.sourceInput('<p>One</p><ul><li>Two</li></ul>');
    assert.equal(f.value().format, 'html');
    assert.equal(f.value().text, '<p>One</p><ul><li>Two</li></ul>');
    f.state.toggleSource();
    assert.equal(f.editor.getContent(), f.value().text);
    f.state.destroy();
});

test('external undo updates the editor and restores the plain format', () => {
    const f = fixture({ id: 'card-a', text: 'First\nSecond', allowLinks: false });
    const original = f.editor.getContent();
    f.editor.setContent('<p><strong>First</strong><br>Second</p>'); f.events.change();
    f.editor.setContent(original); f.events.Undo();
    assert.equal(f.value().text, 'First\nSecond');
    assert.equal(f.value().format, undefined);
    f.state.destroy();
});

test('external undo restores the editor content', () => {
    const old = { id: 'card-a', text: 'Old <b>literal</b>', allowLinks: false };
    const f = fixture(old);
    f.state.sourceInput('<p><strong>New</strong></p>');
    f.change(old);
    assert.equal(f.editor.getContent(), api.plainHtml(old.text));
    assert.equal(f.writes.length, 1);
    f.state.destroy();
});

test('late callbacks cannot write to another selected element or a destroyed control', async () => {
    const f = fixture();
    f.change({ id: 'card-b', text: 'Other', allowLinks: true });
    f.editor.setContent('<p>Stale</p>'); f.events.input();
    assert.equal(f.writes.length, 0);
    f.state.destroy(); f.events.input(); f.state.sourceInput('Stale');
    f.finish(); await Promise.resolve();
    assert.equal(f.writes.length, 0);
    assert.ok(f.removed() >= 1);
});

test('configuration exposes only text formatting, with no persistent or insert toolbar', () => {
    const f = fixture(), options = f.config();
    assert.equal(options.inline, true);
    assert.equal(options.toolbar, false);
    assert.equal(options.quickbars_insert_toolbar, false);
    assert.equal(options.quickbars_image_toolbar, false);
    assert.equal(options.language, 'en');
    assert.ok(options.invalid_elements.includes('script'));
    assert.ok(!options.valid_elements.includes('img'));
    assert.ok(!options.valid_elements.includes('class'));
    f.state.destroy();
});
