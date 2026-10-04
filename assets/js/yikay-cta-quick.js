(function (global) {
    "use strict";
    var keys = { title: "override_title", text: "override_description", btn_text: "override_button_text", btn_url: "override_button_url" };
    function isCta(node) {
        return !!node && (node.type === "cta" || (node.type === "home-block" && (node.data || {}).block_type === "cta"));
    }
    function find(node) {
        if (isCta(node)) return [node];
        return ((node && node.data && node.data.children) || []).flatMap(find);
    }
    function target(section, selected) {
        if (selected) {
            var nested = find(selected);
            return nested.length === 1 ? nested[0] : null;
        }
        var found = ((section || {}).columns || []).flatMap(function (col) { return (col.elements || []).flatMap(find); });
        // Never guess which CTA a customer means when a section contains several.
        return found.length === 1 ? found[0] : null;
    }
    function promoteBackground(node, settings) {
        if (!node || node.type !== "home-block" || (!node.data.bg_image && !node.data.bg_color)) return;
        var data = node.data;
        settings.bg_image = data.bg_image || "";
        settings.bg_color = data.bg_color || "";
        settings.bg_opacity = data.bg_opacity ?? 100;
        if (data.bg_image) {
            settings.bg_overlay_color = data.bg_overlay_color || data.bg_color || "#000000";
            settings.bg_overlay_opacity = data.bg_overlay_opacity ?? (data.bg_color ? (data.bg_opacity ?? 100) : 100 - (data.bg_opacity ?? 100));
        }
        if (data.text_light) settings.text_tone = "light";
        ["bg_image", "bg_color", "bg_opacity", "bg_overlay_color", "bg_overlay_opacity"].forEach(function (key) { delete data[key]; });
    }
    var methods = {
        ctaQuickTarget() {
            // 选中了快捷面板没有的字段（如电话按钮）：让出位置给完整字段面板
            var field = String(this.selectedHomeField || "");
            if (field && Object.keys(keys).every(function (key) { return keys[key] !== field; })) return null;
            return target(this.sections[this.selectedSi], this.selEl);
        },
        ctaQuickValue(key) {
            var node = this.ctaQuickTarget();
            if (!node) return "";
            var data = node.data || {}, legacy = node.type === "home-block";
            var value = data[legacy ? keys[key] : key];
            if ((!legacy && data.use_home_text) || (legacy && !String(value ?? "").trim())) return this.ctaQuickSeeds[key] || "";
            return value ?? ((this.elSchema(node.type).defaults || {})[key] || "");
        },
        setCtaQuickValue(key, value) {
            var node = this.ctaQuickTarget();
            if (!node || !Object.prototype.hasOwnProperty.call(keys, key)) return;
            this.runCommand("edit-cta-content", function () {
                if (node.type === "cta" && node.data.use_home_text) {
                    Object.keys(keys).forEach(function (field) { node.data[field] = this.ctaQuickSeeds[field] || ""; }, this);
                    node.data.use_home_text = false;
                }
                node.data[node.type === "home-block" ? keys[key] : key] = value;
            });
        },
        ctaQuickBackground() {
            var node = this.ctaQuickTarget();
            return node && (node.type === "cta" || node.data.bg_image || node.data.bg_color) ? node.data : ((this.sections[this.selectedSi] || {}).settings || {});
        },
        setCtaQuickBackground(url) {
            var node = this.ctaQuickTarget();
            if (!node) return;
            this.runCommand("edit-cta-background", function () {
                var settings = this.sections[this.selectedSi].settings;
                promoteBackground(node, settings);
                (node.type === "cta" ? node.data : settings).bg_image = url;
            });
        },
        replaceCtaQuickBackground() {
            var node = this.ctaQuickTarget();
            this.openMedia(function (url) {
                if (this.ctaQuickTarget() !== node) return;
                this.setCtaQuickBackground(url);
            }.bind(this), { usage: "cta", source: "official" });
        },
        openCtaQuickBackground() {
            var node = this.ctaQuickTarget();
            if (node && node.type === "cta") {
                var walk = function (items, path) {
                    for (var i = 0; i < items.length; i++) {
                        var next = path.concat(i);
                        if (items[i] === node) return next;
                        var found = walk((items[i].data || {}).children || [], next);
                        if (found) return found;
                    }
                    return null;
                };
                var section = this.sections[this.selectedSi];
                for (var ci = 0; ci < section.columns.length; ci++) {
                    var path = walk(section.columns[ci].elements || [], [this.selectedSi, ci]);
                    if (path) { this.selectPath(path.join('.'), false); break; }
                }
            } else {
                this.runCommand("unify-cta-background", function () { promoteBackground(node, this.sections[this.selectedSi].settings); });
                this.selectSection(this.selectedSi);
            }
            this.panelTab = "style";
        },
        ctaQuickDetails: false,
    };
    var api = { target: target, promoteBackground: promoteBackground, methods: methods };
    if (typeof module !== "undefined" && module.exports) module.exports = api;
    global.BloxCtaQuick = api;
})(typeof window !== "undefined" ? window : globalThis);
