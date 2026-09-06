(function (global) {
    "use strict";
    var backgrounds = {
        section: { key: "bg_image", prefix: "bg_" },
        container: { key: "container_bg_image", prefix: "container_bg_" },
        column: { key: "card_bg_image", prefix: "card_bg_" },
    };
    function hasBackgroundValue(value) {
        return value !== null && value !== undefined && String(value).trim() !== "";
    }
    function collectBackgroundTarget(result, target, paintKeys, cleanupKeys) {
        if (!target || typeof target !== "object") return;
        var active = paintKeys.filter(function (key) { return hasBackgroundValue(target[key]); });
        if (active.length) result.push({ target: target, keys: active.concat(cleanupKeys || []) });
    }
    function collectElementBackgrounds(result, element) {
        if (!element || typeof element !== "object") return;
        var data = element.data;
        collectBackgroundTarget(
            result,
            data,
            ["bg_color", "bg_image", "bg_gradient", "bg_video"],
            ["bg_overlay", "bg_overlay_color", "bg_overlay_opacity"]
        );
        var children = data && Array.isArray(data.children) ? data.children : [];
        children.forEach(function (child) { collectElementBackgrounds(result, child); });
    }
    var methods = {
        imageControlTarget(scope) {
            if (scope === "element") return this.selEl && this.selEl.data;
            if (scope === "column") return this.selectedCol();
            if (scope === "section" || scope === "container") return this.sel && this.sel.settings;
            return null;
        },
        imageControlValue(scope, key) {
            var target = this.imageControlTarget(scope);
            return String(target && target[key] || "");
        },
        setImageControl(scope, key, url, discrete) {
            var target = this.imageControlTarget(scope);
            if (!target || typeof url !== "string" || ["__proto__", "prototype", "constructor"].includes(key)) return;
            var background = backgrounds[scope];
            if (scope !== "element" && (!background || key !== background.key)) return;
            if (String(target[key] || "") === url) return;
            if (discrete !== false) this.flushHistory(true);
            this.runCommand("set-image-" + scope, function () {
                target[key] = url;
                if (!url || !background) return;
                var defaults = {};
                defaults[background.prefix + "overlay_color"] = "#000000";
                defaults[background.prefix + "overlay_opacity"] = 45;
                if (scope === "section") defaults.bg_position = "center";
                Object.keys(defaults).forEach(function (name) {
                    if (!Object.prototype.hasOwnProperty.call(target, name)) target[name] = defaults[name];
                });
            });
            if (discrete !== false) this.flushHistory(true);
        },
        pickImageControl(scope, key) {
            var target = this.imageControlTarget(scope), self = this;
            if (!target) return;
            this.openMedia(function (url) {
                // Selection may have changed while an asynchronous picker was open.
                if (self.imageControlTarget(scope) === target) self.setImageControl(scope, key, url);
            }, scope === "element" ? {} : { usage: "hero-bg" });
        },
        videoControlTarget(scope) {
            if (scope === "element") return this.selEl && this.selEl.data;
            if (scope === "section") return this.sel && this.sel.settings;
            return null;
        },
        videoControlValue(scope, key) {
            var target = this.videoControlTarget(scope);
            return String(target && target[key] || "");
        },
        setVideoControl(scope, key, url, discrete) {
            var target = this.videoControlTarget(scope);
            if (!target || typeof url !== "string" || ["__proto__", "prototype", "constructor"].includes(key)) return;
            if ((scope === "section" && key !== "bg_video") || (scope !== "section" && scope !== "element")) return;
            if (String(target[key] || "") === url) return;
            if (discrete !== false) this.flushHistory(true);
            this.runCommand("set-video-" + scope, function () { target[key] = url; });
            if (discrete !== false) this.flushHistory(true);
        },
        pickVideoControl(scope, key) {
            var target = this.videoControlTarget(scope), self = this;
            if (!target) return;
            this.openMedia(function (url) {
                if (self.videoControlTarget(scope) === target) self.setVideoControl(scope, key, url);
            }, { type: "video" });
        },
        setSectionBackgroundImage(url) { this.setImageControl("section", "bg_image", url); },
        setContainerBackgroundImage(url) { this.setImageControl("container", "container_bg_image", url); },
        pickContainerBackgroundImage() { this.pickImageControl("container", "container_bg_image"); },
        setColumnBackgroundImage(url) { this.setImageControl("column", "card_bg_image", url); },
        pickColumnBackgroundImage() { this.pickImageControl("column", "card_bg_image"); },
        sectionBackgroundVideoObstructions() {
            var section = this.sel;
            if (!section || !section.settings || !hasBackgroundValue(section.settings.bg_video)) return [];
            var result = [];
            collectBackgroundTarget(
                result,
                section.settings,
                ["container_bg", "container_bg_image"],
                ["container_bg_overlay_color", "container_bg_overlay_opacity"]
            );
            (Array.isArray(section.columns) ? section.columns : []).forEach(function (column) {
                collectBackgroundTarget(
                    result,
                    column,
                    ["card_bg", "card_bg_image"],
                    ["card_bg_overlay_color", "card_bg_overlay_opacity"]
                );
                (Array.isArray(column.elements) ? column.elements : []).forEach(function (element) {
                    collectElementBackgrounds(result, element);
                });
            });
            return result;
        },
        sectionBackgroundVideoObstructionCount() {
            return this.sectionBackgroundVideoObstructions().length;
        },
        clearSectionBackgroundVideoObstructions() {
            var obstructions = this.sectionBackgroundVideoObstructions();
            if (!obstructions.length) return 0;
            this.flushHistory(true);
            this.runCommand("clear-section-video-obstructions", function () {
                obstructions.forEach(function (entry) {
                    entry.keys.forEach(function (key) {
                        if (Object.prototype.hasOwnProperty.call(entry.target, key)) {
                            entry.target[key] = key.endsWith("_opacity") ? 0 : "";
                        }
                    });
                });
            });
            this.flushHistory(true);
            if (this.toast && this.backgroundVideoObstructionText) {
                this.toast(this.backgroundVideoObstructionText.cleared.replace(":count", String(obstructions.length)));
            }
            return obstructions.length;
        },
    };
    var api = { methods: methods };
    if (typeof module !== "undefined" && module.exports) module.exports = api;
    global.BloxImageControl = api;
})(typeof window !== "undefined" ? window : globalThis);
