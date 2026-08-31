(function (global) {
    "use strict";

    var contentKeys = ["override_title", "override_content", "override_description", "override_button_text", "override_button_url"];
    var imageKeys = ["override_image", "override_tag_title", "override_tag_description"];
    var backgroundKeys = ["bg_image", "bg_color", "bg_overlay_color", "bg_overlay_opacity", "text_light"];
    var inheritedKeys = ["override_title", "override_content", "override_image", "override_tag_title", "override_tag_description", "override_button_text", "override_button_url"];

    function supports(node) {
        return !!(node && node.type === "home-block" && ["about", "cta"].includes((node.data || {}).block_type));
    }

    function groupFor(key) {
        if (contentKeys.includes(key)) return "content";
        if (imageKeys.includes(key) || backgroundKeys.includes(key)) return "media";
        if (["override_layout", "override_breakpoint", "override_ratio"].includes(key) || key.startsWith("title_decor_")) return "layout";
        return "more";
    }

    function tabFor(node, control) {
        if (supports(node) && node.data.block_type === "cta" && backgroundKeys.includes(control.key)) return "content";
        return control.tab || (control.type === "color" ? "style" : "content");
    }

    function controls(node, list, group, showAll) {
        if (!supports(node) || showAll) return list;
        return list.filter(function (control) { return groupFor(control.key) === group; });
    }

    function isImage(node, key) {
        return supports(node) && key === (node.data.block_type === "cta" ? "bg_image" : "override_image");
    }

    function fieldState(node, key, seeds) {
        if (!supports(node) || node.data.block_type !== "about" || !inheritedKeys.includes(key)
            || !seeds || !Object.prototype.hasOwnProperty.call(seeds, key)) return null;
        // Match PHP trim used by runtimeConfigOverrides, including the string "0".
        var stored = String(node.data[key] ?? "");
        var inherited = stored.replace(/^[ \t\n\r\v\0]+|[ \t\n\r\v\0]+$/g, "") === "";
        return { inherited: inherited, value: inherited ? String((seeds || {})[key] ?? "") : stored };
    }

    var methods = {
        homeContentSource() {
            var node = this.selEl;
            if (!node) return null;
            var key = node.type === 'home-block' ? (node.data || {}).block_type : node.type;
            return Object.prototype.hasOwnProperty.call(this.homeSourceLinks || {}, key) ? this.homeSourceLinks[key] : null;
        },
        homeContentField(key) {
            return fieldState(this.selEl, key, (this.homeFieldSeeds || {}).about);
        },
        homeContentPlaceholder(ctrl) {
            var state = this.homeContentField(ctrl.key);
            return state && state.inherited && state.value ? state.value : (ctrl.placeholder || "");
        },
        homeContentImageValue(key) {
            var state = this.homeContentField(key);
            return state ? state.value : String(((this.selEl || {}).data || {})[key] || "");
        },
        inheritHomeContentField(key) {
            var state = this.homeContentField(key), node = this.selEl;
            if (!state || state.inherited) return;
            // A reset is a discrete action, not part of the preceding typing batch.
            this.flushHistory(true);
            this.runCommand("inherit-home-content-field", function () { node.data[key] = ""; });
            this.flushHistory(true);
        },
        setHomeContentGroup(group) {
            if (!["content", "media", "layout", "more"].includes(group)) return;
            this.homeContentGroup = group;
            this.selectedHomeField = "";
            this.selectedHomeColumn = "";
        },
        replaceHomeContentImage(key) {
            var node = this.selEl;
            if (!isImage(node, key)) return;
            var self = this;
            this.openMedia(function (url) {
                if (self.selEl !== node) return;
                self.runCommand("replace-home-content-image", function () { node.data[key] = url; });
            }, node.data.block_type === "cta" ? { usage: "cta", source: "official" } : {});
        },
    };

    var api = { supports: supports, groupFor: groupFor, tabFor: tabFor, controls: controls, isImage: isImage, fieldState: fieldState, methods: methods };
    if (typeof module !== "undefined" && module.exports) module.exports = api;
    global.BloxHomeContentPanel = api;
})(typeof window !== "undefined" ? window : globalThis);
