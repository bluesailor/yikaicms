/* Same-origin, read-only probe. A 200 homepage or cached 404 is not a success. */
(function () {
    // Paths are site-relative; YK_BASE (set by BasePath in a subdirectory install) is
    // the mount prefix the server stripped, so the answer echoes the unprefixed path.
    async function probe(path, nonce) {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 5000);
        try {
            const response = await fetch((window.YK_BASE || '') + path + '?__yk_route_probe=' + nonce, {
                cache: 'no-store', redirect: 'error', credentials: 'same-origin', signal: controller.signal
            });
            if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) return false;
            const data = await response.json();
            return data.probe === 'yikai-rewrite-v1' && data.nonce === nonce && data.path === path;
        } catch (_) { return false; }
        finally { clearTimeout(timer); }
    }

    async function all(paths) {
        try {
            const bytes = new Uint8Array(16);
            crypto.getRandomValues(bytes);
            const nonce = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
            return (await Promise.all(paths.map(path => probe(path, nonce)))).every(Boolean);
        } catch (_) { return false; }
    }

    window.yikaiCheckRewrite = () => all(['/contact.html', '/en/contact.html']);
    // The server's default document reaches index.php (not a panel placeholder page or a 404).
    window.yikaiCheckHome = () => all(['/']);
})();
