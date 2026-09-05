/**
 * Blox 批量属性（R3）：稳定 id 取值、混合值判断与不可变 data 补丁。
 * 编辑器适配以 mixin 注入，数组运算保持无 DOM、无请求，便于独立回归。
 */
(function (global) {
    "use strict";

    var MIXED = "__mixed__";
    var STYLE_KEYS = ["visual_size", "align", "animation", "animation_speed", "animation_delay"];

    function idOf(item) {
        return item && item.id !== undefined && item.id !== null ? String(item.id) : "";
    }

    function pickByIds(list, ids) {
        var wanted = (ids || []).map(String);
        var items = (Array.isArray(list) ? list : []).filter(function (item) {
            return wanted.indexOf(idOf(item)) !== -1;
        });
        var missing = wanted.filter(function (id) {
            return !items.some(function (item) { return idOf(item) === id; });
        });
        return { items: items, missing: missing };
    }

    function equalValue(left, right) {
        return JSON.stringify(left) === JSON.stringify(right);
    }

    function valueState(items, resolver) {
        var source = Array.isArray(items) ? items : [];
        if (!source.length || typeof resolver !== "function") {
            return { mixed: false, value: "", count: 0 };
        }
        var first = resolver(source[0]);
        var mixed = source.slice(1).some(function (item) {
            return !equalValue(first, resolver(item));
        });
        return { mixed: mixed, value: mixed ? MIXED : first, count: source.length };
    }

    function commonType(items) {
        var source = Array.isArray(items) ? items : [];
        if (!source.length) return "";
        var type = String(source[0].type || "");
        return type && source.every(function (item) { return String(item.type || "") === type; }) ? type : "";
    }

    function planDataPatch(list, ids, patcher) {
        var source = Array.isArray(list) ? list : [];
        var picked = pickByIds(source, ids);
        if (picked.missing.length || picked.items.length < 2 || typeof patcher !== "function") {
            return { error: "invalid", missing: picked.missing, changed: 0 };
        }
        var wanted = (ids || []).map(String);
        var changed = 0;
        var next = source.map(function (item) {
            if (wanted.indexOf(idOf(item)) === -1) return item;
            var before = item && item.data && typeof item.data === "object" ? item.data : {};
            var data = Object.assign({}, before);
            patcher(data, item);
            if (equalValue(before, data)) return item;
            changed++;
            return Object.assign({}, item, { data: data });
        });
        return { list: next, error: null, changed: changed, missing: [] };
    }

    function mixin() {
        return {
            batchPropertyContext: function () {
                if (!this.multiSelActive || !this.multiSelActive()) return null;
                var actions = this.actionsModule ? this.actionsModule() : null;
                var context = this.multiScopeContext ? this.multiScopeContext() : null;
                if (!actions || !context || context.level === "section") return null;
                var picked = actions.pickByIds(context.list, this.multiSel.ids || []);
                if (picked.missing.length || picked.items.length < 2) return null;
                return Object.assign({}, context, {
                    items: picked.items,
                    type: commonType(picked.items),
                });
            },

            batchPropertySameType: function () {
                var context = this.batchPropertyContext();
                return !!(context && context.type);
            },

            batchPropertyTypeLabel: function () {
                var context = this.batchPropertyContext();
                return context && context.type ? String(this.elSchema(context.type).label || context.type) : "";
            },

            batchStyleControls: function () {
                var context = this.batchPropertyContext();
                if (!context || !context.type) return [];
                var controls = (this.elSchema(context.type).controls || []).filter(function (control) {
                    return STYLE_KEYS.indexOf(String(control.key || "")) !== -1 && control.type === "select";
                });
                var animation = controls.find(function (control) { return control.key === "animation"; });
                if (animation) {
                    var state = this.batchControlState(animation);
                    if (!state.mixed && state.value === "") {
                        controls = controls.filter(function (control) {
                            return control.key !== "animation_speed" && control.key !== "animation_delay";
                        });
                    }
                }
                return controls;
            },

            batchHasResponsiveControls: function () {
                return this.batchStyleControls().some(function (control) { return control.responsive === true; });
            },

            batchControlOptions: function (control) {
                return Object.keys((control && control.options) || {}).map(function (value) {
                    return { value: value, label: String(control.options[value]) };
                });
            },

            batchControlState: function (control) {
                var context = this.batchPropertyContext();
                if (!context || !control) return { mixed: false, value: "", count: 0 };
                var self = this;
                return valueState(context.items, function (item) {
                    var data = item.data && typeof item.data === "object" ? item.data : {};
                    var fallback = control.default === undefined || control.default === null ? "" : control.default;
                    var raw = Object.prototype.hasOwnProperty.call(data, control.key) ? data[control.key] : fallback;
                    return control.responsive && global.BloxResponsive
                        ? global.BloxResponsive.valueFor(raw, self.previewDevice, control.options || {}, fallback)
                        : raw;
                });
            },

            batchControlDisplay: function (control) {
                var state = this.batchControlState(control);
                return state.mixed ? MIXED : String(state.value === undefined || state.value === null ? "" : state.value);
            },

            batchBoxKinds: function () {
                var context = this.batchPropertyContext();
                if (!context || !context.items.every(function (item) {
                    return this.supportsBoxStyles(item.type);
                }, this)) return [];
                return this.boxKinds || [];
            },

            batchSpacingState: function (kind) {
                var context = this.batchPropertyContext();
                if (!context) return { mixed: false, value: "", count: 0 };
                var sides = ["top", "right", "bottom", "left"];
                if (context.items.some(function (item) {
                    var data = item.data || {};
                    return sides.some(function (side) {
                        var value = data["style_" + kind + "_" + side];
                        return value !== undefined && value !== null && value !== "";
                    });
                })) return { mixed: true, value: MIXED, count: context.items.length };
                return valueState(context.items, function (item) {
                    var value = (item.data || {})["style_" + kind];
                    return value === undefined || value === null ? "" : value;
                });
            },

            batchSpacingDisplay: function (kind) {
                var state = this.batchSpacingState(kind);
                return state.mixed ? MIXED : String(state.value || "");
            },

            batchSpacingOptions: function (kind) {
                return this.boxSpacingOptions(kind === "margin").filter(function (option) {
                    return option.k !== "exact" && option.k !== "custom";
                });
            },

            batchApplyControl: function (control, value, doneText) {
                if (!control || value === MIXED) return;
                var context = this.batchPropertyContext();
                if (!context) return;
                var self = this;
                var changed = 0;
                var applied = this.runCommand("batch-set-style", function () {
                    var plan = planDataPatch(context.list, self.multiSel.ids || [], function (data) {
                        var fallback = control.default === undefined || control.default === null ? "" : control.default;
                        data[control.key] = control.responsive && global.BloxResponsive
                            ? global.BloxResponsive.setFor(data[control.key], self.previewDevice, value, control.options || {}, fallback)
                            : value;
                    });
                    if (plan.error) throw new Error("Invalid batch property selection");
                    changed = plan.changed;
                    if (changed > 0) self.replaceList(context.list, plan.list);
                });
                if (!applied.ok || changed < 1) return;
                this.highlightCanvasSelection(false);
                this.toast(String(doneText || "").replace(":count", String(changed)));
            },

            batchApplySpacing: function (kind, value, doneText) {
                if (["margin", "padding"].indexOf(kind) === -1 || value === MIXED) return;
                var context = this.batchPropertyContext();
                if (!context) return;
                var self = this;
                var changed = 0;
                var applied = this.runCommand("batch-set-style", function () {
                    var plan = planDataPatch(context.list, self.multiSel.ids || [], function (data) {
                        ["top", "right", "bottom", "left"].forEach(function (side) {
                            delete data["style_" + kind + "_" + side];
                        });
                        if (value === "") delete data["style_" + kind];
                        else data["style_" + kind] = value;
                    });
                    if (plan.error) throw new Error("Invalid batch spacing selection");
                    changed = plan.changed;
                    if (changed > 0) self.replaceList(context.list, plan.list);
                });
                if (!applied.ok || changed < 1) return;
                this.highlightCanvasSelection(false);
                this.toast(String(doneText || "").replace(":count", String(changed)));
            },
        };
    }

    var api = {
        MIXED: MIXED,
        pickByIds: pickByIds,
        valueState: valueState,
        commonType: commonType,
        planDataPatch: planDataPatch,
        mixin: mixin,
    };
    global.YikaiBloxBatchProperties = api;
    if (typeof module === "object" && module && module.exports) module.exports = api;
})(typeof window !== "undefined" ? window : globalThis);
