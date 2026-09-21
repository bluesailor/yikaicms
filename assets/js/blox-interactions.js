/**
 * Blox 元素交互 runtime（v1.28）。
 *
 * 语义全部在此（服务端零逻辑）：解析 data-yk-interactions（服务端已归一的 JSON），
 * 触发 click/hover/page_load/enter_viewport/scroll，动作 show/hide/toggle/
 * add|remove|toggle_class/animate/open_popup/close_popup。
 * 本文件只在页面确实含交互元素时由 BloxAssetCollector 按需输出。
 * open/close_popup 通过 document 级自定义事件交给 blox-popup.js（解耦：弹窗未启用时静默无事）。
 */
(function () {
    'use strict';

    var hosts = document.querySelectorAll('[data-yk-interactions]');
    if (!hosts.length) return;

    var ANIMATIONS = ['fade', 'fade-up', 'fade-down', 'fade-left', 'fade-right', 'zoom-in'];

    function resolveTargets(host, item) {
        if (item.action === 'open_popup' || item.action === 'close_popup') return [];
        var target = item.target || 'self';
        if (target === 'parent') return host.parentElement ? [host.parentElement] : [];
        if (target === 'selector' && item.selector) {
            try {
                return Array.prototype.slice.call(document.querySelectorAll(item.selector));
            } catch (error) {
                return [];
            }
        }
        return [host];
    }

    function apply(host, item) {
        if (item.action === 'open_popup' || item.action === 'close_popup') {
            document.dispatchEvent(new CustomEvent(item.action === 'open_popup' ? 'yk:popup-open' : 'yk:popup-close', {
                detail: { source: host }
            }));
            return;
        }
        resolveTargets(host, item).forEach(function (target) {
            switch (item.action) {
                case 'show': target.classList.remove('yk-i-hidden'); target.style.removeProperty('display'); break;
                case 'hide': target.classList.add('yk-i-hidden'); target.style.display = 'none'; break;
                case 'toggle':
                    if (target.classList.contains('yk-i-hidden')) {
                        target.classList.remove('yk-i-hidden'); target.style.removeProperty('display');
                    } else {
                        target.classList.add('yk-i-hidden'); target.style.display = 'none';
                    }
                    break;
                case 'add_class': target.classList.add(item.value); break;
                case 'remove_class': target.classList.remove(item.value); break;
                case 'toggle_class': target.classList.toggle(item.value); break;
                case 'animate':
                    if (ANIMATIONS.indexOf(item.value) === -1) return;
                    if (window.YikaiMotion) window.YikaiMotion.replay(target, item.value);
                    break;
            }
        });
    }

    function bind(host, item) {
        var fired = false;
        var run = function () {
            if (item.run_once && fired) return;
            fired = true;
            apply(host, item);
        };
        switch (item.trigger) {
            case 'click':
                host.addEventListener('click', run);
                break;
            case 'hover':
                host.addEventListener('mouseenter', run);
                break;
            case 'page_load':
                run();
                break;
            case 'enter_viewport':
                if (!('IntersectionObserver' in window)) { run(); break; }
                var observer = new IntersectionObserver(function (entries) {
                    entries.forEach(function (entry) {
                        if (!entry.isIntersecting) return;
                        run();
                        if (item.run_once) observer.unobserve(entry.target);
                    });
                }, { threshold: 0.15 });
                observer.observe(host);
                break;
            case 'scroll':
                var depth = Math.min(100, Math.max(10, Number(item.scroll_depth || 50)));
                var onScroll = function () {
                    var doc = document.documentElement;
                    var scrollable = doc.scrollHeight - window.innerHeight;
                    var reached = scrollable <= 0
                        || ((window.scrollY || doc.scrollTop || 0) / scrollable) * 100 >= depth;
                    if (!reached) return;
                    if (item.run_once) window.removeEventListener('scroll', onScroll);
                    run();
                };
                window.addEventListener('scroll', onScroll, { passive: true });
                onScroll();
                break;
        }
    }

    hosts.forEach(function (host) {
        var items;
        try {
            items = JSON.parse(host.getAttribute('data-yk-interactions') || '[]');
        } catch (error) {
            return;
        }
        if (!Array.isArray(items)) return;
        items.forEach(function (item) {
            if (item && typeof item === 'object') bind(host, item);
        });
    });
})();
