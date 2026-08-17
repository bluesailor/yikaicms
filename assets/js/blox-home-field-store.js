(function (global) {
    "use strict";

    var BLOCKED = ["__proto__", "prototype", "constructor"];

    function setValue(element, field, value, seeds) {
        if (!element || typeof element !== "object") return false;
        var parts = String(field || "").split(".").filter(Boolean);
        if (!parts.length || parts.some(function (part) { return BLOCKED.indexOf(part) !== -1; })) return false;

        element.data = element.data && typeof element.data === "object" ? element.data : {};
        if (parts.length === 1) {
            element.data[parts[0]] = value;
            return true;
        }

        var root = parts[0];
        var sourceSeeds = seeds && typeof seeds === "object" ? seeds : {};
        if (root === "custom_overrides") {
            // Locale keys are strings. An Array would silently drop zh_CN/ja properties in JSON.stringify().
            if (!element.data[root] || typeof element.data[root] !== "object" || Array.isArray(element.data[root])) {
                element.data[root] = {};
            }
        } else if (!Array.isArray(element.data[root]) && Array.isArray(sourceSeeds[root])) {
            element.data[root] = JSON.parse(JSON.stringify(sourceSeeds[root]));
        }
        if (root !== "custom_overrides" && !Array.isArray(element.data[root])) element.data[root] = [];

        var cursor = element.data;
        for (var index = 0; index < parts.length - 1; index++) {
            var part = parts[index];
            var nextIsIndex = /^\d+$/.test(parts[index + 1]);
            if (cursor[part] === null || typeof cursor[part] !== "object") {
                cursor[part] = nextIsIndex ? [] : {};
            }
            cursor = cursor[part];
        }
        cursor[parts[parts.length - 1]] = value;
        return true;
    }

    function deleteValue(element, field) {
        if (!element || !element.data || typeof element.data !== "object") return false;
        var parts = String(field || "").split(".").filter(Boolean);
        if (!parts.length || parts.some(function (part) { return BLOCKED.indexOf(part) !== -1; })) return false;
        var cursor = element.data;
        var stack = [];
        for (var index = 0; index < parts.length - 1; index++) {
            var part = parts[index];
            if (!cursor[part] || typeof cursor[part] !== "object") return false;
            stack.push([cursor, part]);
            cursor = cursor[part];
        }
        var leaf = parts[parts.length - 1];
        if (!Object.prototype.hasOwnProperty.call(cursor, leaf)) return false;
        delete cursor[leaf];
        for (var stackIndex = stack.length - 1; stackIndex >= 0; stackIndex--) {
            var parent = stack[stackIndex][0];
            var key = stack[stackIndex][1];
            if (Object.keys(parent[key]).length === 0) delete parent[key];
            else break;
        }
        return true;
    }

    function faqItems(seedItems, storedItems, customized, maxItems) {
        var limit = Math.max(1, Math.min(30, Number(maxItems) || 30));
        var seeds = Array.isArray(seedItems) ? seedItems : [];
        var stored = Array.isArray(storedItems) ? storedItems : [];
        var source = customized ? stored : seeds;
        return source.slice(0, limit).map(function (item, index) {
            var base = item && typeof item === "object" ? item : {};
            if (!customized) {
                var override = stored[index] && typeof stored[index] === "object" ? stored[index] : {};
                base = Object.assign({}, base, override);
            }
            return {
                question: String(base.question || ""),
                answer: String(base.answer || ""),
            };
        });
    }

    function parseAccordionItems(value, maxItems) {
        var limit = Math.max(1, Math.min(30, Number(maxItems) || 30));
        if (Array.isArray(value)) {
            return value.slice(0, limit).map(function (item) {
                var source = item && typeof item === "object" ? item : {};
                return {
                    question: String(source.question ?? ""),
                    answer: String(source.answer ?? ""),
                };
            });
        }
        return String(value ?? "").split(/\r\n|\r|\n/).reduce(function (items, line) {
            if (items.length >= limit || String(line).trim() === "") return items;
            var separator = line.indexOf("|");
            items.push({
                question: String(separator === -1 ? line : line.slice(0, separator)).trim(),
                answer: String(separator === -1 ? "" : line.slice(separator + 1)).trim(),
            });
            return items;
        }, []);
    }

    function moveItem(items, fromIndex, toIndex) {
        var list = Array.isArray(items) ? items.slice() : [];
        var from = Number(fromIndex);
        var to = Number(toIndex);
        if (!Number.isInteger(from) || !Number.isInteger(to)
            || from < 0 || to < 0 || from >= list.length || to >= list.length || from === to) {
            return list;
        }
        var moved = list.splice(from, 1)[0];
        list.splice(to, 0, moved);
        return list;
    }

    function cloneValue(value) {
        return value === undefined ? undefined : JSON.parse(JSON.stringify(value));
    }

    function mergeValue(seed, override) {
        if (override === undefined || override === null) return cloneValue(seed);
        if (Array.isArray(seed)) {
            var mergedArray = seed.map(cloneValue);
            if (!Array.isArray(override)) return mergedArray;
            Object.keys(override).forEach(function (key) {
                if (BLOCKED.indexOf(key) !== -1 || override[key] === null) return;
                var index = Number(key);
                if (!Number.isInteger(index) || index < 0) return;
                mergedArray[index] = mergeValue(seed[index], override[index]);
            });
            return mergedArray;
        }
        if (seed && typeof seed === "object") {
            var mergedObject = cloneValue(seed) || {};
            if (!override || typeof override !== "object" || Array.isArray(override)) return mergedObject;
            Object.keys(override).forEach(function (key) {
                if (BLOCKED.indexOf(key) !== -1) return;
                mergedObject[key] = mergeValue(seed[key], override[key]);
            });
            return mergedObject;
        }
        return cloneValue(override);
    }

    function structuralItems(seedItems, storedItems, customized, maxItems) {
        var limit = Math.max(1, Math.min(12, Number(maxItems) || 12));
        var seeds = Array.isArray(seedItems) ? seedItems : [];
        var stored = Array.isArray(storedItems) ? storedItems : [];
        var source = customized ? stored : mergeValue(seeds, stored);
        return Array.isArray(source) ? source.slice(0, limit).map(cloneValue) : [];
    }

    var api = {
        setValue: setValue,
        deleteValue: deleteValue,
        parseAccordionItems: parseAccordionItems,
        faqItems: faqItems,
        moveItem: moveItem,
        structuralItems: structuralItems,
    };
    if (typeof module !== "undefined" && module.exports) module.exports = api;
    global.BloxHomeFieldStore = api;
})(typeof window !== "undefined" ? window : globalThis);
