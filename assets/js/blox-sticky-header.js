/**
 * Blox 吸顶头部：定位由 CSS position:sticky 承担（无 JS 也不塌），
 * 本脚本只负责滚动态视觉——离开顶部时加 .yk-stuck（阴影/底色），回到顶部移除。
 * 幂等绑定（data-yk-sticky-bound），与预览局部刷新共存。
 */
(function (global) {
    "use strict";

    function enabledFor(header, viewportWidth) {
        var width = Number(viewportWidth || 0);
        var device = width >= 1024 ? "desktop" : (width >= 768 ? "tablet" : "mobile");
        return header.getAttribute("data-yk-sticky-" + device) !== "0";
    }

    function stateFor(options) {
        var y = Math.max(0, Number(options.y) || 0);
        var lastY = Math.max(0, Number(options.lastY) || 0);
        var stuck = options.enabled !== false && y > 4;
        var hidden = false;
        if (stuck && options.behavior === "scroll-up") {
            var delta = y - lastY;
            var threshold = Math.max(24, Number(options.headerHeight) || 0);
            if (delta > 2 && y > threshold) hidden = true;
            else if (delta >= -2) hidden = options.wasHidden === true;
        }
        return {
            stuck: stuck,
            hidden: hidden,
            state: stuck ? "stuck" : (options.overlay ? "overlay" : "normal"),
        };
    }

    function bind(root) {
        root = root || global.document;
        if (!root || !root.querySelector) return null;
        var header = root.querySelector(".yk-sticky-header");
        if (!header || header.getAttribute("data-yk-sticky-bound") === "1") return null;
        header.setAttribute("data-yk-sticky-bound", "1");

        var lastY = global.scrollY || global.pageYOffset || 0;
        var hidden = false;
        var update = function () {
            var y = global.scrollY || global.pageYOffset || 0;
            var overlay = root.documentElement
                && root.documentElement.classList.contains("yk-home-header-overlay")
                && header.getAttribute("data-yk-overlay-enabled") === "1";
            var next = stateFor({
                y: y,
                lastY: lastY,
                headerHeight: header.offsetHeight || 0,
                behavior: header.getAttribute("data-yk-sticky-behavior") || "always",
                enabled: enabledFor(header, global.innerWidth || 0),
                overlay: overlay,
                wasHidden: hidden,
            });
            hidden = next.hidden;
            lastY = y;
            header.classList.toggle("yk-stuck", next.stuck);
            header.classList.toggle("yk-sticky-hidden", next.hidden);
            header.setAttribute("data-yk-header-state", next.state);
        };

        global.addEventListener("scroll", update, { passive: true });
        global.addEventListener("resize", update, { passive: true });
        update();
        return { header: header, update: update };
    }

    global.BloxStickyHeader = {
        bind: bind,
        enabledFor: enabledFor,
        stateFor: stateFor,
    };

    if (!global.document) return;
    if (global.document.readyState === "loading") {
        global.document.addEventListener("DOMContentLoaded", function () { bind(); });
    } else {
        bind();
    }
})(typeof window !== "undefined" ? window : globalThis);
