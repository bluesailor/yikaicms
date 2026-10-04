/**
 * 分享按钮（2.0.4）：把服务端生成的分享链接换成页面的规范网址与标题，「复制链接」写入剪贴板。
 * 不加载第三方脚本。
 */
(function (window, document) {
    'use strict';
    if (window.BloxShare) { window.BloxShare.init(document); return; }
    var bound = typeof WeakSet === 'function' ? new WeakSet() : null;
    var TEMPLATES = {
        facebook: 'https://www.facebook.com/sharer/sharer.php?u={url}',
        x: 'https://twitter.com/intent/tweet?url={url}&text={title}',
        linkedin: 'https://www.linkedin.com/sharing/share-offsite/?url={url}',
        whatsapp: 'https://wa.me/?text={title}%20{url}',
        email: 'mailto:?subject={title}&body={url}'
    };

    function pageUrl() {
        var canonical = document.querySelector('link[rel="canonical"]');
        return canonical && canonical.href ? canonical.href : window.location.href.split('#')[0];
    }

    function bind(root) {
        if (!bound || bound.has(root)) return;
        bound.add(root);
        var url = encodeURIComponent(pageUrl());
        var title = encodeURIComponent(document.title || '');
        root.querySelectorAll('[data-yk-share-network]').forEach(function (link) {
            var template = TEMPLATES[link.getAttribute('data-yk-share-network')];
            if (template) link.setAttribute('href', template.replace('{url}', url).replace('{title}', title));
        });
        root.querySelectorAll('[data-yk-share-copy]').forEach(function (button) {
            button.addEventListener('click', function () {
                var text = decodeURIComponent(url);
                var done = function () {
                    var original = button.getAttribute('aria-label') || '';
                    var copied = button.getAttribute('data-copied') || original;
                    button.setAttribute('aria-label', copied);
                    button.setAttribute('title', copied);
                    window.setTimeout(function () { button.setAttribute('aria-label', original); button.setAttribute('title', original); }, 2000);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, function () {});
            });
        });
    }

    function init(scope) {
        scope = scope && scope.querySelectorAll ? scope : document;
        if (scope.matches && scope.matches('[data-yk-share]')) bind(scope);
        scope.querySelectorAll('[data-yk-share]').forEach(bind);
    }
    window.BloxShare = { init: init };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(document); }, { once: true });
    else init(document);
    document.addEventListener('blox:content-updated', function (event) {
        init(event.detail && event.detail.root ? event.detail.root : document);
    });
})(window, document);
