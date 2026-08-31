(function () {
    'use strict';
    if (window.businessHomeSurfaces) {
        window.businessHomeSurfaces();
        return;
    }
    var pending = false;
    function update() {
        pending = false;
        var next = 'light';
        var device = window.innerWidth < 768 ? 'm' : (window.innerWidth < 1024 ? 't' : 'd');
        document.querySelectorAll('[data-business-surface]').forEach(function (section) {
            if (!section.getClientRects().length) return;
            // The editor keeps device-hidden blocks as ghosts; do not count those in the rhythm.
            for (var parent = section; parent; parent = parent.parentElement) {
                if ((parent.getAttribute('data-yk-hide-on') || '').split(',').indexOf(device) !== -1) return;
            }
            var mode = section.getAttribute('data-business-surface');
            if (mode === 'custom') return;
            var tone = mode === 'auto' ? next : mode;
            if (section.getAttribute('data-business-tone') !== tone) {
                section.setAttribute('data-business-tone', tone);
            }
            next = tone === 'light' ? 'dark' : 'light';
        });
    }
    function schedule() {
        if (pending) return;
        pending = true;
        window.requestAnimationFrame(update);
    }
    window.businessHomeSurfaces = schedule;
    function start() {
        update();
        new MutationObserver(schedule).observe(document.body, {
            subtree: true, childList: true, attributes: true,
            attributeFilter: ['class', 'style', 'hidden', 'data-business-surface', 'data-yk-hide-on']
        });
        window.addEventListener('resize', schedule);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
}());
