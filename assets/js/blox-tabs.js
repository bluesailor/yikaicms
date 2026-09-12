(function (window, document) {
    'use strict';
    if (window.BloxTabs) { window.BloxTabs.init(document); return; }
    var bound = new WeakSet();

    function bind(root) {
        if (bound.has(root)) return;
        var nav = root.querySelector('.yk-tabs-nav');
        if (!nav) return;
        var tabs = Array.from(nav.children).filter(function (el) { return el.getAttribute('role') === 'tab'; });
        var panels = Array.from(root.children).filter(function (el) { return el.getAttribute('role') === 'tabpanel'; });
        if (!tabs.length || panels.length !== tabs.length) return;
        bound.add(root);

        function activate(index, focus) {
            tabs.forEach(function (tab, i) {
                tab.setAttribute('aria-selected', String(i === index));
                tab.tabIndex = i === index ? 0 : -1;
                panels[i].hidden = i !== index;
            });
            if (focus) tabs[index].focus({ preventScroll: true });
        }
        tabs.forEach(function (tab, index) {
            tab.addEventListener('click', function () { activate(index, false); });
            tab.addEventListener('keydown', function (event) {
                var next = index;
                var rtl = window.getComputedStyle(nav).direction === 'rtl';
                if (event.key === 'ArrowRight') next += rtl ? -1 : 1;
                else if (event.key === 'ArrowLeft') next += rtl ? 1 : -1;
                else if (event.key === 'Home') next = 0;
                else if (event.key === 'End') next = tabs.length - 1;
                else return;
                event.preventDefault();
                activate((next + tabs.length) % tabs.length, true);
            });
        });
        activate(Math.max(0, Math.min(tabs.length - 1, parseInt(root.dataset.activeTab, 10) || 0)), false);
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
