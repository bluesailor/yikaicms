(function () {
    'use strict';

    var STORAGE_KEY = 'yikai:blox:recent-colors:v1';
    var PALETTE_GROUPS = [
        {
            id: 'neutral',
            colors: ['#ffffff', '#f8fafc', '#e2e8f0', '#cbd5e1', '#94a3b8', '#475569', '#0f172a', '#000000']
        },
        {
            id: 'brand',
            colors: ['#2563eb', '#0891b2', '#0d9488', '#16a34a', '#ca8a04', '#ea580c', '#dc2626', '#7c3aed']
        },
        {
            id: 'deep',
            colors: ['#1e3a8a', '#164e63', '#134e4a', '#14532d', '#713f12', '#7c2d12', '#7f1d1d', '#4c1d95']
        }
    ];

    function normalizeHex(value, fallback) {
        var input = String(value || '').trim();
        if (/^#[0-9a-f]{3}$/i.test(input)) {
            input = '#' + input.slice(1).split('').map(function (character) {
                return character + character;
            }).join('');
        }
        return /^#[0-9a-f]{6}$/i.test(input) ? input.toLowerCase() : (fallback || '');
    }

    function loadRecent() {
        try {
            var parsed = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '[]');
            if (!Array.isArray(parsed)) return [];
            return parsed.map(function (value) { return normalizeHex(value, ''); }).filter(Boolean).slice(0, 8);
        } catch (error) {
            return [];
        }
    }

    function remember(value) {
        var color = normalizeHex(value, '');
        if (!color) return loadRecent();
        var next = [color].concat(loadRecent().filter(function (item) { return item !== color; })).slice(0, 8);
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
        } catch (error) {
            // Private browsing and storage policies must not block color selection.
        }
        return next;
    }

    window.YikaiBloxColorPicker = Object.freeze({
        paletteGroups: PALETTE_GROUPS,
        normalizeHex: normalizeHex,
        loadRecent: loadRecent,
        remember: remember
    });
}());
