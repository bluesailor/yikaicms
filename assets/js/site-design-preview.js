(function () {
    'use strict';
    const snapshots = new Map();
    async function snapshot(path) {
        const url = new URL(path, location.origin);
        if (url.origin !== location.origin || /^\/admin(?:\/|$)/.test(url.pathname)) throw new Error('Invalid preview URL');
        if (!snapshots.has(url.href)) {
            const pending = (async () => {
                const response = await fetch(url.href, { credentials: 'omit', redirect: 'error', signal: AbortSignal.timeout(12000) });
                if (!response.ok || !response.headers.get('content-type')?.includes('text/html')) throw new Error('Preview unavailable');
                const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
                // Keep the published DOM and CSS, but never run front-end actions in the admin preview.
                doc.querySelectorAll('script,iframe,object,embed,base,meta[http-equiv],link[rel="preload"],link[rel="modulepreload"]').forEach(el => el.remove());
                doc.querySelectorAll('*').forEach(el => {
                    for (const attribute of [...el.attributes]) {
                        if (/^on/i.test(attribute.name) || attribute.name === 'autofocus' || attribute.name === 'autoplay') el.removeAttribute(attribute.name);
                    }
                });
                const base = doc.createElement('base');
                base.href = url.href;
                doc.head.prepend(base);
                const style = doc.createElement('style');
                style.textContent = 'html{scroll-behavior:auto!important;overflow:hidden!important}body{padding-bottom:1200px!important}*,*::before,*::after{animation:none!important;transition:none!important}';
                doc.head.append(style);
                return '<!doctype html>' + doc.documentElement.outerHTML;
            })();
            snapshots.set(url.href, pending);
            pending.catch(() => snapshots.delete(url.href));
        }
        return snapshots.get(url.href);
    }
    document.querySelectorAll('[data-site-area-preview]').forEach(panel => {
        let started = false;
        panel.addEventListener('toggle', async () => {
            if (!panel.open || started) return;
            started = true;
            const surface = panel.querySelector('[data-preview-surface]');
            const status = panel.querySelector('[data-preview-status]');
            status.textContent = panel.dataset.loading;
            surface.setAttribute('aria-busy', 'true');
            let frame;
            try {
                const html = await snapshot(panel.dataset.url);
                frame = document.createElement('iframe');
                frame.setAttribute('sandbox', 'allow-same-origin');
                frame.setAttribute('aria-hidden', 'true');
                frame.tabIndex = -1;
                frame.title = panel.querySelector('summary').textContent;
                const loaded = new Promise((resolve, reject) => {
                    const timeout = setTimeout(() => reject(new Error('Preview timed out')), 15000);
                    frame.addEventListener('load', () => { clearTimeout(timeout); resolve(); }, { once: true });
                });
                frame.srcdoc = html;
                surface.append(frame);
                await loaded;
                const doc = frame.contentDocument;
                const footer = panel.dataset.siteAreaPreview === 'footer';
                const target = doc.querySelector(footer ? '.yk-blox-footer, footer' : '.yk-blox-header, #siteHeader, body > header');
                if (!target || !target.getBoundingClientRect().height) throw new Error('Area not rendered');
                if (footer) doc.querySelectorAll('.yk-blox-header, #siteHeader, body > header').forEach(el => { el.style.display = 'none'; });
                const fit = () => {
                    if (!panel.open) return;
                    const scale = surface.clientWidth / 1280;
                    const height = Math.min(1000, Math.max(1, target.getBoundingClientRect().height));
                    frame.style.height = height + 'px';
                    frame.style.transform = 'scale(' + scale + ')';
                    surface.style.height = Math.max(64, height * scale) + 'px';
                    frame.contentWindow.scrollTo(0, target.getBoundingClientRect().top + frame.contentWindow.scrollY);
                };
                new ResizeObserver(fit).observe(surface);
                fit();
                frame.style.visibility = 'visible';
                status.textContent = '';
                panel.dataset.previewReady = 'true';
            } catch (_) {
                frame?.remove();
                snapshots.delete(new URL(panel.dataset.url, location.origin).href);
                status.textContent = panel.dataset.error;
                started = false;
            } finally {
                surface.removeAttribute('aria-busy');
            }
        });
    });
})();
