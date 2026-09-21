/**
 * Blox 轮播运行时（客户评价轮播等）：原生横向滚动 + scroll-snap，本脚本只负责
 * 下方圆点导航、左右箭头与自动播放。无 JS 时内容仍可横向滑动查看。
 *
 * - 圆点按「页」生成：每页显示条数由 CSS 决定（手机 1 / 平板 ≤2 / 桌面 per-view），窗口变化时重算。
 * - 自动播放：悬停、键盘聚焦、页面不可见、减少动态偏好或处于编辑器画布时暂停。
 * - 编辑器局部刷新后通过 blox:content-updated 重新绑定新节点。
 */
(function (window, document) {
    'use strict';
    if (window.BloxCarousel) { window.BloxCarousel.init(document); return; }

    var bound = typeof WeakSet === 'function' ? new WeakSet() : null;
    var reduceMotion = window.YikaiMotion ? window.YikaiMotion.level() !== 'standard'
        : !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    function inEditorCanvas() {
        return !!document.querySelector('.yk-canvas-region');
    }

    function bind(root) {
        if (!bound || bound.has(root)) return;
        var track = root.querySelector('[data-yk-carousel-track]');
        if (!track) return;
        bound.add(root);

        var slides = Array.prototype.slice.call(track.children);
        var dotsBox = root.querySelector('[data-yk-carousel-dots]');
        var dotLabel = root.getAttribute('data-yk-carousel-dot-label') || '%d';
        var dots = [];
        var paused = false;
        var ticking = false;

        function perView() {
            var first = slides[0];
            if (!first || !track.clientWidth) return 1;
            var width = first.getBoundingClientRect().width;
            return width > 0 ? Math.max(1, Math.round((track.clientWidth + 1) / width)) : 1;
        }

        function pageCount() {
            return Math.max(1, Math.ceil(slides.length / perView()));
        }

        function pageOffset(page) {
            var slide = slides[Math.min(slides.length - 1, page * perView())];
            return slide ? slide.offsetLeft - slides[0].offsetLeft : 0;
        }

        function currentPage() {
            var pages = pageCount();
            if (track.scrollLeft + track.clientWidth >= track.scrollWidth - 2) return pages - 1;
            var best = 0;
            var distance = Infinity;
            for (var page = 0; page < pages; page += 1) {
                var gap = Math.abs(pageOffset(page) - track.scrollLeft);
                if (gap < distance) { distance = gap; best = page; }
            }
            return best;
        }

        function goTo(page) {
            var pages = pageCount();
            var target = ((page % pages) + pages) % pages;
            var quiet = window.YikaiMotion ? window.YikaiMotion.level() !== 'standard' : reduceMotion;
            track.scrollTo({ left: pageOffset(target), behavior: quiet ? 'auto' : 'smooth' });
        }

        function renderDots() {
            if (!dotsBox) return;
            var pages = pageCount();
            if (dots.length === pages) return;
            dotsBox.innerHTML = '';
            dots = [];
            dotsBox.hidden = pages <= 1;
            for (var page = 0; page < pages; page += 1) {
                var dot = document.createElement('button');
                dot.type = 'button';
                dot.className = 'yk-carousel-dot';
                dot.setAttribute('aria-label', dotLabel.replace('%d', String(page + 1)));
                dot.addEventListener('click', goTo.bind(null, page));
                dotsBox.appendChild(dot);
                dots.push(dot);
            }
        }

        function update() {
            ticking = false;
            var active = currentPage();
            dots.forEach(function (dot, index) {
                dot.setAttribute('aria-current', index === active ? 'true' : 'false');
            });
        }

        track.addEventListener('scroll', function () {
            if (ticking) return;
            ticking = true;
            window.requestAnimationFrame(update);
        }, { passive: true });

        var prev = root.querySelector('[data-yk-carousel-prev]');
        var next = root.querySelector('[data-yk-carousel-next]');
        if (prev) prev.addEventListener('click', function () { goTo(currentPage() - 1); });
        if (next) next.addEventListener('click', function () { goTo(currentPage() + 1); });

        root.addEventListener('mouseenter', function () { paused = true; });
        root.addEventListener('mouseleave', function () { paused = false; });
        root.addEventListener('focusin', function () { paused = true; });
        root.addEventListener('focusout', function () { paused = false; });

        var interval = parseInt(root.getAttribute('data-yk-carousel-autoplay') || '0', 10);
        if (interval >= 2000 && !reduceMotion && !inEditorCanvas()) {
            window.setInterval(function () {
                if (window.YikaiMotion && window.YikaiMotion.level() !== 'standard') return;
                if (paused || document.hidden || !root.isConnected || pageCount() <= 1) return;
                goTo(currentPage() + 1);
            }, interval);
        }

        var refresh = function () { renderDots(); update(); };
        if (typeof ResizeObserver === 'function') {
            new ResizeObserver(refresh).observe(track);
        } else {
            window.addEventListener('resize', refresh);
        }
        refresh();
    }

    function init(scope) {
        scope = scope && scope.querySelectorAll ? scope : document;
        if (scope.matches && scope.matches('[data-yk-carousel]')) bind(scope);
        scope.querySelectorAll('[data-yk-carousel]').forEach(bind);
    }

    window.BloxCarousel = { init: init };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(document); }, { once: true });
    else init(document);
    document.addEventListener('blox:content-updated', function (event) {
        init(event.detail && event.detail.root ? event.detail.root : document);
    });
})(window, document);
