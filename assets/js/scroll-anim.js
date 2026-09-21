/** Shared entrance/replay policy. Markup stays visible if scripting or animation fails. */
(function (window, document) {
    'use strict';
    if (window.YikaiMotion) { window.YikaiMotion.scan(document); return; }
    var seen = new WeakSet();
    var active = new Set();
    var media = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    var transforms = { fade: 'none', 'fade-up': 'translateY(40px)', 'fade-down': 'translateY(-40px)', 'fade-left': 'translateX(-40px)', 'fade-right': 'translateX(40px)', 'zoom-in': 'scale(0.9)' };

    function level() {
        if (media && media.matches) return 'none';
        var meta = document.querySelector('meta[name="yk-motion"]');
        var value = meta ? meta.getAttribute('content') : 'standard';
        return value === 'none' || value === 'light' ? value : 'standard';
    }
    function deviceAllowed(node) {
        var device = node.getAttribute('data-animate-device') || 'all';
        var width = window.innerWidth;
        return device === 'all' || (device === 'desktop' && width >= 1024)
            || (device === 'tablet' && width >= 768 && width < 1024) || (device === 'mobile' && width < 768);
    }
    function cancel(node) {
        (node._ykMotion || []).forEach(function (animation) { animation.cancel(); });
        node._ykMotion = [];
        active.delete(node);
        node.classList.remove('yk-animate-pending');
    }
    function replay(node, effect, options) {
        options = options || {};
        cancel(node);
        node.classList.add('animated');
        if (node.hasAttribute('data-aos')) node.classList.add('aos-animate');
        var mode = level();
        if (mode === 'none' || !deviceAllowed(node) || typeof node.animate !== 'function' || !Object.prototype.hasOwnProperty.call(transforms, effect)) return;
        var speed = node.getAttribute('data-animate-speed');
        var delay = node.getAttribute('data-animate-delay');
        var duration = mode === 'light' ? 180 : (speed === 'fast' ? 450 : (speed === 'slow' ? 1000 : 700));
        var wait = mode === 'light' ? 0 : (options.delay || ({ short: 150, medium: 300, long: 600 }[delay]) || Number(node.getAttribute('data-aos-delay')) || 0);
        wait = Math.min(600, Math.max(0, Number.isFinite(wait) ? wait : 0));
        var timing = { duration: duration, delay: wait, easing: 'ease', fill: 'backwards' };
        try {
            var fade = node.animate([{ opacity: 0 }, { opacity: 1 }], timing);
            node._ykMotion = [fade];
            // Separate additive transform keeps the existing hover transform/translate intact.
            if (mode === 'standard' && effect !== 'fade') {
                node._ykMotion.push(node.animate([{ transform: transforms[effect] }, { transform: 'none' }], Object.assign({}, timing, { composite: 'add' })));
            }
            active.add(node);
            fade.onfinish = function () { active.delete(node); node._ykMotion = []; };
        } catch (error) { cancel(node); }
    }
    function reveal(node, animate) {
        node.classList.add('animated');
        node.classList.remove('yk-animate-pending');
        if (node.hasAttribute('data-aos')) node.classList.add('aos-animate');
        if (!animate || level() === 'none' || !deviceAllowed(node)) return;
        if (node.hasAttribute('data-stagger')) {
            var children = Array.prototype.filter.call(node.children, function (child) {
                return !child.matches || !child.matches('.yk-gap-resizer, .yk-column-resizer, .yk-empty-hint');
            });
            children.forEach(function (child, index) { replay(child, 'fade-up', { delay: Math.min(index, 8) * 70 }); });
        } else {
            var effect = node.getAttribute('data-animate') || node.getAttribute('data-aos');
            if (!node.hasAttribute('data-animate')) effect = ({ 'fade-left': 'fade-right', 'fade-right': 'fade-left' })[effect] || effect;
            replay(node, effect);
        }
    }
    var observer = null;
    try {
        if ('IntersectionObserver' in window) observer = new window.IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                observer.unobserve(entry.target);
                reveal(entry.target, true);
            });
        }, { threshold: 0.1 });
    } catch (error) { observer = null; }
    function scan(root) {
        var selector = '[data-aos], [data-animate], [data-stagger]';
        var nodes = Array.prototype.slice.call(root.querySelectorAll(selector));
        if (root.matches && root.matches(selector)) nodes.unshift(root);
        var canvas = !!document.querySelector('.yk-canvas-region');
        nodes.forEach(function (node) {
            if (seen.has(node)) return;
            seen.add(node);
            // The editor always remains selectable; replay is a deliberate, separate action.
            if (canvas || level() === 'none' || !deviceAllowed(node) || !observer) { reveal(node, false); return; }
            if (node.getAttribute('data-animate-trigger') === 'load') reveal(node, true);
            else observer.observe(node);
        });
    }
    window.YikaiMotion = { level: level, replay: replay, replayGroup: function (node) { reveal(node, true); }, scan: scan };
    if (media && media.addEventListener) media.addEventListener('change', function () {
        if (level() !== 'none') return;
        Array.from(active).forEach(cancel);
        document.querySelectorAll('[data-animate], [data-aos], [data-stagger]').forEach(function (node) { reveal(node, false); });
    });
    scan(document);
    document.addEventListener('blox:content-updated', function (event) { scan(event.detail && event.detail.root ? event.detail.root : document); });
    document.addEventListener('blox:content-removing', function (event) {
        var root = event.detail && event.detail.root;
        if (!root) return;
        Array.from(active).forEach(function (node) { if (root === node || root.contains(node)) cancel(node); });
    });
})(window, document);
