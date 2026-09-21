const test = require('node:test');
const assert = require('node:assert/strict');
const conditions = require('../../assets/js/blox-detail-conditions');
const { bloxEditorSource } = require('./helpers/editor-source');

// 这些方法是 Alpine 组件对象上的普通方法；按源码原样取出，挂到最小宿主上执行，
// 避免在测试里另写一份"看起来一样"的实现。
// 必须读展开 partial 后的全文：方法被抽进 partial 后，只读入口文件会报"方法不存在"。
const source = bloxEditorSource();

function method(name) {
    const match = source.match(new RegExp('\\n            ' + name + '\\(([^)]*)\\) \\{([\\s\\S]*?)\\n            \\},'));
    assert.ok(match, 'method not found: ' + name);
    const args = match[1].split(',').map((arg) => arg.trim()).filter(Boolean);
    return new Function('window', ...args, match[2]);
}

const bound = ['conditionEnsure', 'conditionReloadFromDocument', 'conditionLegacyBase', 'conditionPanelState',
    'conditionDirty', 'conditionProblems', 'conditionSubmitState', 'conditionSubmission', 'conditionScopeBase',
    'conditionScope', 'applyConditionSubmit', 'syncConditionDocument'];
const windowStub = { BloxDetailConditions: conditions };

function host(contentType, docSettings) {
    const self = {
        conditionContentType: contentType,
        conditionLang: 'zh-CN',
        conditionMaxPriority: 100,
        conditionRows: null,
        conditionBaseline: null,
        conditionBase: null,
        conditionPriority: 0,
        conditionOriginalScope: null,
        conditionDocumentHadV2: false,
        conditionDocumentHadScopeKey: false,
        conditionDiagnosisSeq: 0,
        conditionDiagnosis: null,
        conditionDiagnosisKey: '',
        conditionDiagnosisError: '',
        conditionDiagnosisBusy: false,
        docSettings,
        $nextTick() {},
    };
    for (const name of bound) {
        const fn = method(name);
        self[name] = function (...args) { return fn.call(self, windowStub, ...args); };
    }
    return self;
}

const savedV2 = {
    version: 2, content_type: 'article', lang: 'zh-CN', source: 'native', priority: 3,
    include: [{ kind: 'category', ids: [5], include_children: true }], exclude: [],
};

test('history/recovery reload replaces rows but keeps the saved baseline', () => {
    const editor = host('article', { detail_template: JSON.parse(JSON.stringify(savedV2)) });
    editor.conditionEnsure();
    assert.equal(editor.conditionDirty(), false, '刚打开不脏');

    // 恢复稿/撤销带回一份与服务器不同的文档
    editor.docSettings = { detail_template: { ...savedV2, priority: 9, include: [{ kind: 'all', ids: [], include_children: false }] } };
    editor.conditionDiagnosis = { verdict: 'won' };
    editor.conditionDiagnosisBusy = true;
    editor.conditionReloadFromDocument();

    assert.deepEqual(editor.conditionRows.include.map((row) => row.kind), ['all'], '面板行来自新文档');
    assert.equal(editor.conditionPriority, 9);
    assert.equal(editor.conditionBaseline.priority, 3, '已保存基线不能被恢复内容顶替');
    assert.equal(editor.conditionDirty(), true, '与服务器不同必须仍显示已修改');
    assert.equal(editor.conditionDiagnosis, null, '旧诊断作废');
    assert.equal(editor.conditionDiagnosisBusy, false);
    assert.equal(editor.conditionDiagnosisSeq, 1, '晚到的诊断响应会被序号丢弃');
    // source 不是面板可编辑项：提交沿用已保存的 native，不漂移
    assert.equal(editor.conditionScope().source, 'native');
});

test('an unfinished edit stays in the document and comes back intact', () => {
    const editor = host('article', { detail_template: JSON.parse(JSON.stringify(savedV2)) });
    editor.conditionEnsure();

    // 新增空目标行、清空优先级：都是"改到一半"
    editor.conditionRows.exclude.push({ kind: 'item', ids: [], include_children: false });
    editor.conditionPriority = '';
    editor.syncConditionDocument();

    const projected = editor.docSettings.detail_template;
    assert.equal(projected.priority, '', '非法优先级原样进入文档（不会被当成 0）');
    assert.deepEqual(projected.exclude, [{ kind: 'item', ids: [], include_children: false }], '空目标行保留');
    assert.equal(editor.conditionSubmitState(), 'invalid');

    // 撤销/恢复稿回到这份文档：面板重现同样的问题，而不是"看起来合法"
    editor.conditionReloadFromDocument();
    assert.equal(editor.conditionPriority, '');
    assert.equal(editor.conditionRows.exclude.length, 1);
    assert.deepEqual(editor.conditionProblems().map((problem) => problem.code).sort(), ['bad_priority', 'missing_target']);
    assert.equal(editor.conditionDirty(), true);
});

test('clearing a zero priority is not mistaken for an untouched panel', () => {
    const editor = host('article', { detail_template: { ...savedV2, priority: 0 } });
    editor.conditionEnsure();
    editor.conditionPriority = '';
    assert.equal(editor.conditionDirty(), true);
    editor.syncConditionDocument();
    assert.equal(editor.docSettings.detail_template.priority, '');
});

test('a v1 product reopened from history stays v1 until conditions really change', () => {
    const legacy = { product_template: { mode: 'selected', ids: [6], lang: 'zh-CN' } };
    const editor = host('product', JSON.parse(JSON.stringify(legacy)));
    editor.conditionEnsure();
    assert.equal(editor.conditionSubmitState(), 'unchanged');

    editor.docSettings = JSON.parse(JSON.stringify(legacy));
    editor.conditionReloadFromDocument();
    assert.equal(editor.conditionSubmitState(), 'unchanged', '回到原样仍走旧路径，不迁移');
    assert.deepEqual(editor.conditionRows.include, [{ kind: 'item', ids: ['6'], include_children: false }]);
    assert.equal('detail_template' in editor.docSettings, false);
});

test('a failed condition submit never leaks into a later legacy-path success', () => {
    const editor = host('product', { product_template: { mode: 'selected', ids: [6], lang: 'zh-CN' } });
    editor.conditionEnsure();
    editor.conditionPriority = 5;
    const first = new Map();
    assert.equal(editor.applyConditionSubmit(first), 'valid');
    assert.ok(first.has('conditions_json'));
    assert.equal(editor._submittedConditionScope.priority, 5);

    // 第一次失败后用户改回原样：这次走旧路径，残留的提交快照必须清掉
    editor.conditionPriority = 0;
    const second = new Map();
    assert.equal(editor.applyConditionSubmit(second), 'unchanged');
    assert.equal(second.has('conditions_json'), false);
    assert.equal(editor._submittedConditionScope, null);
});

test('publish retries reuse the submission captured with the payload', () => {
    const editor = host('article', { detail_template: JSON.parse(JSON.stringify(savedV2)) });
    editor.conditionEnsure();
    const captured = editor.conditionSubmission();
    editor.conditionPriority = 50;   // 请求在途期间继续编辑
    const body = new Map();
    editor.applyConditionSubmit(body, captured);
    assert.equal(JSON.parse(body.get('conditions_json')).priority, 3, '重试提交的是与载荷同时取下的条件');
});

test('history and recovery both reload the condition panel', () => {
    for (const name of ['restoreRecovery', 'applyHistorySnapshot']) {
        const start = source.indexOf('\n            ' + name + '(');
        const section = source.slice(start, source.indexOf('\n            },', start));
        assert.ok(section.includes('this.conditionReloadFromDocument();'), name);
    }
});
