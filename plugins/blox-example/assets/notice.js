(function (window, document) {
    'use strict';
    function init(root) {
        root = root && root.querySelectorAll ? root : document;
        root.querySelectorAll('.blox-example-notice:not([data-blox-example-ready])').forEach(function (node) {
            node.setAttribute('data-blox-example-ready', '1');
        });
    }
    window.BloxExampleNotice = { init: init };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); }, { once: true });
    } else {
        init(document);
    }
})(window, document);
