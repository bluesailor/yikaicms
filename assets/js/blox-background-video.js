(function (window, document) {
    'use strict';

    var bindings = new WeakMap();
    var listenersBound = false;

    function videosFor(root) {
        root = root && root.querySelectorAll ? root : document;
        var videos = [];
        if (root.matches && root.matches('[data-blox-background-video]')) videos.push(root);
        root.querySelectorAll('[data-blox-background-video]').forEach(function (video) { videos.push(video); });
        return videos;
    }

    function preferenceAllows(video) {
        var reduceMotion = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduceMotion) return false;
        var connection = window.navigator && window.navigator.connection;
        if (connection && connection.saveData) return false;
        var mobile = window.matchMedia && window.matchMedia('(max-width: 767px)').matches;
        return !(mobile && video.getAttribute('data-blox-mobile-video') !== 'video');
    }

    function pause(video, unload) {
        if (typeof video.pause === 'function') video.pause();
        if (video.classList) video.classList.remove('blox-bg-video-ready');
        if (unload && video.getAttribute('src')) {
            video.removeAttribute('src');
            if (typeof video.load === 'function') video.load();
        }
    }

    function sync(video) {
        if (!preferenceAllows(video)) {
            pause(video, true);
            return;
        }
        if (document.hidden) {
            pause(video, false);
            return;
        }
        var source = video.getAttribute('data-blox-video-src') || '';
        if (!source) return;
        if (video.getAttribute('src') !== source) video.setAttribute('src', source);
        var playback;
        try { playback = video.play(); } catch (error) { playback = null; }
        if (playback && typeof playback.catch === 'function') {
            playback.catch(function () {
                if (video.classList) video.classList.remove('blox-bg-video-ready');
            });
        }
    }

    function bind(video) {
        if (bindings.has(video) || typeof video.addEventListener !== 'function') return;
        bindings.set(video, true);
        video.addEventListener('playing', function () {
            if (preferenceAllows(video) && !document.hidden && video.classList) {
                video.classList.add('blox-bg-video-ready');
            }
        });
        video.addEventListener('error', function () {
            if (video.classList) video.classList.remove('blox-bg-video-ready');
        });
    }

    function syncAll() {
        videosFor(document).forEach(function (video) { bind(video); sync(video); });
    }

    function bindListeners() {
        if (listenersBound) return;
        listenersBound = true;
        document.addEventListener('visibilitychange', syncAll);
        document.addEventListener('blox:content-updated', syncAll);
        if (typeof window.addEventListener === 'function') window.addEventListener('resize', syncAll);
        if (window.matchMedia) {
            var motion = window.matchMedia('(prefers-reduced-motion: reduce)');
            if (typeof motion.addEventListener === 'function') motion.addEventListener('change', syncAll);
            else if (typeof motion.addListener === 'function') motion.addListener(syncAll);
        }
        var connection = window.navigator && window.navigator.connection;
        if (connection && typeof connection.addEventListener === 'function') connection.addEventListener('change', syncAll);
    }

    function init(root) {
        videosFor(root).forEach(function (video) { bind(video); sync(video); });
        bindListeners();
    }

    window.BloxBackgroundVideo = { init: init };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(document); });
    else init(document);
})(window, document);
