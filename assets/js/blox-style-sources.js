(function (root) {
    'use strict';

    var properties = { color: 'color', bg_color: 'background', radius: 'radius' };
    // 全局类（data._classes）用自己的设置键，与共享样式的键名不同，所以单独映射。
    // 只列能精确对上的：对不上的属性宁可不报，也不能猜一个来源出来。
    var classProperties = { color: 'text_color', bg_color: 'bg_color', radius: 'radius' };
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

        // 第三条轴：全局类。此前完全不出现在来源提示里，于是"这个颜色是哪来的"
        // 在挂了类的元素上根本答不上来。只报**确实设了该属性**的类——
        // 类挂着但没设这个属性时不提，免得把无关的类说成来源。
        var classKey = classProperties[control.key];
        var classes = [];
        if (classKey && Array.isArray(data._classes) && data._classes.length) {
            var known = catalog && object(catalog.classes) ? catalog.classes : null;
            data._classes.forEach(function (classId) {
                if (typeof classId !== 'string' || !classId) return;
                if (!known) { classes.push({ id: classId, name: classId, value: '', status: 'unknown' }); return; }
                var entry = known[classId];
                if (!object(entry)) { classes.push({ id: classId, name: classId, value: '', status: 'missing' }); return; }
                var settings = object(entry.settings) ? entry.settings : {};
                if (!(classKey in settings)) return;
                classes.push({
                    id: classId,
                    name: typeof entry.name === 'string' ? entry.name : classId,
                    value: scalar(settings[classKey]) ? String(settings[classKey]) : '',
                    status: 'live'
                });
            });
        }

        return {
            local: localKind,
            localValue: scalar(local) ? String(local) : '',
            resettable: localKind === 'element' || localKind === 'unknown',
            shared: shared,
            sharedName: style && typeof style.name === 'string' ? style.name : id,
            sharedValue: scalar(value) ? String(value) : '',
            classes: classes,
        };
    }

    var api = { supports: supports, describe: describe };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    if (root) root.BloxStyleSources = api;
})(typeof window !== 'undefined' ? window : null);
