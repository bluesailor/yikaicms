/**
 * Blox 吸顶头部：定位由 CSS position:sticky 承担（无 JS 也不塌），
 * 本脚本只负责滚动态视觉——离开顶部时加 .yk-stuck（阴影/底色），回到顶部移除。
 * 幂等绑定（data-yk-sticky-bound），与预览局部刷新共存。
 */
(function () {
    "use strict";
    function bind() {
        var header = document.querySelector(".yk-sticky-header");
        if (!header || header.getAttribute("data-yk-sticky-bound") === "1") return;
        header.setAttribute("data-yk-sticky-bound", "1");
        var update = function () {
            header.classList.toggle("yk-stuck", (window.scrollY || window.pageYOffset || 0) > 4);
        };
        window.addEventListener("scroll", update, { passive: true });
        update();
    }
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", bind);
    } else {
        bind();
    }
})();
