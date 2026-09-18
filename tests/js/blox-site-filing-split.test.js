const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

// 站点资料方法是编辑器 Alpine 方法表的一段纯 JS（partial 在 ?> 之后）
const source = fs.readFileSync(path.join(__dirname, '../../admin/blox_editor/partials/site-data-methods.php'), 'utf8');
const methods = new Function('return ({' + source.slice(source.indexOf('?>') + 2) + '});')();

function editor(copyrightData, languageFixed = false, language = 'zh-CN') {
    const copyright = { id: 'e_copy', type: 'site-copyright', data: copyrightData };
    const app = Object.assign({
        sections: [{ columns: [{ elements: [copyright] }] }],
        selEl: copyright,
        siteCopyright: { language, language_fixed: languageFixed, filing: language === 'zh-CN' },
        elementLib: [{ type: 'site-filing', label: 'Filing', defaults: { show_icp: true, show_police: true, layout: 'inline', align: 'left', tone: 'dark' } }],
        flushes: 0,
        selectedPath() { return '0.0.0'; },
        flushHistory() { this.flushes++; },
        runCommand(_name, fn) { return fn.call(this); },
        newElementNode(lib) { return { id: 'e_new', type: lib.type, data: JSON.parse(JSON.stringify(lib.defaults)) }; },
        insertElementAt(node, target) {
            const parts = target.path.split('.').map(Number);
            this.sections[parts[0]].columns[parts[1]].elements.splice(parts[2] + 1, 0, node);
            this.inserted = target;
            return true;
        },
    }, methods);
    return { app, copyright };
}

test('legacy copyright with filing splits into a sibling filing element with the same layout', () => {
    const { app, copyright } = editor({ align: 'center', tone: 'light' });
    assert.equal(app.copyrightHasFiling(), true);

    app.splitCopyrightFiling();

    const elements = app.sections[0].columns[0].elements;
    assert.deepEqual(elements.map(el => el.type), ['site-copyright', 'site-filing']);
    assert.equal(copyright.data.show_icp, false);
    assert.equal(copyright.data.show_police, false);
    assert.deepEqual(
        { icp: elements[1].data.show_icp, police: elements[1].data.show_police, align: elements[1].data.align, tone: elements[1].data.tone },
        { icp: true, police: true, align: 'center', tone: 'light' }
    );
    assert.equal(app.inserted.position, 'after');
    assert.equal(app.flushes, 2);
    assert.equal(app.copyrightHasFiling(), false);
});

test('split keeps a switched-off filing line switched off', () => {
    const { app } = editor({ show_icp: '1', show_police: '0' });
    app.splitCopyrightFiling();
    const filing = app.sections[0].columns[0].elements[1];
    assert.equal(filing.data.show_icp, true);
    assert.equal(filing.data.show_police, false);
});

test('copyright without filing output is left alone', () => {
    const { app } = editor({ show_icp: false, show_police: false });
    assert.equal(app.copyrightHasFiling(), false);
    app.splitCopyrightFiling();
    assert.equal(app.sections[0].columns[0].elements.length, 1);
    assert.equal(app.flushes, 0);
});

test('legacy filing switches hide once off; language-fixed templates hide zh-only controls', () => {
    const legacy = { key: 'show_icp', legacy_filing: true, site_langs: ['zh-CN'] };
    assert.equal(editor({}).app.siteLanguageControlApplies(legacy), true);
    assert.equal(editor({ show_icp: false, show_police: false }).app.siteLanguageControlApplies(legacy), false);
    assert.equal(editor({}, true, 'en').app.siteLanguageControlApplies(legacy), false);
    assert.equal(editor({}, false, 'en').app.siteLanguageControlApplies({ key: 'align' }), true);
});
