const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function settings(url, withSummary = true) {
    const context = { window: { location: { origin: 'https://example.test' } }, URL, URLSearchParams };
    if (withSummary) vm.runInNewContext(fs.readFileSync(path.resolve(__dirname, '../../assets/js/blox-draft-summary.js'), 'utf8'), context);
    vm.runInNewContext(fs.readFileSync(path.resolve(__dirname, '../../assets/js/blox-page-settings.js'), 'utf8'), context);
    return Object.assign(context.window.YikaiBloxPageSettings.mixin({ id: 88, url, slug: 'company', text: { invalid: 'Invalid URL' } }), {
        pagePublished: false,
        publishedDocument: null,
        docSettings: {}, sections: [],
        draftSummary() {
            return context.window.BloxDraftSummary.summarize(this.publishedDocument, { settings: this.docSettings, sections: this.sections });
        }
    });
}

test('draft preview preserves parent paths and the page language', () => {
    const state = settings('/en/about/company.html');
    const url = new URL(state.pageFrontPreviewUrl(), 'https://example.test');
    assert.equal(url.pathname, '/en/about/company.html');
    assert.equal(url.searchParams.get('preview'), 'draft');
    assert.equal(url.searchParams.get('blox_draft'), 'page:88');
});

test('draft preview preserves dynamic routing parameters', () => {
    const state = settings('/index.php?yk_route=page&slug=company&parent=about&lang=ja');
    const url = new URL(state.pageFrontPreviewUrl(), 'https://example.test');
    assert.equal(url.pathname, '/index.php');
    for (const [key, value] of Object.entries({ yk_route: 'page', slug: 'company', parent: 'about', lang: 'ja', preview: 'draft', blox_draft: 'page:88' })) {
        assert.equal(url.searchParams.get(key), value);
    }
});

test('publication state follows the current document across editing, undo and publishing', () => {
    const state = settings('/en/about/company.html');
    state.sections = [{ id: 's1', columns: [{ id: 'c1', elements: [{ id: 'e1', type: 'heading', data: { text: 'Original' } }] }] }];
    const original = JSON.stringify({ settings: state.docSettings, sections: state.sections });
    assert.equal(state.pageIsPublishedCurrent(), false);
    state.pagePublished = true;
    state.publishedDocument = JSON.parse(original);
    assert.equal(state.pageIsPublishedCurrent(), true);
    assert.equal(state.pageFrontPreviewUrl(), '/en/about/company.html');
    state.sections[0].columns[0].elements[0].data.text = 'Draft';
    assert.equal(state.pageIsPublishedCurrent(), false);
    assert.match(state.pageFrontPreviewUrl(), /preview=draft/);
    // Saving or failing publication must not change which revision is live.
    state.dirty = false;
    state.pageHasUnpublishedChanges = true;
    assert.equal(state.pageIsPublishedCurrent(), false);
    state.sections = JSON.parse(original).sections;
    assert.equal(state.pageIsPublishedCurrent(), true);
    state.docSettings.page_footer_hidden = true;
    assert.equal(state.pageIsPublishedCurrent(), false);
    state.publishedDocument = JSON.parse(JSON.stringify({ settings: state.docSettings, sections: state.sections }));
    assert.equal(state.pageIsPublishedCurrent(), true);
});

test('published dynamic links preserve language, route and fragment without preview flags', () => {
    const state = settings('/sub/index.php?yk_route=page&slug=company&lang=ja&preview=draft&blox_draft=page%3A88#contact');
    state.pagePublished = true;
    state.publishedDocument = { settings: {}, sections: [] };
    const url = new URL(state.pageFrontPreviewUrl(), 'https://example.test');
    assert.equal(url.pathname, '/sub/index.php');
    assert.equal(url.searchParams.get('yk_route'), 'page');
    assert.equal(url.searchParams.get('slug'), 'company');
    assert.equal(url.searchParams.get('lang'), 'ja');
    assert.equal(url.searchParams.has('preview'), false);
    assert.equal(url.searchParams.has('blox_draft'), false);
    assert.equal(url.hash, '#contact');
});

test('missing comparison module or published snapshot never claims the canvas is published', () => {
    for (const state of [settings('/company.html', false), settings('/company.html')]) {
        state.pagePublished = true;
        assert.equal(state.pageIsPublishedCurrent(), false);
        assert.match(state.pageFrontPreviewUrl(), /preview=draft/);
    }
    const noModule = settings('/company.html', false);
    noModule.pagePublished = true;
    noModule.publishedDocument = { sections: [] };
    assert.equal(noModule.pageIsPublishedCurrent(), false);
});

test('invalid slugs are rejected before confirmation or network access', async () => {
    const state = settings('/company.html');
    state.pageSlugDraft = '../admin';
    await state.savePageUrl();
    assert.equal(state.pageUrlError, 'Invalid URL');
    assert.equal(state.pageUrlConfirm, false);
    assert.equal(state.pageSlug, 'company');
});

test('a valid new URL requires the separate confirmation action', async () => {
    const state = settings('/company.html');
    state.pageSlugDraft = 'new-company';
    await state.savePageUrl();
    assert.equal(state.pageUrlConfirm, true);
    assert.equal(state.pageSlug, 'company');
    assert.equal(state.pageUrl, '/company.html');
});

function insertionState(insert = true) {
    return Object.assign(settings('/company.html'), {
        docSettings: {}, elements: [], commands: [], recent: [],
        historyData() { return JSON.stringify({ settings: this.docSettings, elements: this.elements }); },
        _addElementRaw(el) { if (insert) this.elements.push(el); },
        runCommand(name, action) {
            this.commands.push({ name, before: this.historyData() });
            action.call(this);
            this.commands[this.commands.length - 1].after = this.historyData();
            return { ok: true };
        },
        rememberRecentElement(type) { this.recent.push(type); }
    });
}

test('inserting a page title is one ordinary undoable element command', () => {
    const state = insertionState();
    state.addElement({ type: 'page-title' });
    assert.equal(state.commands.length, 1);
    assert.equal(state.commands[0].name, 'add-element');
    assert.deepEqual(JSON.parse(state.commands[0].after).settings, {});
    assert.equal(JSON.parse(state.commands[0].after).elements.length, 1);
    assert.deepEqual(state.recent, ['page-title']);
});

test('rejected insertions do not record a recent element', () => {
    const rejected = insertionState(false);
    rejected.addElement({ type: 'page-title' });
    assert.deepEqual(rejected.docSettings, {});
    assert.deepEqual(rejected.recent, []);
});
