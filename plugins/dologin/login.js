'use strict';
(() => {
    const token = location.hash.slice(1);
    history.replaceState(null, '', location.pathname + location.search);
    if (/^[a-f0-9]{64}$/.test(token)) {
        document.getElementById('dl-token').value = token;
        document.getElementById('dl-confirm').disabled = false;
        document.getElementById('dl-missing').hidden = true;
    }
    addEventListener('pageshow', event => {
        if (event.persisted) {
            document.getElementById('dl-token').value = '';
            document.getElementById('dl-confirm').disabled = true;
            document.getElementById('dl-missing').hidden = false;
        }
    });
})();
