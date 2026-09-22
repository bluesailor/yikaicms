(function (window, document) {
    'use strict';
    function issue(width, height) {
        if (!width || !height) return 'error';
        if (width !== height) return 'shape';
        return width < 16 ? 'small' : '';
    }
    var generation = 0;
    var inputTimer = null;
    function refresh() {
        window.clearTimeout(inputTimer);
        var host = document.getElementById('faviconPreview');
        var input = document.getElementById('input_site_favicon');
        if (!host || !input) return;
        var current = ++generation;
        host.setAttribute('aria-busy', 'false');
        var url = input.value.trim();
        var warning = host.querySelector('[data-favicon-warning]');
        var dimensions = host.querySelector('[data-favicon-dimensions]');
        var samples = host.querySelector('[data-favicon-samples]');
        var images = host.querySelectorAll('[data-favicon-sample]');
        warning.hidden = true;
        warning.textContent = '';
        dimensions.textContent = '';
        samples.hidden = true;
        images.forEach(function (image) { image.removeAttribute('src'); });
        if (!url) return;
        function showWarning(kind) {
            warning.textContent = host.getAttribute('data-' + kind + '-message') || '';
            warning.hidden = !warning.textContent;
        }
        // 与图片字段一致接受站内路径和 HTTP 图片，不加载任意协议或数据 URL。
        if (!/^(?:\/(?!\/)|https?:\/\/)/i.test(url) || /[\u0000-\u0020\\]/.test(url)) {
            showWarning('error');
            return;
        }
        var probe = new window.Image();
        host.setAttribute('aria-busy', 'true');
        probe.onload = function () {
            if (current !== generation) return;
            host.setAttribute('aria-busy', 'false');
            var kind = issue(probe.naturalWidth, probe.naturalHeight);
            dimensions.textContent = (host.getAttribute('data-size-message') || '')
                .replace(':width', String(probe.naturalWidth)).replace(':height', String(probe.naturalHeight));
            if (kind) showWarning(kind);
            if (kind === 'error') return;
            images.forEach(function (image) { image.src = url; });
            samples.hidden = false;
        };
        probe.onerror = function () {
            if (current !== generation) return;
            host.setAttribute('aria-busy', 'false');
            showWarning('error');
        };
        probe.src = url;
    }
    window.YikaiFaviconPreview = { refresh: refresh, issue: issue };
    var input = document.getElementById('input_site_favicon');
    var host = document.getElementById('faviconPreview');
    if (input && host) {
        input.setAttribute('aria-describedby', 'faviconPreviewHelp');
        input.addEventListener('change', refresh);
        input.addEventListener('blur', refresh);
        input.addEventListener('input', function () {
            window.clearTimeout(inputTimer);
            inputTimer = window.setTimeout(refresh, 250);
        });
        if (host.getAttribute('data-initial-preview') === '1') refresh();
    }
})(window, document);
