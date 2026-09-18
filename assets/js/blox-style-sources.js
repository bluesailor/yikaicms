(function (root) {
    'use strict';

    var properties = { color: 'color', bg_color: 'background', radius: 'radius' };
    function object(value) { return value && typeof value === 'object' && !Array.isArray(value); }
    function scalar(value) { return typeof value === 'string' || typeof value === 'number'; }
    function supports(type, control) {
        // 判据是**渲染契约一致**：该控件的值与其共享样式声明落在同一个 CSS 槽位，
        // 不是"控件名叫 color/radius 就算"（TASK-004）。证据：
        // - 共享声明由 BlockRenderer::applyGlobalStyle() 注入到元素 HTML 的**第一个标签**
        //   （includes/builder/BlockRenderer.php:813-830 → BloxDesignSystem::styleDeclarations()，
        //   输出 color / background-color / border-color / border-radius，均带 !important）；
        // - TextElement 的根标签正是那个标签：`<div class="prose prose-lg max-w-none{radius}"{color}>`
        //   （includes/builder/elements/TextElement.php:48 color、:49 radius、:73 渲染），
        //   故 text 的 color / radius 与共享声明同槽 → 本次新增这两组；
        // - 其余类型（icon/button/divider 的 color、heading 的 radius 等）未核对到同槽契约，保持不支持。
        return !!control && (control.key === 'bg_color'
            || (control.key === 'color' && (type === 'heading' || type === 'text'))
            || (control.key === 'radius' && ['container', 'div', 'text'].indexOf(type) !== -1));
    }

    // Describes stored declarations, not the CSS cascade or a child's computed style.
    function describe(data, control, catalog) {
        data = object(data) ? data : {};
        control = control || {};
        var raw = data[control.key], fallback = control.default;
        var explicit = raw !== undefined && raw !== null && raw !== '';
        var local = explicit ? raw : fallback;
        var localKind = explicit && raw !== fallback ? 'element'
            : scalar(fallback) && fallback !== '' ? 'default' : 'css';
        if (local !== undefined && local !== null && !scalar(local)) localKind = 'unknown';
        var id = typeof data._global_style === 'string' ? data._global_style.trim() : '';
        var shared = 'unbound', style = null;
        if (id) {
            if (!catalog || !Array.isArray(catalog.styles)) shared = 'unknown';
            else {
                style = catalog.styles.find(function (item) { return object(item) && item.id === id; }) || null;
                if (style) shared = style.status === 'archived' ? 'archived' : 'live';
                else if (object(data._global_style_snapshot) && Object.keys(data._global_style_snapshot).length) {
                    shared = 'snapshot';
                    style = data._global_style_snapshot;
                } else shared = 'missing';
            }
        }
        var value = style && style[properties[control.key]];
        return {
            local: localKind,
            localValue: scalar(local) ? String(local) : '',
            resettable: localKind === 'element' || localKind === 'unknown',
            shared: shared,
            sharedName: style && typeof style.name === 'string' ? style.name : id,
            sharedValue: scalar(value) ? String(value) : '',
        };
    }

    var api = { supports: supports, describe: describe };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    if (root) root.BloxStyleSources = api;
})(typeof window !== 'undefined' ? window : null);
