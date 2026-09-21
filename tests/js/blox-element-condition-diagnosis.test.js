const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function fixture(fetcher, reports = [{ matched: false, groups: [] }]) {
    const window = { BloxProEditorData: { conditionText: {
        stale: 'stale', diagnoseFailed: 'failed', invalid: 'invalid'
    } } };
    let selector;
    class DOMParser {
        parseFromString() {
            return { querySelectorAll: value => {
                selector = value;
                return reports.map(report => ({ hasAttribute: () => true,
                    getAttribute: () => JSON.stringify(report) }));
            } };
        }
    }
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../plugins/yikai-builder/assets/blox-pro-editor.js'), 'utf8'),
        { window, URLSearchParams, DOMParser, fetch: fetcher });
    const editor = Object.assign({}, window.BloxProEditor.methods, {
        selEl: { data: {} }, selectedSi: 0, currentPath: '0.0.0',
        selectedPath() { return this.currentPath; }, conditionTarget() { return {}; },
        currentDocument: '{"sections":[]}', documentData() { return this.currentDocument; },
        previewEndpoint: '/admin/blox_preview.php?home=1', csrf: 'csrf', displayConditionsEnabled: true,
    });
    return { editor, selector: () => selector };
}
const ok = () => Promise.resolve({ ok: true, text: async () => '<html></html>' });

test('diagnosis posts the unsaved document through preview without saving; reads exact node', async () => {
    let sent;
    const { editor, selector } = fixture(async (url, options) => { sent = { url, options }; return ok(); });
    editor.articleTemplateMode = true;
    editor.articlePreviewId = 'edge:empty';
    await editor.diagnoseElementConditions();
    assert.equal(sent.url, editor.previewEndpoint);
    assert.equal(sent.options.body.get('action'), 'preview');
    assert.equal(sent.options.body.get('_token'), 'csrf');
    assert.equal(sent.options.body.get('condition_diagnostics'), '1');
    assert.equal(sent.options.body.get('blocks_data'), editor.currentDocument);
    assert.equal(sent.options.body.get('preview_article'), 'edge:empty');
    assert.equal(selector(), '[data-yk-el="0.0.0"]');
    assert.equal(editor.elementConditionReport.matched, false);
    assert.equal(editor.elementConditionStale(), false);
    assert.equal(editor.elementConditionBusy, false);
});

test('selection, document, endpoint and sample changes invalidate results', async () => {
    for (const change of [e => e.currentPath = '0.0.1', e => e.currentDocument = '{}',
        e => e.previewEndpoint += '&_lang=ja', e => e.productPreviewId = 2]) {
        const { editor } = fixture(ok);
        editor.productTemplateMode = true;
        editor.productPreviewId = 1;
        await editor.diagnoseElementConditions();
        change(editor);
        assert.equal(editor.elementConditionStale(), true);
    }
});

test('a response for a changed selection is discarded', async () => {
    let resolve;
    const { editor } = fixture(() => new Promise(done => { resolve = done; }));
    const pending = editor.diagnoseElementConditions();
    editor.currentPath = '1.0.0';
    resolve(await ok());
    await pending;
    assert.equal(editor.elementConditionReport, null);
    assert.equal(editor.elementConditionError, 'stale');
});

test('late older requests cannot overwrite a newer result or loading state', async () => {
    const pending = [];
    const { editor } = fixture(() => new Promise(done => pending.push(done)));
    const older = editor.diagnoseElementConditions();
    const newer = editor.diagnoseElementConditions();
    pending[1](await ok());
    await newer;
    const result = editor.elementConditionReport;
    pending[0]({ ok: false });
    await older;
    assert.equal(editor.elementConditionReport, result);
    assert.equal(editor.elementConditionError, '');
    assert.equal(editor.elementConditionBusy, false);
});

test('ambiguous, missing, malformed and failed previews never produce a verdict', async () => {
    for (const reports of [[], [{ matched: true, groups: [] }, { matched: true, groups: [] }], [{}], [null]]) {
        const { editor } = fixture(ok, reports);
        await editor.diagnoseElementConditions();
        assert.equal(editor.elementConditionReport, null);
        assert.ok(editor.elementConditionError);
    }
    const { editor } = fixture(async () => { throw new Error('private server details'); });
    await editor.diagnoseElementConditions();
    assert.equal(editor.elementConditionError, 'failed');
    assert.equal(editor.elementConditionBusy, false);
});

test('sections and nested elements use separate exact selectors; disabled feature sends nothing', async () => {
    let calls = 0;
    const { editor, selector } = fixture(async () => { calls++; return ok(); });
    editor.selEl = null;
    editor.selectedSi = 2;
    await editor.diagnoseElementConditions();
    assert.equal(selector(), '[data-yk-sec="2"]');
    editor.selEl = { data: {} };
    editor.currentPath = '0.0.0.1.2';
    await editor.diagnoseElementConditions();
    assert.equal(selector(), '[data-yk-el="0.0.0.1.2"]');
    editor.displayConditionsEnabled = false;
    await editor.diagnoseElementConditions();
    assert.equal(calls, 2);
});
