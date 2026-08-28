(function (global) {
    "use strict";

    var STYLE_KEY = /(^|_)(align|background|bg|border|bottom|color|display|font|gap|gutter|height|justify|layout|left|margin|max_width|min_height|opacity|order|padding|position|radius|right|shadow|size|spacing|span|style|top|transform|transition|width)($|_)/i;

    function documentOf(value) {
        var document = value;
        if (typeof value === "string") {
            try { document = JSON.parse(value); }
            catch (_) { document = {}; }
        }
        if (!document || typeof document !== "object" || Array.isArray(document)) {
            document = { sections: Array.isArray(document) ? document : [] };
        }
        return {
            settings: document.settings && typeof document.settings === "object" && !Array.isArray(document.settings)
                ? document.settings : {},
            sections: Array.isArray(document.sections) ? document.sections : [],
        };
    }

    function stable(value) {
        if (Array.isArray(value)) return value.map(stable);
        if (!value || typeof value !== "object") return value;
        var result = {};
        Object.keys(value).sort().forEach(function (key) {
            if (value[key] !== undefined) result[key] = stable(value[key]);
        });
        return result;
    }

    function same(left, right) {
        return JSON.stringify(stable(left)) === JSON.stringify(stable(right));
    }

    function contentValue(value, key) {
        if (key === "settings" || STYLE_KEY.test(key)) return undefined;
        if (Array.isArray(value)) {
            return value.map(function (item) { return contentValue(item, ""); });
        }
        if (!value || typeof value !== "object") return value;
        var result = {};
        Object.keys(value).forEach(function (childKey) {
            var child = contentValue(value[childKey], childKey);
            if (child !== undefined) result[childKey] = child;
        });
        return result;
    }

    function styleValue(value, key) {
        if (STYLE_KEY.test(key)) return stable(value);
        if (Array.isArray(value)) {
            var items = value.map(function (item) { return styleValue(item, ""); })
                .filter(function (item) { return item !== undefined; });
            return items.length ? items : undefined;
        }
        if (!value || typeof value !== "object") return undefined;
        var result = {};
        Object.keys(value).forEach(function (childKey) {
            var child = styleValue(value[childKey], childKey);
            if (child !== undefined) result[childKey] = child;
        });
        return Object.keys(result).length ? result : undefined;
    }

    function sectionContent(section) {
        var result = contentValue(section || {}, "") || {};
        delete result.id;
        return result;
    }

    function sectionStyle(section) {
        section = section && typeof section === "object" ? section : {};
        var columns = Array.isArray(section.columns) ? section.columns : [];
        return {
            settings: stable(section.settings || {}),
            columns: columns.map(function (column) {
                var elements = Array.isArray(column && column.elements) ? column.elements : [];
                return {
                    id: String((column && column.id) || ""),
                    style: styleValue(column || {}, "") || {},
                    elements: elements.map(function (element) {
                        return {
                            id: String((element && element.id) || ""),
                            type: String((element && element.type) || ""),
                            style: styleValue((element && element.data) || {}, "") || {},
                        };
                    }),
                };
            }),
        };
    }

    function sectionLabel(section, index) {
        section = section && typeof section === "object" ? section : {};
        var settings = section.settings && typeof section.settings === "object" ? section.settings : {};
        var candidates = [section.name, settings.title, section.library_name];
        for (var i = 0; i < candidates.length; i++) {
            var label = String(candidates[i] || "").trim();
            if (label) return label.slice(0, 120);
        }
        return "#" + (index + 1);
    }

    function indexed(sections, side) {
        var map = new Map();
        var order = [];
        sections.forEach(function (section, index) {
            var id = String((section && section.id) || "").trim();
            var key = id && !map.has(id) ? id : "@" + side + ":" + index;
            map.set(key, { id: id, key: key, index: index, section: section || {} });
            order.push(key);
        });
        return { map: map, order: order };
    }

    function lcsSet(left, right) {
        var rows = left.length + 1;
        var cols = right.length + 1;
        var table = Array.from({ length: rows }, function () { return Array(cols).fill(0); });
        for (var i = 1; i < rows; i++) {
            for (var j = 1; j < cols; j++) {
                table[i][j] = left[i - 1] === right[j - 1]
                    ? table[i - 1][j - 1] + 1
                    : Math.max(table[i - 1][j], table[i][j - 1]);
            }
        }
        var result = new Set();
        var x = left.length;
        var y = right.length;
        while (x > 0 && y > 0) {
            if (left[x - 1] === right[y - 1]) {
                result.add(left[x - 1]);
                x--;
                y--;
            } else if (table[x - 1][y] >= table[x][y - 1]) {
                x--;
            } else {
                y--;
            }
        }
        return result;
    }

    function summarize(publishedValue, currentValue) {
        var published = documentOf(publishedValue);
        var current = documentOf(currentValue);
        var before = indexed(published.sections, "published");
        var after = indexed(current.sections, "current");
        var commonBefore = before.order.filter(function (key) { return after.map.has(key); });
        var commonAfter = after.order.filter(function (key) { return before.map.has(key); });
        var stableOrder = lcsSet(commonBefore, commonAfter);
        var items = [];
        var totals = { added: 0, removed: 0, moved: 0, content: 0, style: 0, settings: 0 };

        after.order.forEach(function (key) {
            var currentEntry = after.map.get(key);
            var previousEntry = before.map.get(key);
            if (!previousEntry) {
                totals.added++;
                items.push({
                    id: currentEntry.id,
                    label: sectionLabel(currentEntry.section, currentEntry.index),
                    currentIndex: currentEntry.index,
                    added: true, removed: false, moved: false, content: false, style: false, settings: false,
                    canLocate: currentEntry.id !== "",
                });
                return;
            }

            var moved = !stableOrder.has(key);
            var content = !same(sectionContent(previousEntry.section), sectionContent(currentEntry.section));
            var style = !same(sectionStyle(previousEntry.section), sectionStyle(currentEntry.section));
            if (!moved && !content && !style) return;
            if (moved) totals.moved++;
            if (content) totals.content++;
            if (style) totals.style++;
            items.push({
                id: currentEntry.id,
                label: sectionLabel(currentEntry.section, currentEntry.index),
                currentIndex: currentEntry.index,
                previousIndex: previousEntry.index,
                added: false, removed: false, moved: moved, content: content, style: style, settings: false,
                canLocate: currentEntry.id !== "",
            });
        });

        before.order.forEach(function (key) {
            if (after.map.has(key)) return;
            var entry = before.map.get(key);
            totals.removed++;
            items.push({
                id: entry.id,
                label: sectionLabel(entry.section, entry.index),
                previousIndex: entry.index,
                added: false, removed: true, moved: false, content: false, style: false, settings: false,
                canLocate: false,
            });
        });

        if (!same(published.settings, current.settings)) {
            totals.settings = 1;
            items.unshift({
                id: "", label: "", added: false, removed: false, moved: false,
                content: false, style: false, settings: true, canLocate: false,
            });
        }

        return {
            changed: items.length > 0,
            total: items.length,
            totals: totals,
            items: items,
        };
    }

    global.BloxDraftSummary = { summarize: summarize };
})(typeof window !== "undefined" ? window : globalThis);
