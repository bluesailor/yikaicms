(function (window) {
    'use strict';

    function prefersReducedMotion() {
        return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    }

    function saveDataEnabled() {
        var connection = window.navigator && window.navigator.connection;
        return !!(connection && connection.saveData);
    }

    function mobileViewport() {
        return !!(window.matchMedia && window.matchMedia('(max-width: 767px)').matches);
    }

    function allowsPlayback(video, options) {
        if (!video || typeof video.getAttribute !== 'function') return false;
        var config = options && typeof options === 'object' ? options : {};
        var reduceMotion = typeof config.reduceMotion === 'boolean'
            ? config.reduceMotion
            : prefersReducedMotion();
        if (reduceMotion || saveDataEnabled()) return false;
        return !(mobileViewport() && video.getAttribute('data-blox-mobile-video') !== 'video');
    }

    window.BloxVideoPolicy = {
        allowsPlayback: allowsPlayback,
        prefersReducedMotion: prefersReducedMotion,
        saveDataEnabled: saveDataEnabled,
        mobileViewport: mobileViewport
    };
})(window);
