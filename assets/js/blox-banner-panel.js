(function (global) {
    "use strict";

    function supports(node) {
        return !!(node && node.type === "home-block" && (node.data || {}).block_type === "banner");
    }

    function groupFor(key) {
        if (["banner_effect", "banner_content_motion", "banner_background_motion", "banner_speed", "banner_stagger"].includes(key)) return "motion";
        if (["banner_autoplay", "banner_navigation", "banner_pagination", "banner_pause_hover", "limit"].includes(key)) return "playback";
        return "common";
    }

    function controls(node, list, group, showAll) {
        if (!supports(node) || showAll) return list;
        return list.filter(function (control) { return groupFor(control.key) === group; });
    }

    var api = { supports: supports, controls: controls, groupFor: groupFor };
    if (typeof module !== "undefined" && module.exports) module.exports = api;
    global.BloxBannerPanel = api;
})(typeof window !== "undefined" ? window : globalThis);
