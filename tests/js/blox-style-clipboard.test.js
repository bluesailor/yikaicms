const test = require('node:test');
const assert = require('node:assert/strict');
const { mixin, styleKeys } = require('../../assets/js/blox-style-clipboard.js');

// 与真实 controls() 元数据同构的极简 schema：tab=style 即样式，group=animation 是动作
const SCHEMAS = {
    heading: { label: '标题', controls: [
        { key: 'text' }, { key: 'level' }, { key: 'url' }, { key: 'html_id' },
        { key: 'site_field' }, { key: 'loop_field' },
        { key: 'visual_size', tab: 'style', responsive: true },
        { key: 'type_font_size', tab: 'style', responsive: true },
        { key: 'color', tab: 'style' }, { key: 'align', tab: 'style' },
        { key: 'animation', tab: 'style', group: 'animation' },
        { key: 'animation_duration', tab: 'style', group: 'animation' },
    ] },
    button: { label: '按钮', controls: [
        { key: 'text' }, { key: 'url' }, { key: 'icon' }, { key: 'hover_effect' },
        { key: 'variant', tab: 'style' }, { key: 'color', tab: 'style' },
        { key: 'btn_padding_x', tab: 'style', responsive: true },
    ] },
    text: { label: '文本', controls: [
        { key: 'html' }, { key: 'typography_role', tab: 'style' }, { key: 'color', tab: 'style' },
    ] },
    image: { label: '图片', controls: [{ key: 'src' }, { key: 'radius', tab: 'style' }] },
};

function editor() {
    const commands = [];
    const toasts = [];
    const host = Object.assign({
        elSchema: (type) => SCHEMAS[type] || { controls: [] },
        runCommand(name, fn) { commands.push(name); fn(); return { ok: true }; },
        toast(msg) { toasts.push(msg); },
        commands, toasts,
    }, mixin({ copied: 'copied', pasted: 'pasted', empty: 'empty', typeMismatch: 'from :type', unsupported: 'unsupported' }));
    return host;
}

test('whitelist derives from control metadata: style tab only, no animation, no global refs', function () {
    assert.deepEqual(styleKeys('heading', SCHEMAS.heading), ['visual_size', 'type_font_size', 'color', 'align']);
    // 按钮悬停是历史混合字段：显式适配进入白名单
    assert.deepEqual(styleKeys('button', SCHEMAS.button), ['variant', 'color', 'btn_padding_x', 'hover_effect']);
    // 全局样式引用（typography_role）不隐式复制
    assert.deepEqual(styleKeys('text', SCHEMAS.text), ['color']);
    // 首批只开 heading/button/text
    assert.deepEqual(styleKeys('image', SCHEMAS.image), []);
});

test('paste replaces whitelisted styles, clears absent ones to inherit, keeps content and bindings', function () {
    const host = editor();
    const source = { type: 'heading', data: {
        text: '源标题', level: 'h1', url: '/a', html_id: 'src', site_field: 'company_name',
        visual_size: { d: '3xl', m: 'xl' }, color: '#ff0000', animation: 'fade',
    } };
    const target = { type: 'heading', data: {
        text: '目标标题', level: 'h3', url: '/b', html_id: 'dst', loop_field: 'title',
        align: 'center', type_font_size: { d: '40' }, animation: 'zoom',
    } };

    assert.equal(host.copyElementStyle(source), true);
    assert.equal(host.pasteElementStyle(target), true);
    assert.deepEqual(host.commands, ['paste-element-style'], '一次粘贴 = 一条命令');

    // 白名单值被替换（含响应式对象），且是深拷贝
    assert.deepEqual(target.data.visual_size, { d: '3xl', m: 'xl' });
    assert.equal(target.data.color, '#ff0000');
    source.data.visual_size.d = 'sm';
    assert.equal(target.data.visual_size.d, '3xl', '粘贴必须深拷贝，不共享引用');

    // 剪贴板未出现的白名单属性清除为继承（删除，不是写 0/空串）
    assert.equal('align' in target.data, false);
    assert.equal('type_font_size' in target.data, false);

    // 内容 / 链接 / 锚点 / 动态绑定 / 动作原样保留
    assert.equal(target.data.text, '目标标题');
    assert.equal(target.data.level, 'h3');
    assert.equal(target.data.url, '/b');
    assert.equal(target.data.html_id, 'dst');
    assert.equal(target.data.loop_field, 'title');
    assert.equal(target.data.animation, 'zoom');
    // 复制侧的绑定也不进入剪贴板
    assert.equal('site_field' in target.data, false);
});

test('cross-type paste is refused with a reason; empty clipboard and unsupported types explain themselves', function () {
    const host = editor();
    const button = { type: 'button', data: { variant: 'primary' } };
    const heading = { type: 'heading', data: {} };
    const image = { type: 'image', data: {} };

    assert.equal(host.pasteStyleDisabledReason(heading), 'empty');
    assert.equal(host.copyElementStyle(button), true);
    assert.equal(host.canPasteElementStyle(heading), false);
    assert.equal(host.pasteElementStyle(heading), false);
    assert.equal(host.pasteStyleDisabledReason(heading), 'from 按钮');
    assert.deepEqual(host.commands, [], '被拒绝的粘贴不产生历史命令');

    assert.equal(host.canCopyElementStyle(image), false);
    assert.equal(host.pasteStyleDisabledReason(image), 'unsupported');
});

test('button hover style travels with the paste; failed command leaves no toast of success', function () {
    const host = editor();
    const source = { type: 'button', data: { text: 'Buy', hover_effect: 'lift', variant: 'outline' } };
    const target = { type: 'button', data: { text: 'More', hover_effect: 'none', color: '#333' } };
    host.copyElementStyle(source);
    host.pasteElementStyle(target);
    assert.equal(target.data.hover_effect, 'lift');
    assert.equal('color' in target.data, false);
    assert.equal(target.data.text, 'More');

    // runCommand 回滚（ok:false）时不提示"已粘贴"
    const failing = editor();
    failing.runCommand = (name, fn) => ({ ok: false });
    failing.copyElementStyle(source);
    const before = JSON.stringify(target.data);
    assert.equal(failing.pasteElementStyle(target), false);
    assert.equal(JSON.stringify(target.data), before);
    assert.equal(failing.toasts.includes('pasted'), false);
});
