'use strict';
(() => {
    const input = document.getElementById('dl-issued-link');
    const button = document.getElementById('dl-copy');
    if (!input || !button) return;
    input.value = new URL(input.value, location.origin).href;
    button.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(input.value);
            document.getElementById('dl-copy-status').textContent = button.dataset.copied;
        } catch (_) {
            input.focus();
            input.select();
            document.getElementById('dl-copy-status').textContent = button.dataset.fallback;
        }
    });
})();
