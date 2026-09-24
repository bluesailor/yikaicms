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

    // ── 编辑全局类时的反向提示：这个类属性在当前元素上会不会被挡住？ ─────────────
    // 优先级（依据见 BloxGlobalClasses::SELECTOR_SUFFIX）：元素的精确值（内联 / .yk-r-*）
    // > 样式预设（内联 !important）> 同元素上类名更靠后的类 > 本类 > 主题默认与元素的档位预设（Tailwind 工具类）。
    // 只列能精确对上的本地键：对不上的宁可不报。
    var localOverrides = {
        text_color: function (type) { return type === 'heading' || type === 'text' ? ['color'] : []; },
        bg_color: function () { return ['bg_color']; },
        font_size_px: function () { return ['type_font_size']; },
        line_height: function () { return ['type_line_height']; },
        font_weight: function () { return ['type_font_weight']; },
        gap_px: function () { return ['gap_px', 'row_gap_px', 'column_gap_px']; },
        padding_px: function () {
            return ['style_padding', 'style_padding_top', 'style_padding_right', 'style_padding_bottom', 'style_padding_left'];
        },
    };
    ['top', 'right', 'bottom', 'left'].forEach(function (side) {
        localOverrides['margin_' + side + '_px'] = function () { return ['style_margin', 'style_margin_' + side]; };
        localOverrides['padding_' + side + '_px'] = function () { return ['style_padding', 'style_padding_' + side]; };
    });
    // 样式预设输出的属性 → 预设快照字段
    var presetFields = { text_color: 'color', bg_color: 'background', border_color: 'border_color', radius_px: 'radius', radius: 'radius' };

    function filled(value) {
        if (value === undefined || value === null || value === '') return false;
        if (object(value)) return Object.keys(value).some(function (key) { return filled(value[key]); });
        return true;
    }

    function presetFor(data, catalog) {
        var id = typeof data._global_style === 'string' ? data._global_style.trim() : '';
        if (!id) return null;
        var styles = catalog && Array.isArray(catalog.styles) ? catalog.styles : [];
        var style = styles.find(function (item) { return object(item) && item.id === id && item.status !== 'archived'; });
        if (style) return style;
        return object(data._global_style_snapshot) ? data._global_style_snapshot : null;
    }

    /**
     * 本类已设置、但在该元素上会被更高来源挡住的属性。
     * @return {Array<{key:string, by:string, localKeys:string[], name:string}>} by: element | preset | class
     */
    function classConflicts(element, classId, catalog) {
        var data = element && object(element.data) ? element.data : {};
        var type = element && typeof element.type === 'string' ? element.type : '';
        var classes = catalog && object(catalog.classes) ? catalog.classes : {};
        var target = object(classes[classId]) ? classes[classId] : null;
        if (!target || !object(target.settings)) return [];
        var preset = presetFor(data, catalog);
        var attached = Array.isArray(data._classes) ? data._classes : [];
        var result = [];
        Object.keys(target.settings).forEach(function (key) {
            if (!filled(target.settings[key])) return;
            var localKeys = (localOverrides[key] ? localOverrides[key](type) : []).filter(function (localKey) {
                return filled(data[localKey]);
            });
            if (localKeys.length) {
                result.push({ key: key, by: 'element', localKeys: localKeys, name: '' });
                return;
            }
            var presetField = presetFields[key];
            if (preset && presetField && filled(preset[presetField]) && preset[presetField] !== 'none') {
                result.push({ key: key, by: 'preset', localKeys: [], name: typeof preset.name === 'string' ? preset.name : '' });
                return;
            }
            // 多类：样式表按类名升序输出，同一属性类名靠后者胜出（与挂载顺序无关）
            var winner = null;
            attached.forEach(function (otherId) {
                var other = classes[otherId];
                if (otherId === classId || !object(other) || !object(other.settings) || !filled(other.settings[key])) return;
                if (String(other.name) > String(target.name) && (!winner || String(other.name) > String(winner.name))) winner = other;
            });
            if (winner) result.push({ key: key, by: 'class', localKeys: [], name: String(winner.name) });
        });
        return result;
    }

    var api = { supports: supports, describe: describe, classConflicts: classConflicts };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    if (root) root.BloxStyleSources = api;
})(typeof window !== 'undefined' ? window : null);
