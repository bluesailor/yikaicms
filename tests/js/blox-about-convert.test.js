const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const editor = fs.readFileSync(path.resolve(__dirname, '../../admin/blox_editor.php'), 'utf8');
const body = editor.match(/async convertHomeAbout\(useSiteDefaults = false\) \{([\s\S]*?)\n            \},/)[1]
    .replace(/<\?=([\s\S]*?)\?>/g, '"conflict"');

function fixture(options = {}) {
    const node = { id: 'old', type: 'home-block', data: { block_type: 'about', override_title: 'Local title' } };
    const sibling = { id: 'keep', type: 'text', data: { html: 'Keep me' } };
    const section = { id: 'section', name: 'About', settings: { anchor_id: 'company' }, columns: [{ elements: options.sibling ? [node, sibling] : [node] }] };
    const result = { id: 'native', settings: { gap: 'xl' }, columns: [{ elements: [] }, { elements: [] }] };
    const state = {
        selEl: node, selectedSi: 0, selectedCi: 0, selectedEi: 0, sections: [section], csrf: 'test',
        homeText: { actionFailed: 'failed' }, commands: [], messages: [],
        historyData() { return JSON.stringify(this.sections); },
        runCommand(name, fn) { this.commands.push(name); fn.call(this); },
        selectSection(si) { this.selectedSi = si; },
        toast(message) { this.messages.push(message); },
    };
    const convert = vm.runInNewContext('(async function(useSiteDefaults = false){' + body + '})', {
        FormData,
        fetch: async (_, request) => {
            state.request = JSON.parse(request.body.get('block_data'));
            if (options.changed) state.sections[0].name = 'Edited while waiting';
            return { ok: !options.failed, json: async () => ({ code: options.failed ? 1 : 0, data: { section: result } }) };
        },
    });
    return { state, sibling, convert: defaults => convert.call(state, defaults) };
}

test('conversion is one undo command and retains section identity and anchor', async () => {
    const f = fixture();
    await f.convert();
    assert.deepEqual(f.state.commands, ['convert-home-about']);
    assert.equal(f.state.sections.length, 1);
    assert.equal(f.state.sections[0].columns.length, 2);
    assert.equal(f.state.sections[0].id, 'section');
    assert.equal(f.state.sections[0].settings.anchor_id, 'company');
    assert.equal(f.state.request.override_title, 'Local title');
    assert.equal(f.state.convertingHomeAbout, false);
});

test('conversion preserves sibling content in the original section', async () => {
    const f = fixture({ sibling: true });
    await f.convert();
    assert.deepEqual(f.state.sections[0].columns[0].elements, [f.sibling]);
    assert.equal(f.state.sections.length, 2);
    assert.equal(f.state.selectedSi, 1);
});

test('choosing a new About source snapshots site defaults', async () => {
    const f = fixture();
    await f.convert(true);
    assert.deepEqual(f.state.request, { block_type: 'about', enabled: true });
});

test('network failure or concurrent edits never overwrite the document', async () => {
    for (const options of [{ changed: true }, { failed: true }]) {
        const f = fixture(options);
        await f.convert();
        assert.equal(f.state.commands.length, 0);
        assert.equal(f.state.sections[0].columns[0].elements[0].type, 'home-block');
        assert.equal(f.state.convertingHomeAbout, false);
        assert.equal(f.state.messages.length, 1);
    }
});
