(function (window, document) {
    'use strict';

    var states = new WeakMap();
    var videoBindings = new WeakMap();
    var viewportListenersBound = false;

    function numberAttribute(element, name, fallback, min, max) {
        var value = parseInt(element.getAttribute(name) || '', 10);
        if (!Number.isFinite(value)) value = fallback;
        return Math.min(max, Math.max(min, value));
    }

    function enabled(element, name, fallback) {
        var value = element.getAttribute(name);
        if (value === null) return fallback;
        return value !== '0' && value !== 'false';
    }

    function configFor(slider) {
        var effect = slider.getAttribute('data-blox-effect') === 'slide' ? 'slide' : 'fade';
        var autoplay = numberAttribute(slider, 'data-blox-autoplay', 5, 0, 30);
        return {
            effect: effect,
            autoplay: autoplay > 0 ? Math.max(2, autoplay) : 0,
            speed: numberAttribute(slider, 'data-blox-speed', 700, 200, 2000),
            navigation: enabled(slider, 'data-blox-navigation', true),
            pagination: enabled(slider, 'data-blox-pagination', true),
            pauseHover: enabled(slider, 'data-blox-pause-hover', true)
        };
    }

    function signatureFor(slider) {
        return [
            'data-blox-effect',
            'data-blox-autoplay',
            'data-blox-speed',
            'data-blox-navigation',
            'data-blox-pagination',
            'data-blox-pause-hover'
        ].map(function (name) { return slider.getAttribute(name) || ''; }).join('|');
    }

    function refreshViewportHeight(slider) {
        if (slider.getAttribute('data-blox-height-mode') !== 'screen') return;
        if (!slider.style || typeof slider.style.setProperty !== 'function') return;
        var rect = typeof slider.getBoundingClientRect === 'function'
            ? slider.getBoundingClientRect()
            : { top: 0 };
        var scrollTop = window.pageYOffset
            || (document.documentElement && document.documentElement.scrollTop)
            || 0;
        slider.style.setProperty('--blox-banner-offset', Math.max(0, Math.round(rect.top + scrollTop)) + 'px');
    }

    function refreshViewportHeights(root) {
        root = root && root.querySelectorAll ? root : document;
        if (root.matches && root.matches('[data-blox-banner]')) refreshViewportHeight(root);
        root.querySelectorAll('[data-blox-banner]').forEach(refreshViewportHeight);
    }

    function bindViewportListeners() {
        if (viewportListenersBound || typeof window.addEventListener !== 'function') return;
        viewportListenersBound = true;
        window.addEventListener('load', function () { refreshViewportHeights(document); });
        window.addEventListener('resize', function () { refreshViewportHeights(document); });
    }

    function videosFor(slider) {
        return slider && typeof slider.querySelectorAll === 'function'
            ? Array.from(slider.querySelectorAll('[data-blox-banner-video]'))
            : [];
    }

    function pauseVideo(video, reset) {
        if (!video) return;
        if (typeof video.pause === 'function') video.pause();
        if (reset) {
            try { video.currentTime = 0; } catch (error) { /* Some streams are not seekable yet. */ }
            if (video.classList) video.classList.remove('blox-banner-video-ready');
        }
    }

    function controlAutoplay(instance, action) {
        var autoplay = instance && instance.autoplay;
        if (autoplay && typeof autoplay[action] === 'function') autoplay[action]();
    }

    function autoplayEnabled(config, reduceMotion) {
        return !reduceMotion && config.autoplay > 0;
    }

    function videoCanPlay(video, reduceMotion) {
        if (reduceMotion) return false;
        var connection = window.navigator && window.navigator.connection;
        if (connection && connection.saveData) return false;
        var mobilePoster = video.getAttribute('data-blox-mobile-video') !== 'video';
        var mobile = window.matchMedia && window.matchMedia('(max-width: 767px)').matches;
        return !(mobile && mobilePoster);
    }

    function activeVideo(slider, instance) {
        var slides = slider.querySelectorAll('.swiper-wrapper > .swiper-slide');
        var index = Math.max(0, Number(instance && instance.activeIndex) || 0);
        var slide = slides[index];
        return slide && typeof slide.querySelector === 'function'
            ? slide.querySelector('[data-blox-banner-video]')
            : null;
    }

    function bindVideo(slider, video) {
        if (!video || videoBindings.get(video) === slider || typeof video.addEventListener !== 'function') return;
        videoBindings.set(video, slider);
        video.addEventListener('playing', function () {
            if (video.classList) video.classList.add('blox-banner-video-ready');
        });
        video.addEventListener('error', function () {
            if (video.classList) video.classList.remove('blox-banner-video-ready');
            var state = states.get(slider);
            if (state && autoplayEnabled(state.config, state.reduceMotion) && activeVideo(slider, state.instance) === video) {
                controlAutoplay(state.instance, 'start');
            }
        });
        video.addEventListener('ended', function () {
            if (video.classList) video.classList.remove('blox-banner-video-ready');
            var state = states.get(slider);
            if (!state || activeVideo(slider, state.instance) !== video) return;
            var count = slider.querySelectorAll('.swiper-wrapper > .swiper-slide').length;
            if (count > 1 && autoplayEnabled(state.config, state.reduceMotion) && typeof state.instance.slideNext === 'function') {
                state.instance.slideNext();
            } else if (count === 1) {
                try { video.currentTime = 0; } catch (error) { /* Ignore non-seekable streams. */ }
                var replay = video.play();
                if (replay && typeof replay.catch === 'function') replay.catch(function () {});
            }
        });
    }

    function syncVideos(slider, instance, config, reduceMotion, preserveActivePosition) {
        var videos = videosFor(slider);
        var currentVideo = activeVideo(slider, instance);
        videos.forEach(function (candidate) {
            bindVideo(slider, candidate);
            pauseVideo(candidate, !preserveActivePosition || candidate !== currentVideo);
        });
        if (document.hidden) {
            if (autoplayEnabled(config, reduceMotion)) controlAutoplay(instance, 'stop');
            return;
        }
        if (!currentVideo || !videoCanPlay(currentVideo, reduceMotion)) {
            if (autoplayEnabled(config, reduceMotion)) controlAutoplay(instance, 'start');
            return;
        }

        if (autoplayEnabled(config, reduceMotion)) controlAutoplay(instance, 'stop');
        var playback;
        try { playback = currentVideo.play(); } catch (error) { playback = null; }
        if (playback && typeof playback.catch === 'function') {
            playback.catch(function () {
                if (currentVideo.classList) currentVideo.classList.remove('blox-banner-video-ready');
                if (autoplayEnabled(config, reduceMotion) && activeVideo(slider, instance) === currentVideo) {
                    controlAutoplay(instance, 'start');
                }
            });
        }
    }

    function initSlider(slider) {
        var signature = signatureFor(slider);
        var previousState = states.get(slider);
        if (previousState && previousState.signature === signature) {
            if (previousState.instance && typeof previousState.instance.update === 'function') previousState.instance.update();
            syncVideos(slider, previousState.instance, previousState.config, previousState.reduceMotion);
            return;
        }
        if (previousState && previousState.instance && typeof previousState.instance.destroy === 'function') {
            videosFor(slider).forEach(function (video) { pauseVideo(video, true); });
            previousState.instance.destroy(true, true);
        }

        var wrapper = slider.querySelector('.swiper-wrapper');
        if (!wrapper) {
            states.set(slider, {
                signature: signature,
                instance: null,
                config: configFor(slider),
                reduceMotion: !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)
            });
            slider.classList.add('blox-banner-static-active');
            return;
        }
        if (typeof window.Swiper !== 'function') return;

        var reduceMotion = window.matchMedia
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var config = configFor(slider);
        var pagination = slider.querySelector('.swiper-pagination');
        var previous = slider.querySelector('.swiper-button-prev');
        var next = slider.querySelector('.swiper-button-next');
        var options = {
            loop: false,
            rewind: true,
            watchOverflow: true,
            effect: reduceMotion ? 'slide' : config.effect,
            speed: reduceMotion ? 0 : config.speed,
            autoplay: !reduceMotion && config.autoplay > 0 ? {
                delay: config.autoplay * 1000,
                disableOnInteraction: false,
                pauseOnMouseEnter: config.pauseHover
            } : false,
            pagination: config.pagination && pagination ? { el: pagination, clickable: true } : false,
            navigation: config.navigation && previous && next ? { prevEl: previous, nextEl: next } : false,
            on: {
                slideChangeTransitionStart: function () {
                    videosFor(slider).forEach(function (video) { pauseVideo(video, true); });
                },
                slideChangeTransitionEnd: function (swiper) {
                    syncVideos(slider, swiper, config, reduceMotion);
                }
            }
        };
        if (options.effect === 'fade') options.fadeEffect = { crossFade: true };

        slider.bloxBanner = new window.Swiper(slider, options);
        states.set(slider, {
            signature: signature,
            instance: slider.bloxBanner,
            config: config,
            reduceMotion: reduceMotion
        });
        syncVideos(slider, slider.bloxBanner, config, reduceMotion);
    }

    function init(root) {
        root = root && root.querySelectorAll ? root : document;
        var sliders = [];
        if (root.matches && root.matches('[data-blox-banner]')) sliders.push(root);
        root.querySelectorAll('[data-blox-banner]').forEach(function (slider) {
            sliders.push(slider);
        });
        sliders.forEach(initSlider);
        sliders.forEach(refreshViewportHeight);
        bindViewportListeners();
    }

    function handleVisibilityChange() {
        document.querySelectorAll('[data-blox-banner]').forEach(function (slider) {
            var state = states.get(slider);
            if (!state || !state.instance) return;
            if (document.hidden) {
                videosFor(slider).forEach(function (video) { pauseVideo(video, false); });
                if (autoplayEnabled(state.config, state.reduceMotion)) controlAutoplay(state.instance, 'stop');
                return;
            }
            syncVideos(slider, state.instance, state.config, state.reduceMotion, true);
        });
    }

    function show(slider, index) {
        if (!slider || typeof slider.querySelectorAll !== 'function') return false;
        initSlider(slider);
        var state = states.get(slider);
        var instance = state && state.instance ? state.instance : slider.bloxBanner;
        if (!instance || instance.destroyed || typeof instance.slideTo !== 'function') return false;

        if (typeof instance.update === 'function') instance.update();
        var count = slider.querySelectorAll('.swiper-wrapper > .swiper-slide').length;
        if (count < 1) return false;
        var target = Math.min(count - 1, Math.max(0, parseInt(index, 10) || 0));
        instance.slideTo(target, 0);
        return true;
    }

    window.BloxBanner = {
        init: init,
        show: show,
        configFor: configFor,
        refreshViewportHeights: refreshViewportHeights
    };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); }, { once: true });
    } else {
        init(document);
    }
    document.addEventListener('blox:content-updated', function (event) {
        init(event.detail && event.detail.root ? event.detail.root : document);
    });
    document.addEventListener('visibilitychange', handleVisibilityChange);
})(window, document);
