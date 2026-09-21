/* Same-origin, read-only probe. A 200 homepage or cached 404 is not a success. */
window.yikaiCheckRewrite = async function () {
    try {
        const bytes = new Uint8Array(16);
        crypto.getRandomValues(bytes);
        const nonce = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
        const results = await Promise.all(['/contact.html', '/en/contact.html'].map(async path => {
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 5000);
            try {
                const response = await fetch(path + '?__yk_route_probe=' + nonce, {
                    cache: 'no-store', redirect: 'error', credentials: 'same-origin', signal: controller.signal
                });
                if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) return false;
                const data = await response.json();
                return data.probe === 'yikai-rewrite-v1' && data.nonce === nonce && data.path === path;
            } catch (_) { return false; }
            finally { clearTimeout(timer); }
        }));
        return results.every(Boolean);
    } catch (_) { return false; }
};
