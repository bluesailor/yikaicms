/**
 * R7A 单页圆点导航前台运行时。
 *
 * - 真实锚点链接：本脚本只做高亮与偏移测量，不 preventDefault、不改 history，
 *   JS 不可用时链接照常跳转，浏览器后退行为不被劫持。
 * - IntersectionObserver 高亮当前区块；不支持时保持无高亮（链接仍可用）。
 * - 吸顶头部与管理条高度写入 --yk-dotnav-offset（scroll-margin-top），不写死 80px。
 * - 平滑滚动交给 CSS scroll-behavior，prefers-reduced-motion 时立即定位。
 */
(function () {
    "use strict";

    function init() {
        var nav = document.querySelector("[data-yk-dotnav]");
        if (!nav) return;
        var dots = Array.prototype.slice.call(nav.querySelectorAll("[data-yk-dotnav-target]"));
        var targets = dots.map(function (dot) {
            return document.getElementById(dot.getAttribute("data-yk-dotnav-target"));
        });
        if (!dots.length) return;

        var reduced = typeof window.matchMedia === "function"
            && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
        if (!reduced) document.documentElement.classList.add("yk-dotnav-smooth");

        function fixedTopBottom(element, offset) {
            if (!element || typeof window.getComputedStyle !== "function") return 0;
            var style = window.getComputedStyle(element);
            if (style.position !== "fixed" && style.position !== "sticky") return 0;
            var rect = element.getBoundingClientRect();
            return rect.top <= offset + 1 ? Math.max(0, rect.bottom) : 0;
        }

        function measureOffset() {
            var offset = 0;
            ["#ik-adminbar", "#ik-draft-previewbar"].forEach(function (selector) {
                offset = Math.max(offset, fixedTopBottom(document.querySelector(selector), offset));
            });
            offset = Math.max(offset, fixedTopBottom(
                document.querySelector(".yk-blox-header") || document.getElementById("siteHeader"), offset
            ));
            document.documentElement.style.setProperty("--yk-dotnav-offset", Math.round(offset + 8) + "px");
        }

        var activeIndex = -1;
        function setActive(index) {
            if (index === activeIndex) return;
            activeIndex = index;
            dots.forEach(function (dot, i) {
                dot.classList.toggle("is-active", i === index);
                if (i === index) dot.setAttribute("aria-current", "true");
                else dot.removeAttribute("aria-current");
            });
        }

        if ("IntersectionObserver" in window) {
            var ratios = new Map();
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    ratios.set(entry.target, entry.isIntersecting ? entry.intersectionRatio : 0);
                });
                var best = -1;
                var bestRatio = 0;
                targets.forEach(function (target, i) {
                    var ratio = target ? (ratios.get(target) || 0) : 0;
                    if (ratio > bestRatio) { bestRatio = ratio; best = i; }
                });
                if (best >= 0) setActive(best);
            }, { rootMargin: "-15% 0px -50% 0px", threshold: [0, 0.1, 0.3, 0.6, 0.9] });
            targets.forEach(function (target) { if (target) observer.observe(target); });
        }

        // 直接带 hash 进入：先给对应圆点初始高亮（观察器随后接管）
        var hash = String(window.location.hash || "").slice(1);
        if (hash !== "") {
            dots.forEach(function (dot, i) {
                if (dot.getAttribute("data-yk-dotnav-target") === hash) setActive(i);
            });
        }

        measureOffset();
        window.addEventListener("resize", measureOffset);
        window.addEventListener("load", measureOffset);
        window.addEventListener("scroll", measureOffset, { passive: true });
    }

    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init);
    else init();
})();
