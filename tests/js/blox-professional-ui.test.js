const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { bloxEditorSource } = require('./helpers/editor-source');
// 展开 partial 后的入口全文：方法被抽走后只读入口会误报「方法不存在」
const source = bloxEditorSource();
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
