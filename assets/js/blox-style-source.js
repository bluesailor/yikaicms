(function (global) {
    "use strict";
    function state(data, ctrl, device, responsive, styles) {
        var value = data[ctrl.key], source = "default", reset = false;
        var field = { color: "color", bg_color: "background", border_color: "border_color", radius: "radius" }[ctrl.key];
        var style = (styles || []).find(function (item) { return item.id === data._global_style; }) || data._global_style_snapshot;
        // Global presets currently win in BlockRenderer; disclose that precedence honestly.
        if (data._global_style && field && style && style[field]) return { source: "global", reset: false };
        if (ctrl.responsive && device !== "desktop") {
            var tier = responsive.stateFor(value, device, ctrl.options || {}, ctrl.default ?? "");
            return { source: tier.overridden ? (device === "mobile" ? "mobile" : "tablet") : (tier.source === "t" ? "fromTablet" : "fromDesktop"), reset: tier.overridden };
        }
        if (ctrl.responsive) value = responsive.valueFor(value, "desktop", ctrl.options || {}, ctrl.default ?? "");
        if (value !== undefined && value !== null && value !== "" && JSON.stringify(value) !== JSON.stringify(ctrl.default ?? "")) {
            source = /^var\(--yk-color-(primary|secondary)\)$/.test(String(value)) ? "theme" : (/^var\(--yk-color-/.test(String(value)) ? "token" : "local");
            reset = true;
        } else if (ctrl.key === "color" && !value && !(ctrl.default || "")) source = "theme";
        return { source: source, reset: reset };
    }
    var methods = {
        sectionStyleSource(key, fallback) {
            var ctrl = {key:key,default:fallback,responsive:['padding','gap'].includes(key),options:{none:true,sm:true,md:true,lg:true,xl:true}};
            var result = state((this.sel || {}).settings || {}, ctrl, this.previewDevice, global.BloxResponsive, []);
            if (key === 'max_width' && result.source === 'default') result.source = 'theme';
            return result;
        },
        restoreSectionStyle(key, fallback) {
            if (!this.sel) return;
            this.flushHistory(true);
            this.runCommand('restore-section-style', function () {
                if (['padding','gap'].includes(key)) {
                    if (this.previewDevice !== 'desktop') this.inheritSectionResponsiveValue(key, fallback);
                    else this.setSectionResponsiveValue(key, fallback, fallback);
                } else this.sel.settings[key] = fallback;
            });
            this.flushHistory(true);
        },
        controlStyleSource(ctrl) { return state((this.selEl || {}).data || {}, ctrl, this.previewDevice, global.BloxResponsive, this.designSystem.styles); },
        restoreControlStyle(ctrl) {
            if (!this.selEl || !this.controlStyleSource(ctrl).reset) return;
            this.flushHistory(true);
            this.runCommand("restore-style-inheritance", function () {
                if (ctrl.responsive && this.previewDevice !== "desktop") this.inheritControlValue(ctrl);
                else if (ctrl.responsive && typeof this.selEl.data[ctrl.key] === "object") {
                    this.selEl.data[ctrl.key] = global.BloxResponsive.setFor(this.selEl.data[ctrl.key], "desktop", ctrl.default ?? "", this.controlOptions(ctrl), ctrl.default ?? "");
                } else delete this.selEl.data[ctrl.key];
            });
            this.flushHistory(true);
        },
    };
    var api = { state: state, methods: methods };
    if (typeof module !== "undefined" && module.exports) module.exports = api;
    global.BloxStyleSource = api;
})(typeof window !== "undefined" ? window : globalThis);
