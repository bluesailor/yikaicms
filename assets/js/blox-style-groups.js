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

    /**
     * 检索可匹配文本：控件名 / 控件键 / 所在区块名 / 所属分组名（TASK-003 D）。
     * 纯函数，供 node --test 直接测。
     */
    function searchHaystack(control, groupLabel) {
        if (!control) return "";
        return [control.label, control.key, control.section, groupLabel]
            .filter(function (v) { return typeof v === "string" && v !== ""; })
            .join(" ")
            .toLowerCase();
    }

    /** 关键词是否命中该控件；空关键词一律命中。 */
    function matchesQuery(control, query, groupLabel) {
        var q = String(query === undefined || query === null ? "" : query).trim().toLowerCase();
        if (q === "") return true;
        return searchHaystack(control, groupLabel).indexOf(q) !== -1;
    }

    /**
     * 搜索 / 只看已修改过滤（模块内纯逻辑）：
     * 命中范围＝控件名/键 + 所在区块名 + 所属分组名；只看已修改走注入的谓词。
     * 惰性调用谓词：不需要时（modifiedOnly=false）不依赖宿主提供 isModified。
     */
    function searchFilter(controls, query, modifiedOnly, isModified, groupLabels) {
        return (controls || []).filter(function (c) {
            var label = (groupLabels || {})[groupOf(c)] || "";
            if (!matchesQuery(c, query, label)) return false;
            if (modifiedOnly && !(typeof isModified === "function" && isModified(c))) return false;
            return true;
        });
    }

    /**
     * 可见候选集（TASK-003 R01）：**分组计算与最终渲染必须用同一批候选控件**。
     *
     * 此前 styleGroups() 直接拿未过滤的 styleTabControls() 算命中组，隐藏控件（editor_hidden、
     * 条件不满足、循环上下文、动画开关关闭等）也参与计算 → 会造出"幽灵分组"，
     * 于是有可见命中时仍可能默认落进空分组。这里把"先按宿主谓词排除、再按检索/只看已修改过滤"
     * 固化为唯一的候选集入口；分组筛选由调用方（visibleCtrls）在其后做，二者不相互递归。
     *
     * @param {Array} controls 宿主提供的原始控件（同一类型、同一页签）
     * @param {{isExcluded?: Function, isModified?: Function, query?: string, modifiedOnly?: boolean, groupLabels?: Object}} options
     */
    function visibleCandidates(controls, options) {
        var opts = options || {};
        var isExcluded = typeof opts.isExcluded === "function" ? opts.isExcluded : null;
        var kept = (controls || []).filter(function (c) {
            return !(isExcluded && isExcluded(c));
        });
        return searchFilter(kept, opts.query, opts.modifiedOnly, opts.isModified, opts.groupLabels);
    }

    /** 盒模型键是否设了值——与服务端 boxStyle() 同口径：只认非空字符串 */
    function hasBoxValue(data) {
        return BOX_KEYS.some(function (k) {
            var v = (data || {})[k];
            return typeof v === "string" && v !== "";
        });
    }

    /**
     * 常规（通用设置）占位项（TASK-003 R02）。
     *
     * 间距、设备可见性、全局样式等通用设置**不一定在元素 schema 里**（由独立块渲染），
     * 但它们属于常规分组、必须可达：原先无搜索时靠 `[{group:'general'}]` 合成组保证，
     * R01 改成"只用可见候选"后该合成项丢失 → schema 只有 background/animation 的元素
     * 常规组永远选不中、通用设置整块消失。这里将其做成正式占位项：
     * 分组与检索都当普通候选对待，是否排除由宿主的 isExcluded 决定。
     *
     * @param {string} searchText 通用设置的可检索文本（由调用方传入本地化文案）
     */
    function commonMarker(searchText) {
        return { key: "common_style", group: "general", label: typeof searchText === "string" ? searchText : "" };
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
        /**
         * 空数组 = 不启用分组（容器专用块、组数不足 2）。
         * TASK-003 D + R01：搜索 / 只看已修改时不再清空分组，只列**有命中的组**；
         * 候选集一律取自宿主的可见候选（styleCandidates），与最终渲染同源——
         * 隐藏控件不得参与分组计算（否则会出现默认落进空分组的幽灵组）。
         */
        styleGroups: function () {
            if (!this.selEl || this.isSelectedContainerEl()) return [];
            var source = typeof this.styleCandidates === "function" ? this.styleCandidates() : [];
            var present = groups(source);
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
        searchHaystack: searchHaystack, matchesQuery: matchesQuery,
        searchFilter: searchFilter, visibleCandidates: visibleCandidates, commonMarker: commonMarker,
        filter: filter, hasBoxValue: hasBoxValue, hasModified: hasModified, methods: methods,
    };
    if (typeof module !== "undefined" && module.exports) module.exports = api;
    global.BloxStyleGroups = api;
})(typeof window !== "undefined" ? window : globalThis);
