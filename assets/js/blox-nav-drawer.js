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
        var panel = root.querySelector('[data-yk-drawer-panel]');
        var backdrop = root.querySelector('[data-yk-drawer-backdrop]');
        if (!open || !panel || !backdrop) return;

        function show() {
            panel.classList.remove('hidden');
            backdrop.classList.remove('hidden');
            document.documentElement.style.overflow = 'hidden';
        }
        function hide() {
            panel.classList.add('hidden');
            backdrop.classList.add('hidden');
            document.documentElement.style.overflow = '';
        }
        open.addEventListener('click', show);
        backdrop.addEventListener('click', hide);
        panel.addEventListener('click', function (e) {
            var a = e.target && e.target.closest ? e.target.closest('a') : null;
            if (a) hide(); // 点击导航项后收起
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') hide();
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
