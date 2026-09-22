const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
function fixture(value = 'First\n{{contact_info}}') {
    const note = {hidden: true};
    const menu = {value: '0'};
    const button = {hidden: true, dataset: {source: 'Source', visual: 'Visual'}, addEventListener(name, fn) {this[name] = fn;}, setAttribute() {}};
    const row = {querySelector: s => ({'.fcol-menu': menu, '.fcol-menu-notice': note, '.fcol-source-toggle': button})[s]};
    const input = {id: 'footer-content-0', value, closest: () => row, insertAdjacentElement() {}, focus() {}};
    const editor = {dirty: false, html: '', container: {}, mode: {set(v) {this.value = v;}},
        on(name, fn) {this[name] = fn;}, setContent(html) {this.html = html;}, setDirty(v) {this.dirty = v;},
        getContent() {return this.html;}, isDirty() {return this.dirty;}, getContainer() {return this.container;}};
    const window = {editorLanguage: () => '', hugerte: {init(options) {options.setup(editor); return Promise.resolve();}}};
    const document = {documentElement: {lang: 'en'}, querySelectorAll: () => [input], createElement: () => ({remove() {}})};
    vm.runInNewContext(fs.readFileSync(path.resolve(__dirname, '../../assets/js/footer-content-editor.js'), 'utf8'), {window, document, WeakMap});
    return {api: window.YikaiFooterEditor, input, editor, menu, note, button};
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
test('source toggle preserves untouched legacy markup', () => {
    const f = fixture('<div class="legacy">{{qrcode}}</div>'); f.editor.init();
    f.button.click(); f.input.value = 'Source\n{{qrcode}}'; f.button.click();
    assert.equal(f.api.read(f.input), 'Source\n{{qrcode}}');
    assert.equal(f.editor.html, 'Source<br>{{qrcode}}');
});
test('menu takeover is read-only and retains text when switched back', () => {
    const f = fixture(); f.editor.init(); f.api.menu(f.input, true);
    assert.equal(f.editor.mode.value, 'readonly'); assert.equal(f.note.hidden, false);
    f.api.menu(f.input, false);
    assert.equal(f.editor.mode.value, 'design'); assert.equal(f.note.hidden, true);
    assert.equal(f.api.read(f.input), 'First\n{{contact_info}}');
});
