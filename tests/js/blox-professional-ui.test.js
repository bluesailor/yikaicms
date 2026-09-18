const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../admin/blox_editor.php'), 'utf8');
const start = source.indexOf('            styleCandidates() {');
const end = source.indexOf('            visibleCtrls() {', start);
const method = source.slice(start, end).trim().replace(/,\s*$/, '');
const windowStub = {
    BloxHomeContentPanel: { tabFor: (_, control) => control.tab || 'content' },
    BloxStyleGroups: { visibleCandidates: controls => controls, commonMarker: () => ({ key: 'common' }) },
};
const candidateFn = new Function('window', 'return ({' + method + '}).styleCandidates;')(windowStub);

test('normal settings exclude professional controls, explicit professional view includes them', () => {
    const controls = [{ key: 'text' }, { key: 'site_field', advanced: true }, { key: 'color', tab: 'style' }];
    const state = {
        selEl: { type: 'text', data: {} }, panelTab: 'content', ctrlQuery: '', modifiedOnly: false,
        elSchema: () => ({ controls }), isLoopTemplateChild: () => false,
        controlRequirementMet: () => true, siteLanguageControlApplies: () => true, isCtrlModified: () => false,
        selectedRegionKeys: () => null,
        loopItemControlHidden: () => false,
    };
    assert.deepEqual(candidateFn.call(state).map(x => x.key), ['text']);
    state.panelTab = 'professional';
    assert.deepEqual(candidateFn.call(state).map(x => x.key), ['site_field']);
    state.panelTab = 'style';
    assert.deepEqual(candidateFn.call(state).map(x => x.key), ['common', 'color']);
    state.panelTab = 'condition';
    assert.deepEqual(candidateFn.call(state), []);
});
