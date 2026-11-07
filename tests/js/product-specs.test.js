const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function editor(data) {
    const source = fs.readFileSync(path.resolve(__dirname, '../../admin/product_edit.php'), 'utf8');
    const start = source.indexOf('function specName(');
    const script = source.slice(start, source.indexOf('\nrenderSpecs();', start)).replace(/<\?php[\s\S]*?\?>/g, php => {
        const key = php.match(/__\(['"]([^'"]+)['"]\)/)[1];
        return php.includes('json_encode') ? JSON.stringify(key) : key;
    });
    let focused;
    const element = () => ({
        children: [], dataset: {}, listeners: {}, attributes: {}, value: '', hidden: true,
        appendChild(child) { this.children.push(child); },
        addEventListener(type, handler) { this.listeners[type] = handler; },
        setAttribute(key, value) { this.attributes[key] = value; },
        focus() { focused = this; },
        set innerHTML(value) { this.children = []; this.html = value; },
    });
    const ids = Object.fromEntries(['specForm', 'specError', 'newSpecKey', 'newSpecValue', 'specsList', 'specsInput', 'specsCount'].map(id => [id, element()]));
    const trigger = element();
    const descendants = node => node.children.flatMap(child => [child, ...descendants(child)]);
    const doc = {
        getElementById: id => ids[id], createElement: element,
        querySelector: () => trigger,
        querySelectorAll: selector => descendants(ids.specsList).filter(node => (node.className || '').split(' ').includes(selector.slice(1))),
    };
    const context = { document: doc, specsData: JSON.parse(JSON.stringify(data)), specLabels: {}, specPresets: {},
        prompt() { throw new Error('Native prompt forbidden'); }, alert() { throw new Error('Native alert forbidden'); } };
    vm.runInNewContext(script, context);
    return { context, ids, doc, trigger, focused: () => focused };
}

test('inline add validates blank/duplicate names without mutating existing values', () => {
    const e = editor({ 型号: 'CU-84' });
    e.context.addSpecRow();
    assert.equal(e.ids.specForm.hidden, false);
    assert.equal(e.focused(), e.ids.newSpecKey);
    e.ids.newSpecKey.value = '   ';
    e.context.commitSpecRow();
    assert.equal(e.ids.specError.textContent, 'admin_spec_name_required');
    e.ids.newSpecKey.value = ' 型号 ';
    e.context.commitSpecRow();
    assert.equal(e.ids.specError.textContent, 'admin_spec_exists');
    assert.equal(e.context.specsData.型号, 'CU-84');
    assert.equal(Object.keys(e.context.specsData).length, 1);
    e.context.cancelSpecRow();
    assert.equal(e.ids.specForm.hidden, true);
    assert.equal(e.focused(), e.trigger);
});

test('Unicode, units, quotes and prototype-like names survive JSON and safe event binding', () => {
    const e = editor({ 重量: '0.8 kg' });
    for (const key of ["工作温度 'quoted'", '__proto__']) {
        e.context.addSpecRow();
        e.ids.newSpecKey.value = key;
        e.ids.newSpecValue.value = '-20 ～ +60 °C';
        e.context.commitSpecRow();
        assert.equal(JSON.parse(e.ids.specsInput.value)[key], '-20 ～ +60 °C');
        assert.equal(e.ids.specsList.children.at(-1).children[1].attributes['aria-label'], key + ' admin_spec_value');
    }
    const row = e.ids.specsList.children[1];
    const remove = row.children.at(-1);
    assert.equal(remove.attributes.onclick, undefined);
    assert.equal(typeof remove.listeners.click, 'function');
    remove.listeners.click();
    assert.equal(Object.hasOwn(e.context.specsData, "工作温度 'quoted'"), false);
    assert.equal(e.context.specsData.重量, '0.8 kg');
});

test('array-form translated specs retain names and values when appending, editing and removing', () => {
    const e = editor([{ name: 'Weight', value: '0.8 kg' }]);
    e.context.addSpecRow();
    e.ids.newSpecKey.value = 'Temperature';
    e.ids.newSpecValue.value = '-20 °C';
    e.context.commitSpecRow();
    let data = JSON.parse(e.ids.specsInput.value);
    assert.deepEqual(data, [{ name: 'Weight', value: '0.8 kg' }, { name: 'Temperature', value: '-20 °C' }]);
    const keyInput = e.ids.specsList.children[1].children[0];
    keyInput.value = 'Weight';
    keyInput.listeners.change();
    assert.equal(keyInput.value, 'Temperature');
    keyInput.value = 'Operating temperature';
    keyInput.listeners.change();
    const valueInput = e.ids.specsList.children[1].children[1];
    valueInput.value = '-20 ～ +60 °C';
    valueInput.listeners.input();
    data = JSON.parse(e.ids.specsInput.value);
    assert.equal(data[1].name, 'Operating temperature');
    assert.equal(data[1].value, '-20 ～ +60 °C', 'typing must update the saved value before blur');
    e.ids.specsList.children[0].children.at(-1).listeners.click();
    assert.equal(JSON.parse(e.ids.specsInput.value).length, 1);
});

test('Enter commits without submitting the product; IME Enter is ignored and Escape cancels', () => {
    const e = editor({});
    let prevented = 0;
    e.context.addSpecRow();
    e.ids.newSpecKey.value = '温度';
    e.context.specFormKeydown({ key: 'Enter', isComposing: true, preventDefault() { prevented++; } });
    assert.equal(Object.keys(e.context.specsData).length, 0);
    e.context.specFormKeydown({ key: 'Enter', preventDefault() { prevented++; } });
    assert.equal(prevented, 1);
    assert.equal(Object.hasOwn(e.context.specsData, '温度'), true);
    e.context.addSpecRow();
    e.context.specFormKeydown({ key: 'Escape', preventDefault() { prevented++; } });
    assert.equal(e.ids.specForm.hidden, true);
    assert.equal(prevented, 2);
});
