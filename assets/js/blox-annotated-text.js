/* 进入视口时描画固定 SVG 路径；无脚本、低动效与画布场景保留静态标注。 */
(function (window, document) {
    'use strict';
    if (window.YikaiAnnotatedText) return;
    var selector = '[data-yk-annotated][data-annotation-animate="1"]';
    var seen = new WeakSet();
    var pending = new Set();
    var reduced = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    var observer = null;

    function motionLevel() {
        if (reduced && reduced.matches) return 'none';
        if (window.YikaiMotion && typeof window.YikaiMotion.level === 'function') return window.YikaiMotion.level();
        var meta = document.querySelector('meta[name="yk-motion"]');
        return meta ? meta.getAttribute('content') : 'standard';
    }

    function reveal(node, draw) {
        if (observer) observer.unobserve(node);
        pending.delete(node);
        var stroke = node.querySelector('.yk-annotated-mark__stroke');
        if (!stroke) return;
        if (!draw || motionLevel() === 'none') {
            node.classList.remove('is-annotation-pending', 'is-annotation-drawing');
            stroke.style.strokeDasharray = '';
            stroke.style.strokeDashoffset = '';
            return;
        }
        node.classList.remove('is-annotation-pending');
        node.classList.add('is-annotation-drawing');
        if (motionLevel() === 'light') node.style.setProperty('--yk-annotated-duration', '.18s');
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(function () { stroke.style.strokeDashoffset = '0'; });
        });
    }

    try {
        if ('IntersectionObserver' in window) observer = new window.IntersectionObserver(function (entries) {
            entries.forEach(function (entry) { if (entry.isIntersecting) reveal(entry.target, true); });
        }, { threshold: .1 });
    } catch (error) { observer = null; }

    function scan(root) {
        var nodes = Array.prototype.slice.call(root.querySelectorAll(selector));
        if (root.matches && root.matches(selector)) nodes.unshift(root);
        nodes.forEach(function (node) {
            if (seen.has(node)) return;
            seen.add(node);
            var stroke = node.querySelector('.yk-annotated-mark__stroke');
            if (!stroke || !observer || motionLevel() === 'none' || document.querySelector('.yk-canvas-region')) return;
            stroke.style.strokeDasharray = '100';
            stroke.style.strokeDashoffset = '100';
            node.classList.add('is-annotation-pending');
            pending.add(node);
            if (node.getAttribute('data-annotation-trigger') === 'load') reveal(node, true);
            else observer.observe(node);
        });
    }

    window.YikaiAnnotatedText = { scan: scan };
    scan(document);
    document.addEventListener('blox:content-updated', function (event) {
        scan(event.detail && event.detail.root ? event.detail.root : document);
    });
    if (reduced && reduced.addEventListener) reduced.addEventListener('change', function () {
        if (!reduced.matches) return;
        Array.from(pending).forEach(function (node) { reveal(node, false); });
    });
})(window, document);
