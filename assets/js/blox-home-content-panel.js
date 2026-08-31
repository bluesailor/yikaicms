(function (global) {
    "use strict";

    var contentKeys = ["override_title", "override_content", "override_description", "override_button_text", "override_button_url"];
    var imageKeys = ["override_image", "override_tag_title", "override_tag_description"];
    var backgroundKeys = ["bg_image", "bg_color", "bg_overlay_color", "bg_overlay_opacity", "text_light"];

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

    var methods = {
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

    var api = { supports: supports, groupFor: groupFor, tabFor: tabFor, controls: controls, isImage: isImage, methods: methods };
    if (typeof module !== "undefined" && module.exports) module.exports = api;
    global.BloxHomeContentPanel = api;
})(typeof window !== "undefined" ? window : globalThis);
