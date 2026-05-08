/**
 * Yikai CMS - 轻量级 scroll-to-view 动画（替代 AOS）
 *
 * 用法：在元素上加 data-aos="fade-up | fade-right | fade-left | zoom-in"
 *      可选 data-aos-delay="0..1000"（毫秒）
 * 入场后元素被加 .aos-animate class，配合 CSS 完成过渡。
 *
 * 兼容：所有现代浏览器（IE 11+ 不计；如需兼容老 IE，元素直接显示）
 * 大小：约 1KB（vs AOS 17KB JS + 4KB CSS）
 */
(function () {
    'use strict';

    var elements = document.querySelectorAll('[data-aos]');
    if (!elements.length) return;

    // 不支持 IntersectionObserver 时，直接显示全部
    if (!('IntersectionObserver' in window)) {
        for (var i = 0; i < elements.length; i++) {
            elements[i].classList.add('aos-animate');
        }
        return;
    }

    var observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (!entry.isIntersecting) return;
            var el = entry.target;
            var delay = parseInt(el.getAttribute('data-aos-delay') || '0', 10);
            if (delay > 0) {
                setTimeout(function () { el.classList.add('aos-animate'); }, delay);
            } else {
                el.classList.add('aos-animate');
            }
            observer.unobserve(el);
        });
    }, {
        threshold: 0.1,
        rootMargin: '0px 0px -80px 0px'
    });

    for (var j = 0; j < elements.length; j++) {
        observer.observe(elements[j]);
    }
})();
