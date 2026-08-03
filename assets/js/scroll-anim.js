/**
 * Yikai CMS - 统一滚动入场动画
 *
 * 同时支持旧主题的 data-animate/data-stagger 与构建器元素使用的 data-aos。
 * 不支持 IntersectionObserver 或用户减少动画时，直接显示内容。
 */
(function () {
    'use strict';

    var elements = document.querySelectorAll('[data-aos], [data-animate], [data-stagger]');
    if (!elements.length) return;

    function reveal(element) {
        if (element.hasAttribute('data-animate') || element.hasAttribute('data-stagger')) {
            element.classList.add('animated');
        }
        if (!element.hasAttribute('data-aos')) {
            return;
        }

        var delay = parseInt(element.getAttribute('data-aos-delay') || '0', 10);
        if (delay > 0) {
            window.setTimeout(function () {
                element.classList.add('aos-animate');
            }, delay);
        } else {
            element.classList.add('aos-animate');
        }
    }

    var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduceMotion || !('IntersectionObserver' in window)) {
        elements.forEach(reveal);
        return;
    }

    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) return;
            reveal(entry.target);
            observer.unobserve(entry.target);
        });
    }, {
        threshold: 0.15,
        rootMargin: '0px 0px -40px 0px'
    });

    elements.forEach(function (element) {
        observer.observe(element);
    });
})();
