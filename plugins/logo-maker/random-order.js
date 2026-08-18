(function (root, factory) {
    'use strict';
    var api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.LogoMakerRandomOrder = api;
})(typeof window !== 'undefined' ? window : globalThis, function () {
    'use strict';

    function normalize(available, saved) {
        var valid = new Set((available || []).map(String));
        var seen = new Set();
        var result = [];
        (Array.isArray(saved) ? saved : []).forEach(function (id) {
            id = String(id);
            if (valid.has(id) && !seen.has(id)) {
                seen.add(id);
                result.push(id);
            }
        });
        valid.forEach(function (id) {
            if (!seen.has(id)) result.push(id);
        });
        return result;
    }

    function move(order, id, targetIndex) {
        var result = (order || []).map(String);
        id = String(id);
        var currentIndex = result.indexOf(id);
        if (currentIndex < 0) return result;
        result.splice(currentIndex, 1);
        targetIndex = Math.max(0, Math.min(result.length, Number(targetIndex) || 0));
        result.splice(targetIndex, 0, id);
        return result;
    }

    function key(search) {
        var value = String(search || '');
        var hash = 2166136261;
        for (var i = 0; i < value.length; i++) {
            hash ^= value.charCodeAt(i);
            hash = Math.imul(hash, 16777619);
        }
        return 'yikaicms.logo-maker.candidate-order.' + (hash >>> 0).toString(36);
    }

    return {normalize: normalize, move: move, key: key};
});
