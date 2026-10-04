/**
 * R2A：同类元素的「复制样式 / 粘贴样式」（编辑器内部剪贴板）。
 *
 * 白名单从控件元数据推导：tab === 'style' 的控件即样式字段（含响应式值），
 * 再排除交互动作（group === 'animation'）与全局样式引用（typography_role——
 * 修改共享定义不属于"复制本地外观"）；按钮的悬停效果是历史上的混合字段
 * （未标 style tab），列入显式适配表。内容、链接、ID、命名、动态绑定、条件、
 * 查询一律不在 style tab 上，天然不进白名单。
 *
 * 粘贴语义（测试固定）：替换白名单内本地样式；剪贴板里未出现的白名单属性
 * 清除为继承/默认。一次粘贴 = 一条可撤销历史（runCommand + sections watcher）。
 * 只用编辑器内部剪贴板，不读系统剪贴板，不跨站传输；粘贴的数据仍走服务端
 * 保存管线的能力/保护字段校验，不能借粘贴启用未授权的高级字段。
 */
(function (global) {
    "use strict";

    var SUPPORTED = { heading: true, button: true, text: true };
    var DENY = { typography_role: true, bg_image: true, bg_video: true };
    var ADAPT = { button: ["hover_effect"] };

    function styleKeys(type, schema) {
        if (!SUPPORTED[type] || !schema || !Array.isArray(schema.controls)) return [];
        var keys = [];
        schema.controls.forEach(function (control) {
            var key = String((control && control.key) || "");
            if (key === "" || DENY[key]) return;
            if (control.type === "image" || control.type === "video_url") return;
            if (control.group === "animation") return;
            if (control.tab === "style" && keys.indexOf(key) === -1) keys.push(key);
        });
        (ADAPT[type] || []).forEach(function (key) {
            if (keys.indexOf(key) === -1) keys.push(key);
        });
        return keys;
    }

    function clone(value) {
        return value === undefined ? undefined : JSON.parse(JSON.stringify(value));
    }

    function mixin(text) {
        text = text || {};
        return {
            _styleClipboard: null,

            styleClipboardKeys(type) {
                return styleKeys(type, typeof this.elSchema === "function" ? this.elSchema(type) : null);
            },

            canCopyElementStyle(el) {
                return !!(el && this.styleClipboardKeys(el.type).length);
            },

            canPasteElementStyle(el) {
                return !!(el && this._styleClipboard && this._styleClipboard.type === el.type
                    && this.styleClipboardKeys(el.type).length);
            },

            /** 不可粘贴的原因（禁用按钮的 title）；可粘贴时返回空串。 */
            pasteStyleDisabledReason(el) {
                if (!el || !this.styleClipboardKeys(el.type).length) return text.unsupported || "";
                if (!this._styleClipboard) return text.empty || "";
                if (this._styleClipboard.type !== el.type) {
                    return String(text.typeMismatch || "").replace(":type", this._styleClipboard.label || this._styleClipboard.type);
                }
                return "";
            },

            copyElementStyle(el) {
                if (!this.canCopyElementStyle(el)) return false;
                var data = el.data && typeof el.data === "object" ? el.data : {};
                var styles = {};
                this.styleClipboardKeys(el.type).forEach(function (key) {
                    if (Object.prototype.hasOwnProperty.call(data, key)) styles[key] = clone(data[key]);
                });
                var schema = typeof this.elSchema === "function" ? this.elSchema(el.type) : null;
                this._styleClipboard = { type: el.type, label: (schema && schema.label) || el.type, styles: styles };
                if (this.toast) this.toast(text.copied || "");
                return true;
            },

            /** v1.29 Reset Style：清除白名单内全部本地样式覆盖（回默认/继承），一条可撤销历史。 */
            canResetElementStyle(el) {
                return this.canCopyElementStyle(el);
            },

            resetElementStyle(el) {
                if (!this.canResetElementStyle(el)) return false;
                var keys = this.styleClipboardKeys(el.type);
                var run = typeof this.runCommand === "function"
                    ? this.runCommand.bind(this)
                    : function (name, fn) { fn(); return { ok: true }; };
                var applied = run("reset-element-style", function () {
                    if (!el.data || typeof el.data !== "object") return;
                    keys.forEach(function (key) { delete el.data[key]; });
                });
                if (!applied || applied.ok === false) return false;
                if (this.toast) this.toast(text.reset || "");
                return true;
            },

            pasteElementStyle(el) {
                if (!el) return false;
                if (!this.canPasteElementStyle(el)) {
                    var reason = this.pasteStyleDisabledReason(el);
                    if (reason && this.toast) this.toast(reason);
                    return false;
                }
                var clipboard = this._styleClipboard;
                var keys = this.styleClipboardKeys(el.type);
                var run = typeof this.runCommand === "function"
                    ? this.runCommand.bind(this)
                    : function (name, fn) { fn(); return { ok: true }; };
                var applied = run("paste-element-style", function () {
                    if (!el.data || typeof el.data !== "object") el.data = {};
                    keys.forEach(function (key) {
                        if (Object.prototype.hasOwnProperty.call(clipboard.styles, key)) {
                            el.data[key] = clone(clipboard.styles[key]);
                        } else {
                            // 未出现的白名单属性清除为继承/默认，而不是保留旧覆盖
                            delete el.data[key];
                        }
                    });
                });
                if (!applied || applied.ok === false) return false;
                if (this.toast) this.toast(text.pasted || "");
                return true;
            },
        };
    }

    var api = { mixin: mixin, styleKeys: styleKeys };
    if (typeof module !== "undefined" && module.exports) module.exports = api;
    global.YikaiBloxStyleClipboard = api;
})(typeof window !== "undefined" ? window : globalThis);
