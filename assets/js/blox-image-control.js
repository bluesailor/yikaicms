(function (global) {
    "use strict";
    var backgrounds = {
        section: { key: "bg_image", prefix: "bg_" },
        container: { key: "container_bg_image", prefix: "container_bg_" },
        column: { key: "card_bg_image", prefix: "card_bg_" },
    };
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
        setSectionBackgroundImage(url) { this.setImageControl("section", "bg_image", url); },
        setContainerBackgroundImage(url) { this.setImageControl("container", "container_bg_image", url); },
        pickContainerBackgroundImage() { this.pickImageControl("container", "container_bg_image"); },
        setColumnBackgroundImage(url) { this.setImageControl("column", "card_bg_image", url); },
        pickColumnBackgroundImage() { this.pickImageControl("column", "card_bg_image"); },
    };
    var api = { methods: methods };
    if (typeof module !== "undefined" && module.exports) module.exports = api;
    global.BloxImageControl = api;
})(typeof window !== "undefined" ? window : globalThis);
