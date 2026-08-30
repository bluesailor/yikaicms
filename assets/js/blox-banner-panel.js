(function (global) {
    "use strict";

    function supports(node) {
        return !!(node && (node.type === "home-banner-item" || (node.type === "home-block" && (node.data || {}).block_type === "banner")));
    }

    function groupFor(key, node) {
        if (node && node.type === "home-banner-item") {
            if (["content_motion", "background_motion"].includes(key)) return "motion";
            if (["btn1_text", "btn1_url", "btn2_text", "btn2_url", "link_url", "link_target"].includes(key)) return "playback";
            return "common";
        }
        if (["banner_effect", "banner_content_motion", "banner_background_motion", "banner_speed", "banner_stagger"].includes(key)) return "motion";
        if (["banner_autoplay", "banner_navigation", "banner_pagination", "banner_pause_hover", "limit"].includes(key)) return "playback";
        return "common";
    }

    function controls(node, list, group, showAll) {
        if (!supports(node) || showAll) return list;
        var visible = list.filter(function (control) { return groupFor(control.key, node) === group; });
        if (node.type === "home-banner-item" && group === "common") {
            var order = ["image", "title", "subtitle", "image_mobile"];
            visible.sort(function (a, b) { return order.indexOf(a.key) - order.indexOf(b.key); });
        }
        return visible;
    }

    var api = { supports: supports, controls: controls, groupFor: groupFor };
    if (typeof module !== "undefined" && module.exports) module.exports = api;
    global.BloxBannerPanel = api;
})(typeof window !== "undefined" ? window : globalThis);
