(function (window, document) {
    'use strict';

    var bound = new WeakSet();
    var observer = null;

    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    function configFor(group) {
        var raw = group.getAttribute('data-blox-counter') || '';
        try {
            var config = JSON.parse(raw);
            return {
                enabled: config.enabled !== false,
                start: clamp(parseInt(config.start || 0, 10) || 0, 0, 1000000),
                duration: clamp(parseInt(config.duration || 0, 10) || 0, 0, 5000)
            };
        } catch (error) {
            return { enabled: false, start: 0, duration: 0 };
        }
    }

    function animateNumber(element, config) {
        var text = element.getAttribute('data-count') || element.textContent || '';
        var target = parseInt(text.replace(/[^0-9]/g, ''), 10);
        if (!Number.isFinite(target) || config.start === target) {
            element.textContent = text;
            return;
        }

        var suffix = text.replace(/[0-9]/g, '');
        var duration = config.duration > 0
            ? clamp(config.duration, 100, 5000)
            : Math.min(1500, Math.max(800, target * 2));
        var startedAt = performance.now();
        element.textContent = config.start + suffix;

        function tick(now) {
            var progress = Math.min((now - startedAt) / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3);
            var value = config.start + ((target - config.start) * eased);
            element.textContent = (target >= config.start ? Math.floor(value) : Math.ceil(value)) + suffix;
            if (progress < 1) {
                requestAnimationFrame(tick);
            } else {
                element.textContent = text;
            }
        }
        requestAnimationFrame(tick);
    }

    function activate(group) {
        var config = configFor(group);
        if (!config.enabled) return;
        group.querySelectorAll('.stat-number[data-count]').forEach(function (element) {
            animateNumber(element, config);
        });
    }

    function getObserver() {
        if (observer || !('IntersectionObserver' in window)) return observer;
        observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                activate(entry.target);
                observer.unobserve(entry.target);
            });
        }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
        return observer;
    }

    function init(root) {
        root = root && root.querySelectorAll ? root : document;
        var groups = [];
        if (root.matches && root.matches('[data-blox-counter]')) groups.push(root);
        root.querySelectorAll('[data-blox-counter]').forEach(function (group) {
            groups.push(group);
        });

        var reduceMotion = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        groups.forEach(function (group) {
            if (bound.has(group)) return;
            bound.add(group);
            var config = configFor(group);
            if (!config.enabled || reduceMotion) return;
            var currentObserver = getObserver();
            if (currentObserver) currentObserver.observe(group);
            else activate(group);
        });
    }

    window.BloxCounter = { init: init };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); }, { once: true });
    } else {
        init(document);
    }
    document.addEventListener('blox:content-updated', function (event) {
        init(event.detail && event.detail.root ? event.detail.root : document);
    });
})(window, document);
