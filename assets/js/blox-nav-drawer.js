/**
 * Blox 移动抽屉导航（nav-drawer 元素前台脚本，经 BloxAssetCollector 按需注入）。
 * 无依赖；重复初始化安全（data-yk-drawer-bound 去重）。
 */
(function () {
    'use strict';

    function bind(root) {
        if (root.getAttribute('data-yk-drawer-bound') === '1') return;
        root.setAttribute('data-yk-drawer-bound', '1');
        var open = root.querySelector('[data-yk-drawer-open]');
        var close = root.querySelector('[data-yk-drawer-close]');
        var panel = root.querySelector('[data-yk-drawer-panel]');
        var backdrop = root.querySelector('[data-yk-drawer-backdrop]');
        if (!open || !panel || !backdrop) return;
        var previousOverflow = '';

        function show() {
            previousOverflow = document.documentElement.style.overflow;
            panel.classList.remove('hidden');
            backdrop.classList.remove('hidden');
            panel.setAttribute('aria-hidden', 'false');
            open.setAttribute('aria-expanded', 'true');
            document.documentElement.style.overflow = 'hidden';
            window.requestAnimationFrame(function () {
                (close || panel.querySelector('a, button, input'))?.focus();
            });
        }
        function hide(restoreFocus) {
            if (panel.classList.contains('hidden')) return;
            panel.classList.add('hidden');
            backdrop.classList.add('hidden');
            panel.setAttribute('aria-hidden', 'true');
            open.setAttribute('aria-expanded', 'false');
            document.documentElement.style.overflow = previousOverflow;
            if (restoreFocus !== false) open.focus();
        }
        open.addEventListener('click', show);
        close?.addEventListener('click', function () { hide(true); });
        backdrop.addEventListener('click', function () { hide(true); });
        panel.addEventListener('click', function (e) {
            var a = e.target && e.target.closest ? e.target.closest('a') : null;
            if (a) hide(false); // 点击导航项后收起
        });
        document.addEventListener('keydown', function (e) {
            if (panel.classList.contains('hidden')) return;
            if (e.key === 'Escape') {
                hide(true);
                return;
            }
            if (e.key !== 'Tab') return;
            var focusable = Array.prototype.slice.call(panel.querySelectorAll('a[href], button:not([disabled]), input:not([disabled])'));
            if (focusable.length === 0) return;
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        });
        window.addEventListener('resize', function () {
            if (window.innerWidth >= 1280) hide(false);
        });
    }

    function init() {
        document.querySelectorAll('[data-yk-nav-drawer]').forEach(bind);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
