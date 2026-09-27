const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
function fixture(value = 'First\n{{contact_info}}') {
    const note = {hidden: true};
    const menu = {value: '0'};
    const row = {querySelector: s => ({'.fcol-menu': menu, '.fcol-menu-notice': note})[s]};
    const input = {id: 'footer-content-0', value, dataset: {mediaLabel: 'Insert image', sourceLabel: 'Show source'}, closest: () => row, insertAdjacentElement() {}, focus() {}};
    const buttons = {};
    const editor = {dirty: false, html: '', container: {}, mode: {set(v) {this.value = v;}},
        ui: {registry: {addButton(name, options) {buttons[name] = options;}}},
        selection: {getBookmark() {return 'caret';}, moveToBookmark(value) {this.restored = value;}, setCursorLocation() {}},
        windowManager: {open(options) {this.dialog = options;}}, nodeChanged() {},
        undoManager: {transact(fn) {fn();}},
        dom: {createHTML(tag, attrs) {return '<' + tag + Object.entries(attrs).map(([k,v]) => ' ' + k + '="' + v.replace(/&/g, '&amp;').replace(/"/g, '&quot;') + '"').join('') + '>'; }},
        focus() {}, insertContent(html) {this.html += html;},
        on(name, fn) {this[name] = fn;}, setContent(html) {this.html = html;}, setDirty(v) {this.dirty = v;},
        getContent() {return this.html;}, isDirty() {return this.dirty;}, getContainer() {return this.container;}};
    const window = {editorLanguage: () => '', hugerte: {init(options) {options.setup(editor); return Promise.resolve();}}};
    const document = {documentElement: {lang: 'en'}, querySelectorAll: () => [input], createElement: () => ({remove() {}})};
    vm.runInNewContext(fs.readFileSync(path.resolve(__dirname, '../../assets/js/footer-content-editor.js'), 'utf8'), {window, document, WeakMap});
    const source = (code, cancel = false) => {
        buttons.footersource.onAction();
        const dialog = editor.windowManager.dialog;
        if (cancel) dialog.onClose();
        else dialog.onSubmit({getData: () => ({code: code ?? dialog.initialData.code}), close: () => dialog.onClose()});
    };
    return {api: window.YikaiFooterEditor, input, editor, menu, note, source, buttons, window};
}
test('opening preserves exact legacy text and placeholder bytes', () => {
    const f = fixture(); f.editor.init();
    assert.equal(f.editor.html, 'First<br>{{contact_info}}');
    assert.equal(f.api.read(f.input), 'First\n{{contact_info}}');
    assert.equal(f.api.editorHtml('A & < B'), 'A &amp; &lt; B');
});
test('edited rich HTML is collected even before blur', () => {
    const f = fixture(); f.editor.init();
    f.editor.html = '<p><strong>Edited</strong></p>'; f.editor.dirty = true;
    assert.equal(f.api.read(f.input), '<p><strong>Edited</strong></p>');
});
test('clear during initialization cannot revive original content', () => {
    const f = fixture(); f.api.clear(f.input); f.editor.init();
    assert.equal(f.editor.html, '');
    f.editor.html = 'Draft'; f.editor.dirty = true;
    f.api.clear(f.input);
    assert.equal(f.api.read(f.input), '');
});
test('toolbar source dialog preserves untouched legacy markup and accepts changes', () => {
    const f = fixture('<div class="legacy">{{qrcode}}</div>'); f.editor.init();
    assert.equal(f.buttons.footersource.icon, 'sourcecode');
    f.source();
    assert.equal(f.api.read(f.input), '<div class="legacy">{{qrcode}}</div>');
    f.source('Source\n{{qrcode}}');
    assert.equal(f.api.read(f.input), 'Source\n{{qrcode}}');
    assert.equal(f.editor.html, 'Source<br>{{qrcode}}');
});

test('cancel source changes leaves content untouched and allows opening again', () => {
    const f = fixture(); f.editor.init(); f.source('Discard', true);
    assert.equal(f.api.read(f.input), 'First\n{{contact_info}}');
    f.source('Accepted'); assert.equal(f.api.read(f.input), 'Accepted');
});
test('menu takeover is read-only and retains text when switched back', () => {
    const f = fixture(); f.editor.init(); f.api.menu(f.input, true);
    assert.equal(f.editor.mode.value, 'readonly'); assert.equal(f.note.hidden, false);
    f.api.menu(f.input, false);
    assert.equal(f.editor.mode.value, 'design'); assert.equal(f.note.hidden, true);
    assert.equal(f.api.read(f.input), 'First\n{{contact_info}}');
});

test('image opens the local picker, restores caret and collects image before blur', () => {
    const f = fixture(''); f.editor.init();
    f.window.openMediaPicker = (callback, options) => {
        assert.equal(options.type, 'image'); assert.equal(options.source, 'local');
        callback('/uploads/footer.png');
    };
    f.buttons.footermedia.onAction();
    assert.equal(f.editor.selection.restored, 'caret');
    assert.equal(f.api.read(f.input), '<img src="/uploads/footer.png" alt="" width="160">');
    f.source();
    assert.match(f.api.read(f.input), /footer\.png/);
});

test('cancel, unsafe URLs and menu/source takeover cannot insert an image', () => {
    for (const url of [null, 'javascript:alert(1)', 'data:image/png;base64,abc', '//example.com/a.png', '/\\example.com/a.png']) {
        const f = fixture(''); f.editor.init();
        f.window.openMediaPicker = callback => callback(url);
        f.buttons.footermedia.onAction();
        assert.equal(f.api.read(f.input), '');
    }
    const f = fixture(''); f.editor.init();
    let pending; f.window.openMediaPicker = callback => {pending = callback;};
    f.buttons.footermedia.onAction();
    f.menu.value = '1'; pending('/uploads/a.png');
    assert.equal(f.api.read(f.input), '');
    f.menu.value = '0'; f.buttons.footersource.onAction(); pending('/uploads/a.png');
    assert.equal(f.api.read(f.input), '');
});
