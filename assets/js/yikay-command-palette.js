/**
 * 网页构建器命令面板（Ctrl+K，2.0.4）的纯函数：模糊匹配排序、整页复制粘贴格式、文字查找替换。
 * 编辑器状态与命令清单在 admin/blox_editor/partials/command-palette-methods.php。
 */
(function (root) {
    "use strict";

    var PAGE_FORMAT = "yikai-blox-page";
    // 查找替换只改这些「访客看得到的文字」键（白名单）：设置项的取值（如 size: "lg"）不能被一次替换改坏
    var TEXT_KEY = /^(text|html|title|subtitle|content|description|desc|question|answer|label|role|caption|alt|quote|author|note|badge|eyebrow|heading|body|summary|features|placeholder|prefix|suffix|period|period_yearly|price_prefix|price_note|button_text)$|_(text|title|label|desc|description|content|html|note|caption|heading|subtitle|placeholder)$/i;
    // 白名单里也排除地址、图片、图标与内部键
    var SKIP_KEY = /^_|url|href|src|image|icon|avatar|logo|link/i;

    function normalize(value) {
        return String(value == null ? "" : value).toLowerCase();
    }

    /** 匹配分：连续子串最高、各词都出现其次、按顺序的散字母最低；不匹配返回 -1。 */
    function score(query, text) {
        var q = normalize(query).trim();
        var t = normalize(text);
        if (!q) return 0;
        var at = t.indexOf(q);
        if (at !== -1) return 1000 - at * 2 - Math.min(200, t.length - q.length) * 0.1;
        var tokens = q.split(/\s+/).filter(Boolean);
        if (tokens.length > 1 && tokens.every(function (token) { return t.indexOf(token) !== -1; })) {
            return 500 - Math.min(200, t.length) * 0.1;
        }
        var from = 0, last = -1, gaps = 0;
        for (var i = 0; i < q.length; i++) {
            if (q[i] === " ") continue;
            var found = t.indexOf(q[i], from);
            if (found === -1) return -1;
            if (last >= 0) gaps += found - last - 1;
            last = found;
            from = found + 1;
        }
        return Math.max(1, 100 - gaps);
    }

    /** items: {label, keywords?}；同分保持原顺序。 */
    function rank(items, query, limit) {
        var scored = [];
        (items || []).forEach(function (item, index) {
            var s = score(query, String(item.label || "") + " " + String(item.keywords || ""));
            if (s >= 0) scored.push({ item: item, score: s, index: index });
        });
        scored.sort(function (a, b) { return b.score - a.score || a.index - b.index; });
        return scored.slice(0, limit || 50).map(function (entry) { return entry.item; });
    }

    function serializePage(sections) {
        return JSON.stringify({ format: PAGE_FORMAT, version: 1, sections: sections || [] });
    }

    /** 只接受本格式且结构像区块列表的内容；否则返回 null。 */
    function parsePage(text) {
        var data;
        try { data = JSON.parse(String(text || "")); } catch (e) { return null; }
        if (!data || data.format !== PAGE_FORMAT || !Array.isArray(data.sections) || !data.sections.length) return null;
        var valid = data.sections.every(function (section) {
            return section && typeof section === "object" && Array.isArray(section.columns)
                && section.columns.every(function (column) { return column && Array.isArray(column.elements); });
        });
        return valid ? data.sections : null;
    }

    function countIn(text, find) {
        if (!find) return 0;
        var n = 0, at = text.indexOf(find);
        while (at !== -1) { n++; at = text.indexOf(find, at + find.length); }
        return n;
    }

    /**
     * 在一个字符串里替换（区分大小写）。像 HTML 的值只改文字节点，不碰标签与属性。
     * @return {{value:string,count:number}}
     */
    function replaceText(value, find, replacement) {
        var text = String(value);
        if (!find) return { value: text, count: 0 };
        if (text.indexOf("<") === -1 || typeof root === "undefined" || !root || !root.DOMParser) {
            if (text.indexOf("<") !== -1) {
                // 无 DOMParser（单测环境）：按标签切开，只替换标签之间的文字
                var total = 0;
                var out = text.split(/(<[^>]*>)/).map(function (part) {
                    if (part.charAt(0) === "<") return part;
                    var n = countIn(part, find);
                    total += n;
                    return n ? part.split(find).join(replacement) : part;
                }).join("");
                return { value: out, count: total };
            }
            var count = countIn(text, find);
            return { value: count ? text.split(find).join(replacement) : text, count: count };
        }
        var doc = new root.DOMParser().parseFromString("<body>" + text + "</body>", "text/html");
        var walker = doc.createTreeWalker(doc.body, 4 /* NodeFilter.SHOW_TEXT */);
        var hits = 0, node;
        while ((node = walker.nextNode())) {
            var n = countIn(node.nodeValue, find);
            if (n) { hits += n; node.nodeValue = node.nodeValue.split(find).join(replacement); }
        }
        return { value: hits ? doc.body.innerHTML : text, count: hits };
    }

    /**
     * 递归替换一个元素 data 里的可见文字（含重复项列表里的字段与子元素），就地修改。
     * dryRun 为真时只计数。返回替换次数。
     */
    function replaceInData(data, find, replacement, dryRun) {
        var total = 0;
        function walk(value, key) {
            if (typeof value === "string") {
                if (key === null || !TEXT_KEY.test(key) || SKIP_KEY.test(key)) return value;
                var result = replaceText(value, find, replacement);
                total += result.count;
                return dryRun ? value : result.value;
            }
            if (Array.isArray(value)) {
                for (var i = 0; i < value.length; i++) value[i] = walk(value[i], key);
                return value;
            }
            if (value && typeof value === "object") {
                Object.keys(value).forEach(function (k) {
                    if (k === "children" && Array.isArray(value[k])) {
                        value[k].forEach(function (child) {
                            if (child && child.data) child.data = walk(child.data, null);
                        });
                        return;
                    }
                    if (SKIP_KEY.test(k)) return;
                    // 对象 / 列表往下走（重复项字段在里面）；字符串只在白名单键上替换
                    value[k] = walk(value[k], k);
                });
            }
            return value;
        }
        walk(data, null);
        return total;
    }

    var api = {
        score: score, rank: rank, serializePage: serializePage, parsePage: parsePage,
        replaceText: replaceText, replaceInData: replaceInData, PAGE_FORMAT: PAGE_FORMAT,
    };
    if (typeof module !== "undefined" && module.exports) module.exports = api;
    if (root) root.BloxCommandPalette = api;
})(typeof window !== "undefined" ? window : null);
