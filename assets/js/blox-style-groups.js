(function (global) {
    "use strict";

    // 样式页签分组（通用背景规划第 2 轮，2026-09-02）。
    // 控件按 schema 的 group 键归组，无 group 或未知组落「general」；
    // 盒模型、可见设备和全局样式归入 general，搜索时仍可跨组查看。
    // 容器/Div 的专用样式块（workspace.php isSelectedContainerEl 分支)不参与。
    // 纯函数可被 node --test 直接测；Alpine 接线经 methods 混入编辑器组件
    //（先例：BloxBannerPanel / BloxHomeContentPanel）。
    var ORDER = ["general", "background", "animation"];
    var BOX_KEYS = [
        "style_margin", "style_margin_top", "style_margin_right", "style_margin_bottom", "style_margin_left",
        "style_padding", "style_padding_top", "style_padding_right", "style_padding_bottom", "style_padding_left",
    ];

    function groupOf(control) {
        var group = control && control.group ? String(control.group) : "general";
        return ORDER.indexOf(group) === -1 ? "general" : group;
    }

    /** 元素样式控件里实际出现的组，按 ORDER 顺序 */
    function groups(styleControls) {
        var present = {};
        (styleControls || []).forEach(function (c) { present[groupOf(c)] = true; });
        return ORDER.filter(function (g) { return present[g]; });
    }

    /** showAll 时原样返回（搜索、只看已修改、未启用分组都走这条） */
    function filter(list, activeGroup, showAll) {
        if (showAll) return list || [];
        return (list || []).filter(function (c) { return groupOf(c) === activeGroup; });
    }

    /** 盒模型键是否设了值——与服务端 boxStyle() 同口径：只认非空字符串 */
    function hasBoxValue(data) {
        return BOX_KEYS.some(function (k) {
            var v = (data || {})[k];
            return typeof v === "string" && v !== "";
        });
    }

    function hasModified(group, styleControls, isModified) {
        return (styleControls || []).some(function (c) {
            return groupOf(c) === group && isModified(c);
        });
    }

    var methods = {
        styleTabControls: function () {
            if (!this.selEl) return [];
            var self = this;
            return (this.elSchema(this.selEl.type).controls || []).filter(function (c) {
                return global.BloxHomeContentPanel.tabFor(self.selEl, c) === "style";
            });
        },
        /** 空数组 = 不启用分组（容器专用块、搜索中、只看已修改、组数不足 2） */
        styleGroups: function () {
            if (!this.selEl || this.isSelectedContainerEl()) return [];
            if (this.ctrlQuery.trim() || this.modifiedOnly) return [];
            var present = groups([{ group: "general" }].concat(this.styleTabControls()));
            return present.length > 1 ? present : [];
        },
        commonStyleVisible: function () {
            return !this.styleGroups().length || this.effectiveStyleGroup() === "general";
        },
        commonStyleModified: function () {
            var data = this.selEl && this.selEl.data || {};
            return hasBoxValue(data) || !!data._global_style || (Array.isArray(data._hide_on) && data._hide_on.length > 0);
        },
        setStyleGroup: function (group) { this.styleGroup = group; },
        /** 切换元素后，失效分组回落到常规。 */
        effectiveStyleGroup: function () {
            var present = this.styleGroups();
            if (present.indexOf(this.styleGroup) !== -1) return this.styleGroup;
            return present.length ? present[0] : "general";
        },
        styleGroupDot: function (group) {
            if (group === "general" && this.commonStyleModified()) return true;
            var self = this;
            return hasModified(group, this.styleTabControls(), function (c) { return self.isCtrlModified(c); });
        },
        /** 样式页签圆点涵盖通用设置及 schema 控件。 */
        styleTabDot: function () {
            if (!this.selEl) return false;
            if (this.commonStyleModified()) return true;
            var self = this;
            return this.styleTabControls().some(function (c) { return self.isCtrlModified(c); });
        },
    };

    var api = {
        ORDER: ORDER, BOX_KEYS: BOX_KEYS, groupOf: groupOf, groups: groups,
        filter: filter, hasBoxValue: hasBoxValue, hasModified: hasModified, methods: methods,
    };
    if (typeof module !== "undefined" && module.exports) module.exports = api;
    global.BloxStyleGroups = api;
})(typeof window !== "undefined" ? window : globalThis);
