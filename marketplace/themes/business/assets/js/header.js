(function () {
    'use strict';
    var header = document.querySelector('[data-business-home-header]');
    var main = document.querySelector('main');
    if (!header || !main) return;

    function updateHeader() {
        var toolbar = document.querySelector('#ik-adminbar, #ik-draft-previewbar');
        header.style.top = toolbar ? Math.ceil(toolbar.getBoundingClientRect().height) + 'px' : '';
        var banner = main.querySelector('.banner-swiper');
        var rect = banner ? banner.getBoundingClientRect() : null;
        // A homepage flag alone does not guarantee a visible hero behind white navigation.
        var overlay = !!rect && rect.height > 0 && rect.width > 0
            && Math.abs(rect.top - main.getBoundingClientRect().top) <= 32;
        header.classList.toggle('nav-transparent', overlay);
        header.classList.toggle('nav-solid', !overlay);
        header.classList.toggle('shadow-lg', !overlay);
    }

    updateHeader();
    window.addEventListener('resize', updateHeader);
    window.addEventListener('load', updateHeader);
}());
