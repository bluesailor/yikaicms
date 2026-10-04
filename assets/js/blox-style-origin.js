/**
 * 样式来源与恢复继承（2.0.4，借鉴 WordPress 7.2 Inherited Styles）。
 *
 * 对一个元素的一个样式控件，按渲染优先级列出所有给它赋值的层，第一层就是生效值：
 *   1. preset   旧样式预设（内联 !important；2.0.4 起预设已转为全局类，只剩快照）
 *   2. class!   带 !important 的全局类（由样式预设转来的类）
 *   3. local    本元素在当前设备档的值
 *   4. inherit  本元素从更大设备档沿用的值（平板沿用桌面、手机沿用平板 / 桌面）
 *   5. class    普通全局类（同属性多个类时类名靠后者胜，与 BloxGlobalClasses 输出顺序一致）
 *   6. theme    主题（设计系统里的排版角色 / 按钮设置）
 *   7. default  控件默认值
 * 生效层之后、仍有值的层就是被它「覆盖」的来源；生效层是本元素的值时可以「恢复」——删掉本档的值，
 * 让下一层接管。只描述存下来的声明，不读画布的计算样式。
 *
 * 映射只收「能精确对上」的属性：对不上的宁可不报来源，也不能猜一个出来。
 */
(function (root) {
    'use strict';

    function object(value) { return value && typeof value === 'object' && !Array.isArray(value); }
    function filled(value) {
        if (value === undefined || value === null || value === '') return false;
        if (object(value)) return Object.keys(value).some(function (key) { return filled(value[key]); });
        return true;
    }

    // 元素控件键 → 全局类设置键（只在列出的元素类型上成立；'*' 为全部类型）
    var CLASS_MAP = {
        color: { key: 'text_color', types: ['heading', 'text'] },
        bg_color: { key: 'bg_color', types: '*' },
        border_color: { key: 'border_color', types: '*' },
        type_font_size: { key: 'font_size_px', types: '*', unit: 'px' },
        type_line_height: { key: 'line_height', types: '*' },
        type_font_weight: { key: 'font_weight', types: '*' },
        align: { key: 'text_align', types: ['heading', 'text'] },
        radius_token: { key: 'radius_token', types: '*', scale: 'radii' },
        shadow_token: { key: 'shadow_token', types: '*', scale: 'shadows' },
        gap_px: { key: 'gap_px', types: '*', unit: 'px' },
    };
    // 旧样式预设快照字段（与 BlockRenderer::applyGlobalStyle 同槽才列）
    var PRESET_MAP = { color: ['color', ['heading', 'text']], bg_color: ['background', '*'], radius: ['radius', ['container', 'div', 'text']] };

    // 设备档沿用链（与 BloxResponsive 一致：t←d，m←t←d，w←d）
    var CHAIN = { desktop: ['d'], tablet: ['t', 'd'], mobile: ['m', 't', 'd'], wide: ['w', 'd'] };
    var SLOT = { desktop: 'd', tablet: 't', mobile: 'm', wide: 'w' };
    function deviceValue(value, device) {
        if (!object(value)) return { value: value, slot: 'd' };
        var chain = CHAIN[device] || CHAIN.desktop;
        for (var i = 0; i < chain.length; i++) {
            if (filled(value[chain[i]])) return { value: value[chain[i]], slot: chain[i] };
        }
        return { value: undefined, slot: '' };
    }

    function applies(map, type) { return map && (map.types === '*' || map.types.indexOf(type) !== -1); }

    /** 主题层：标题按级别取排版角色，正文按 typography_role（默认 body），按钮取按钮设置。 */
    function themeValue(type, data, key, theme) {
        if (!object(theme)) return null;
        var typography = object(theme.typography) ? theme.typography : {};
        var buttons = object(theme.buttons) ? theme.buttons : {};
        var role = '';
        if (type === 'heading') role = /^h[1-6]$/.test(String(data.level || '')) ? String(data.level) : 'h2';
        else if (type === 'text') role = /^(body|caption|h[1-6])$/.test(String(data.typography_role || '')) ? String(data.typography_role) : 'body';
        var roleSettings = role && object(typography[role]) ? typography[role] : null;
        if (roleSettings) {
            var field = { type_font_size: 'size', type_line_height: 'line_height', type_font_weight: 'weight', color: 'color' }[key];
            if (field && filled(roleSettings[field])) {
                var raw = roleSettings[field];
                return { value: field === 'color' ? 'var(--yk-color-' + raw + ')' : raw, name: role.toUpperCase(), unit: field === 'size' ? 'px' : '' };
            }
        }
        if (type === 'button') {
            var buttonField = { btn_radius: 'radius', btn_padding_x: 'padding_x', btn_padding_y: 'padding_y' }[key];
            if (buttonField && filled(buttons[buttonField])) return { value: buttons[buttonField], name: 'button', unit: buttonField === 'radius' ? '' : 'px' };
        }
        return null;
    }

    /**
     * @param {{type:string,data:object}} element
     * @param {{key:string,default?:*,responsive?:boolean}} control
     * @param {{device?:string, catalog?:{classes?:object,styles?:Array,tokens?:Array,radii?:Array,shadows?:Array}, theme?:object}} ctx
     * @return {{layers:Array<{kind:string,value:*,name?:string,id?:string,unit?:string,slot?:string}>, effective:object|null, overridden:Array, resettable:boolean}}
     */
    function describe(element, control, ctx) {
        element = element || {};
        control = control || {};
        ctx = ctx || {};
        var type = String(element.type || '');
        var data = object(element.data) ? element.data : {};
        var key = String(control.key || '');
        var device = ctx.device || 'desktop';
        var catalog = object(ctx.catalog) ? ctx.catalog : {};
        var layers = [];

        // 1. 旧样式预设
        var preset = PRESET_MAP[key];
        if (preset && (preset[1] === '*' || preset[1].indexOf(type) !== -1) && typeof data._global_style === 'string' && data._global_style) {
            var styles = Array.isArray(catalog.styles) ? catalog.styles : [];
            var style = styles.find(function (item) { return object(item) && item.id === data._global_style && item.status !== 'archived'; })
                || (object(data._global_style_snapshot) ? data._global_style_snapshot : null);
            if (style && filled(style[preset[0]]) && style[preset[0]] !== 'none') {
                layers.push({ kind: 'preset', value: style[preset[0]], name: String(style.name || data._global_style) });
            }
        }

        // 全局类：只算确实设了该属性的类；important 的排在本元素值之前
        var classLayers = [], strongLayers = [];
        var map = CLASS_MAP[key];
        var classes = object(catalog.classes) ? catalog.classes : null;
        if (applies(map, type) && Array.isArray(data._classes) && classes) {
            var found = [];
            data._classes.forEach(function (id) {
                var entry = classes[id];
                if (!object(entry) || !object(entry.settings)) return;
                var picked = deviceValue(entry.settings[map.key], device);
                if (!filled(picked.value)) return;
                found.push({ kind: 'class', id: String(id), name: String(entry.name || id), value: picked.value, unit: map.unit || '', scale: map.scale || '', important: !!entry.settings.important });
            });
            // 样式表按类名升序输出：同属性类名靠后者胜 → 倒序即优先级
            found.sort(function (a, b) { return a.name < b.name ? 1 : (a.name > b.name ? -1 : 0); });
            found.forEach(function (layer) { (layer.important ? strongLayers : classLayers).push(layer); });
        }
        strongLayers.forEach(function (layer) { layer.kind = 'class'; layers.push(layer); });

        // 本元素：当前档 / 沿用更大档（数字值按映射表补单位显示）
        var raw = data[key];
        var localUnit = (map && map.unit) || (typeof control.unit === 'string' ? control.unit : '');
        if (control.responsive && object(raw)) {
            var own = raw[SLOT[device] || 'd'];
            if (filled(own) && JSON.stringify(own) !== JSON.stringify(control.default ?? '')) {
                layers.push({ kind: 'local', value: own, slot: SLOT[device] || 'd', unit: localUnit });
            } else if (filled(own) && device === 'desktop') {
                layers.push({ kind: 'local', value: own, slot: 'd', isDefault: true });
            } else {
                var inherited = deviceValue(raw, device);
                if (filled(inherited.value) && inherited.slot !== (SLOT[device] || 'd')) {
                    layers.push({ kind: 'inherit', value: inherited.value, slot: inherited.slot, unit: localUnit });
                }
            }
        } else if (filled(raw) && JSON.stringify(raw) !== JSON.stringify(control.default ?? '')) {
            layers.push({ kind: 'local', value: raw, slot: 'd', unit: localUnit });
        }

        classLayers.forEach(function (layer) { layers.push(layer); });

        var theme = themeValue(type, data, key, ctx.theme);
        if (theme) {
            // 主题的字号等是分档值（d / t / m）：按当前设备取
            var themed = deviceValue(theme.value, device);
            if (filled(themed.value)) layers.push({ kind: 'theme', value: themed.value, name: theme.name, unit: theme.unit });
        }

        if (filled(control.default)) layers.push({ kind: 'default', value: control.default });

        // 写着默认值的本元素层不算覆盖（插入元素时会把默认值写进数据）
        layers = layers.filter(function (layer) { return !layer.isDefault; });
        var effective = layers[0] || null;
        var overridden = effective ? layers.slice(1).filter(function (layer) { return layer.kind !== 'default'; }) : [];
        return {
            layers: layers,
            effective: effective,
            overridden: overridden,
            resettable: !!effective && effective.kind === 'local',
        };
    }

    var SPACING_SIDES = ['top', 'right', 'bottom', 'left'];
    /**
     * 间距块的来源汇总（间距是独立界面，不走通用控件）：挂着的全局类设了哪些内 / 外边距，
     * 以及本元素当前设备档的哪些值压过了它们（元素的「全部」压过类的四边，元素的单边只压同一边）。
     * @return {{classes:Array<{id:string,name:string,items:Array<{kind:string,side:string,value:*}>}>, overridden:Array<{kind:string,side:string}>}}
     */
    function spacing(element, ctx) {
        ctx = ctx || {};
        var data = element && object(element.data) ? element.data : {};
        var catalog = object(ctx.catalog) ? ctx.catalog : {};
        var classes = object(catalog.classes) ? catalog.classes : {};
        var device = ctx.device || 'desktop';
        var out = { classes: [], overridden: [] };
        var local = function (key) { return filled(deviceValue(data[key], device).value); };
        (Array.isArray(data._classes) ? data._classes : []).forEach(function (id) {
            var entry = classes[id];
            if (!object(entry) || !object(entry.settings)) return;
            var items = [];
            ['padding', 'margin'].forEach(function (kind) {
                if (kind === 'padding') {
                    var all = deviceValue(entry.settings.padding_px, device).value;
                    if (filled(all)) items.push({ kind: kind, side: '', value: all });
                }
                SPACING_SIDES.forEach(function (side) {
                    var value = deviceValue(entry.settings[kind + '_' + side + '_px'], device).value;
                    if (filled(value)) items.push({ kind: kind, side: side, value: value });
                });
            });
            if (!items.length) return;
            out.classes.push({ id: String(id), name: String(entry.name || id), items: items });
            items.forEach(function (item) {
                var hit = local('style_' + item.kind) || (item.side ? local('style_' + item.kind + '_' + item.side)
                    : SPACING_SIDES.some(function (side) { return local('style_' + item.kind + '_' + side); }));
                if (hit && !out.overridden.some(function (o) { return o.kind === item.kind && o.side === item.side; })) out.overridden.push({ kind: item.kind, side: item.side });
            });
        });
        return out;
    }

    /** 给人看的值：色值令牌显示名称，圆角 / 阴影令牌显示刻度名，数字带单位。 */
    function display(layer, catalog) {
        if (!layer) return '';
        var value = layer.value;
        if (object(value)) return '';
        var text = String(value);
        catalog = object(catalog) ? catalog : {};
        var token = /^var\(--yk-color-([a-z0-9_-]+)\)$/.exec(text);
        if (token) {
            var tokens = Array.isArray(catalog.tokens) ? catalog.tokens : [];
            var found = tokens.find(function (item) { return object(item) && item.id === token[1]; });
            return found && found.name ? String(found.name) : token[1];
        }
        if (layer.scale) {
            var scale = Array.isArray(catalog[layer.scale]) ? catalog[layer.scale] : [];
            var step = scale.find(function (item) { return object(item) && item.id === text; });
            if (step && step.name) return String(step.name);
        }
        return layer.unit && /^-?\d+(\.\d+)?$/.test(text) ? text + layer.unit : text;
    }

    var EMPTY = { layers: [], effective: null, overridden: [], resettable: false };
    var SLOT_DEVICE = { d: 'desktop', t: 'tablet', m: 'mobile', w: 'wide' };

    /** 混入编辑器（Alpine）的方法：样式面板每个控件下面那一行「来源 / 已覆盖 / 恢复」。 */
    var methods = {
        controlOrigin(ctrl) {
            if (!this.selEl || !ctrl) return EMPTY;
            var system = this.designSystem || {};
            return describe({ type: this.selEl.type, data: this.selEl.data || {} }, ctrl, { device: this.previewDevice, catalog: system, theme: system.theme });
        },
        /** 一层的说明：「全局类 .card-title」「主题 · H2」「沿用桌面」「本元素」。 */
        originLayerLabel(layer) {
            if (!layer) return '';
            var text = this.styleOriginText || {};
            if (layer.kind === 'class') return (text.class || '') + ' .' + layer.name;
            if (layer.kind === 'preset') return (text.preset || '') + ' ' + layer.name;
            if (layer.kind === 'theme') return (text.theme || '') + ' · ' + (layer.name === 'button' ? (text.button || '') : layer.name);
            if (layer.kind === 'inherit') {
                var device = (this.devices || []).find(function (d) { return d.key === SLOT_DEVICE[layer.slot]; });
                return String(text.inherit || '%s').replace('%s', device ? device.label : layer.slot);
            }
            return text[layer.kind] || layer.kind;
        },
        originLayerValue(layer) { return display(layer, this.designSystem || {}); },
        /** 被覆盖的来源：「全局类 .card-title（18px）、主题 · H2（32px）」 */
        originOverriddenText(ctrl) {
            var self = this;
            return this.controlOrigin(ctrl).overridden.map(function (layer) {
                var value = self.originLayerValue(layer);
                return self.originLayerLabel(layer) + (value ? '（' + value + '）' : '');
            }).join('、');
        },
        /** 恢复继承：删掉本元素在当前设备档的值，让下一层（更大档 / 全局类 / 主题 / 默认）接管；可撤销。 */
        restoreControlOrigin(ctrl) {
            if (!this.selEl || !this.controlOrigin(ctrl).resettable) return;
            var responsive = global().BloxResponsive;
            this.flushHistory(true);
            this.runCommand('restore-style-inheritance', function () {
                var key = ctrl.key, value = this.selEl.data[key];
                if (ctrl.responsive && this.previewDevice !== 'desktop' && responsive) {
                    this.inheritControlValue(ctrl);
                } else if (ctrl.responsive && object(value)) {
                    var next = Object.assign({}, value);
                    delete next.d;
                    if (Object.keys(next).some(function (slot) { return filled(next[slot]); })) this.selEl.data[key] = next;
                    else delete this.selEl.data[key];
                } else {
                    delete this.selEl.data[key];
                }
            });
            this.flushHistory(true);
        },
        /** 间距块的来源：「全局类 .x：内边距 24px、上外边距 16px」，本元素压过的部分另行列出。 */
        spacingOrigin() {
            if (!this.selEl) return { classes: [], overridden: [] };
            return spacing({ type: this.selEl.type, data: this.selEl.data || {} }, { device: this.previewDevice, catalog: this.designSystem || {} });
        },
        spacingItemText(item) {
            var text = this.styleOriginText || {};
            var label = (text[item.kind] || item.kind) + (item.side ? ' · ' + (text['side_' + item.side] || item.side) : '');
            return item.value === undefined ? label : label + ' ' + (/^-?\d+(\.\d+)?$/.test(String(item.value)) ? item.value + 'px' : item.value);
        },
        /** 去改来源：全局类 → 切到编辑这个类；主题 / 色值令牌 → 设计系统。 */
        openOriginSource(layer) {
            if (!layer) return;
            if (layer.kind === 'class' && typeof this.setStyleTarget === 'function') this.setStyleTarget(layer.id);
            else if ((layer.kind === 'preset' || /^var\(--yk-color-/.test(String(layer.value))) && typeof this.openDesignSystem === 'function') this.openDesignSystem(layer.kind === 'preset' ? 'styles' : 'colors');
            else if (layer.kind === 'theme' && this.canManageDesign) window.open((window.YK_BASE || '') + '/admin/blox_design.php', '_blank', 'noopener');
        },
    };
    function global() { return typeof window !== 'undefined' ? window : {}; }

    var api = { describe: describe, display: display, spacing: spacing, methods: methods, CLASS_MAP: CLASS_MAP };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    if (root) root.BloxStyleOrigin = api;
})(typeof window !== 'undefined' ? window : null);
