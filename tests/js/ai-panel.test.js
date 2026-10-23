const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('node:path');
const vm = require('node:vm');
const { execFileSync } = require('node:child_process');

// Render the PHP fragment so the test also catches broken PHP-to-JS output.
const root = path.resolve(__dirname, '../..');
const php = process.env.PHP_BINARY || 'php';
const rendered = execFileSync(php, ['-r',
    "define('ROOT_PATH', getcwd()); function config($key) { return true; } " +
    'function __($key) { return $key . chr(39) . chr(34) . "label"; } ' +
    "include 'admin/includes/ai_panel_js.php';"
], { cwd: root, encoding: 'utf8' });
const script = rendered.match(/<script>([\s\S]*)<\/script>/)[1];

function fixture(response) {
    const changes = [];
    const nodes = {};
    const element = (value = '') => ({ value, checked: true, disabled: false,
        dispatchEvent(event) { changes.push(event.type); },
        classList: { toggle() {}, contains() { return false; } } });
    for (const id of ['aiBoxBody', 'aiPanelSubmit', 'aiStatus', 'aiPrompt', 'aiIndustry',
        'aiAudience', 'aiKeywords', 'aiExtra', 'aiGenTitle', 'aiGenSummary', 'aiGenTags',
        'aiGenSlug', 'aiGenContent']) nodes[id] = element();
    nodes.aiPanelAction = element('generate_all');
    nodes.aiStyle = element('professional');
    nodes.aiLength = element('300');
    const fields = {};
    for (const name of ['title', 'summary', 'tags', 'slug', 'seo_title', 'seo_keywords', 'seo_description']) fields[name] = element('Draft');
    const messages = [];
    const editor = { html: '<p>Original</p>', setContent(html) { this.html = html; }, getContent() { return this.html; } };
    const context = {
        document: { getElementById: id => nodes[id] || null, querySelectorAll: () => [],
            querySelector: selector => fields[(selector.match(/name=([^\]]+)/) || [])[1]] || null },
        window: {}, Event: class { constructor(type) { this.type = type; } },
        FormData: class { append() {} }, confirm: () => true,
        hugerte: { activeEditor: editor, triggerSave() {} },
        showMessage: (message, type) => messages.push({ message, type }),
        fetch: () => Promise.resolve({ json: () => Promise.resolve(response) })
    };
    vm.createContext(context);
    new vm.Script(script).runInContext(context);
    return { context, nodes, fields, editor, changes, messages };
}

test('panel renders valid JavaScript with quoted translations and no optional arrow', () => {
    const f = fixture({ success: true });
    assert.doesNotThrow(() => f.context.toggleAiBox());
});

test('generate-all fills content and notifies summary counters', async () => {
    const f = fixture({ success: true, fields: { summary: 'Generated summary', content: '<p>Generated body</p>' } });
    f.context.submitAiPanel();
    await new Promise(setImmediate);
    assert.equal(f.fields.summary.value, 'Generated summary');
    assert.deepEqual(f.changes, ['input']);
    assert.equal(f.editor.html, '<p>Generated body</p>');
    assert.equal(f.nodes.aiPanelSubmit.disabled, false);
});

test('SEO quick action fills all metadata fields', async () => {
    const seo = { seo_title: 'SEO title', seo_keywords: 'website,content', seo_description: 'SEO description' };
    const f = fixture({ success: true, seo });
    f.context.aiQuick('generate_seo');
    await new Promise(setImmediate);
    for (const [key, value] of Object.entries(seo)) assert.equal(f.fields[key].value, value);
});
