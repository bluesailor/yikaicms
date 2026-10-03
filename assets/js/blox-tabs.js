(function (window, document) {
    'use strict';
    if (window.BloxTabs) { window.BloxTabs.init(document); return; }
    var bound = new WeakSet();
    var reduceMotion = window.YikaiMotion ? window.YikaiMotion.level() !== 'standard'
        : !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    function inEditorCanvas() {
        return !!document.querySelector('.yk-canvas-region');
    }

    /** 网址里的 #标识 对应第几个选项卡（找不到返回 -1）：先比主标识（英文标题），再比序号形式 */
    function hashIndex(tabs) {
        var hash = '';
        try { hash = decodeURIComponent((window.location.hash || '').slice(1)).toLowerCase(); } catch (e) { return -1; }
        if (!hash) return -1;
        for (var pass = 0; pass < 2; pass++) {
            var attr = pass === 0 ? 'data-tab-hash' : 'data-tab-hash-alt';
            for (var i = 0; i < tabs.length; i++) {
                if (tabs[i].getAttribute(attr) === hash) return i;
            }
        }
        return -1;
    }

    function bind(root) {
        if (bound.has(root)) return;
        var nav = root.querySelector('.yk-tabs-nav');
        if (!nav) return;
        var tabs = Array.from(nav.children).filter(function (el) { return el.getAttribute('role') === 'tab'; });
        var panels = Array.from(root.children).filter(function (el) { return el.getAttribute('role') === 'tabpanel'; });
        if (!tabs.length || panels.length !== tabs.length) return;
        bound.add(root);
        var deepLink = root.hasAttribute('data-tabs-deep-link');
        var toggle = root.querySelector('[data-tabs-autoplay-toggle]');
        var autoplay = root.hasAttribute('data-tabs-autoplay') && !reduceMotion && !inEditorCanvas();
        var current = 0;
        var hovering = false;
        var focused = false;

        function activate(index, focus) {
            current = index;
            tabs.forEach(function (tab, i) {
                tab.setAttribute('aria-selected', String(i === index));
                tab.tabIndex = i === index ? 0 : -1;
                panels[i].hidden = i !== index;
            });
            if (focus) tabs[index].focus({ preventScroll: true });
        }

        // 自动轮播：进度条的 CSS 动画就是计时器，播完切到下一个；暂停即暂停动画。访客主动切换后不再自动轮播。
        function stopAutoplay() {
            autoplay = false;
            root.removeAttribute('data-tabs-running');
            if (toggle) toggle.hidden = true;
        }
        function syncPaused() {
            var paused = hovering || focused || document.hidden || (toggle && toggle.getAttribute('aria-pressed') === 'true');
            root.toggleAttribute('data-tabs-paused', !!paused);
        }

        // 访客主动切换：同步网址（不产生历史记录、不跳动），并停止自动轮播
        function choose(index, focus) {
            activate(index, focus);
            stopAutoplay();
            if (deepLink && window.history && window.history.replaceState) {
                var hash = tabs[index].getAttribute('data-tab-hash');
                if (hash) window.history.replaceState(window.history.state, '', '#' + hash);
            }
        }

        tabs.forEach(function (tab, index) {
            tab.addEventListener('click', function () { choose(index, false); });
            tab.addEventListener('keydown', function (event) {
                var next = index;
                var rtl = window.getComputedStyle(nav).direction === 'rtl';
                if (event.key === 'ArrowRight') next += rtl ? -1 : 1;
                else if (event.key === 'ArrowLeft') next += rtl ? 1 : -1;
                else if (event.key === 'Home') next = 0;
                else if (event.key === 'End') next = tabs.length - 1;
                else return;
                event.preventDefault();
                choose((next + tabs.length) % tabs.length, true);
            });
            var bar = tab.querySelector('.yk-tabs-progress');
            if (bar) {
                bar.addEventListener('animationend', function () {
                    if (autoplay && tabs[current] === tab) activate((current + 1) % tabs.length, false);
                });
            }
        });

        if (autoplay) {
            root.setAttribute('data-tabs-running', '');
            root.addEventListener('mouseenter', function () { hovering = true; syncPaused(); });
            root.addEventListener('mouseleave', function () { hovering = false; syncPaused(); });
            root.addEventListener('focusin', function () { focused = true; syncPaused(); });
            root.addEventListener('focusout', function (event) {
                if (!root.contains(event.relatedTarget)) { focused = false; syncPaused(); }
            });
            document.addEventListener('visibilitychange', syncPaused);
            if (toggle) {
                toggle.addEventListener('click', function () {
                    var pause = toggle.getAttribute('aria-pressed') !== 'true';
                    toggle.setAttribute('aria-pressed', String(pause));
                    toggle.setAttribute('aria-label', toggle.getAttribute(pause ? 'data-label-play' : 'data-label-pause') || '');
                    var icon = toggle.querySelector('i');
                    if (icon) icon.className = pause ? 'ti ti-player-play' : 'ti ti-player-pause';
                    syncPaused();
                });
            }
        } else if (toggle) {
            toggle.hidden = true;
        }

        var initial = deepLink ? hashIndex(tabs) : -1;
        activate(initial >= 0 ? initial : Math.max(0, Math.min(tabs.length - 1, parseInt(root.dataset.activeTab, 10) || 0)), false);
        if (initial >= 0) {
            stopAutoplay();
            root.scrollIntoView({ block: 'start' });
        }
        if (deepLink) {
            window.addEventListener('hashchange', function () {
                var index = hashIndex(tabs);
                if (index < 0) return;
                activate(index, false);
                stopAutoplay();
                root.scrollIntoView({ block: 'start', behavior: reduceMotion ? 'auto' : 'smooth' });
            });
        }
    }

    function init(scope) {
        scope = scope && scope.querySelectorAll ? scope : document;
        if (scope.matches && scope.matches('[data-blox-tabs]')) bind(scope);
        scope.querySelectorAll('[data-blox-tabs]').forEach(bind);
    }
    window.BloxTabs = { init: init };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(document); }, { once: true });
    else init(document);
    document.addEventListener('blox:content-updated', function (event) {
        init(event.detail && event.detail.root ? event.detail.root : document);
    });
})(window, document);
