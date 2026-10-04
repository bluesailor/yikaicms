'use strict';
// 样式来源与恢复继承（2.0.4）：层的顺序 = 渲染优先级；生效层之后的有值层 = 被覆盖的来源。
const test = require('node:test');
const assert = require('node:assert/strict');
const { describe, display } = require('../../assets/js/blox-style-origin.js');

const catalog = {
    classes: {
        gc_aaaaaaaaaaaa: { name: 'card-title', settings: { font_size_px: { d: 18, m: 15 }, text_color: 'var(--yk-color-brand)' } },
        gc_bbbbbbbbbbbb: { name: 'zz-accent', settings: { text_color: '#ff0000' } },
        gc_cccccccccccc: { name: 'aa-loud', settings: { font_size_px: 40, important: true } },
    },
    tokens: [{ id: 'brand', name: '品牌蓝' }],
    radii: [{ id: 'lg', name: '大' }],
};
const theme = { typography: { h2: { size: { d: 32 }, color: 'secondary' }, body: { line_height: 1.7 } }, buttons: { radius: 'md' } };
const heading = data => ({ type: 'heading', data: Object.assign({ level: 'h2' }, data) });
const kinds = result => result.layers.map(layer => layer.kind + (layer.name ? ':' + layer.name : ''));

test('local value wins and reports what it overrides', () => {
    const result = describe(heading({ type_font_size: { d: '24' }, _classes: ['gc_aaaaaaaaaaaa'] }), { key: 'type_font_size', responsive: true }, { catalog, theme });
    assert.deepEqual(kinds(result), ['local', 'class:card-title', 'theme:H2']);
    assert.equal(result.resettable, true);
    assert.deepEqual(result.overridden.map(layer => layer.kind), ['class', 'theme']);
    assert.equal(display(result.overridden[0]), '18px');
});

test('without a local value the class, then the theme, takes over', () => {
    const withClass = describe(heading({ _classes: ['gc_aaaaaaaaaaaa'] }), { key: 'type_font_size', responsive: true }, { catalog, theme, device: 'mobile' });
    assert.equal(withClass.effective.kind, 'class');
    assert.equal(display(withClass.effective), '15px', '类的手机档值');
    assert.equal(withClass.resettable, false);
    const themed = describe(heading({}), { key: 'type_font_size', responsive: true }, { catalog, theme });
    assert.equal(themed.effective.kind, 'theme');
    assert.equal(display(themed.effective), '32px', '主题分档值按当前设备取');
});

test('smaller devices inherit from larger ones until overridden', () => {
    const data = { type_font_size: { d: '24', m: '18' } };
    assert.equal(describe(heading(data), { key: 'type_font_size', responsive: true }, { device: 'tablet' }).effective.kind, 'inherit');
    const mobile = describe(heading(data), { key: 'type_font_size', responsive: true }, { device: 'mobile' });
    assert.equal(mobile.effective.kind, 'local');
    assert.deepEqual(mobile.overridden.map(layer => layer.kind), []);
});

test('class order follows the stylesheet and important classes beat the element', () => {
    const color = describe(heading({ _classes: ['gc_bbbbbbbbbbbb', 'gc_aaaaaaaaaaaa'] }), { key: 'color' }, { catalog, theme });
    assert.deepEqual(kinds(color), ['class:zz-accent', 'class:card-title', 'theme:H2'], '类名靠后的类胜出，与挂载顺序无关');
    assert.equal(display(color.overridden[0], catalog), '品牌蓝', '色值令牌显示名称');
    const loud = describe(heading({ type_font_size: '24', _classes: ['gc_cccccccccccc'] }), { key: 'type_font_size' }, { catalog });
    assert.deepEqual(kinds(loud), ['class:aa-loud', 'local']);
    assert.equal(loud.resettable, false, '生效的不是本元素的值，恢复也不会改变显示');
});

test('mappings only apply where the render slot matches', () => {
    const button = describe({ type: 'button', data: { color: 'primary', _classes: ['gc_bbbbbbbbbbbb'] } }, { key: 'color', default: 'primary' }, { catalog, theme });
    assert.deepEqual(kinds(button), ['default'], '按钮的 color 是配色方案，不是文字颜色');
    const radius = describe({ type: 'button', data: {} }, { key: 'btn_radius' }, { theme });
    assert.equal(radius.effective.kind, 'theme');
    assert.equal(display({ value: 'lg', scale: 'radii' }, catalog), '大');
});

test('stored defaults are not reported as local overrides', () => {
    const result = describe({ type: 'text', data: { color: '' } }, { key: 'color', default: '' }, { theme });
    assert.equal(result.effective, null);
    const seeded = describe(heading({ align: 'left' }), { key: 'align', default: 'left' }, {});
    assert.equal(seeded.effective.kind, 'default');
    assert.equal(seeded.resettable, false);
});
