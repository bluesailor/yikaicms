/**
 * Blox 批量动作纯逻辑（R2：删除/复制/剪切/粘贴的数组运算）。
 *
 * 一切按稳定 id 解析后「整体重建」目标数组——绝不正序按下标删（下标会连锁失效）。
 * 不碰 DOM、不发包；同一输入产生同一输出。复制与粘贴的新 id 由调用方注入
 * idFactory，保持本模块可测、可回滚（回滚由编辑器 runCommand 的快照层负责）。
 */
(function (global) {
    "use strict";

    function idOf(item) {
        return item && item.id !== undefined && item.id !== null ? String(item.id) : "";
    }

    function contains(ids, id) {
        return ids.indexOf(id) !== -1;
    }

    /**
     * 从有序列表中删除给定 id 集合（整体重建）。
     * @return {{list:Array, removed:Array, missing:Array}} removed/missing 均按文档顺序
     */
    function removeByIds(list, ids) {
        var idSet = (ids || []).map(String);
        var kept = [];
        var removed = [];
        var source = Array.isArray(list) ? list : [];
        source.forEach(function (item) {
            var id = idOf(item);
            if (id !== "" && contains(idSet, id)) removed.push(item);
            else kept.push(item);
        });
        var missing = idSet.filter(function (id) {
            return !removed.some(function (item) { return idOf(item) === id; });
        });
        return { list: kept, removed: removed, missing: missing };
    }

    /** 有序列表里，给定 id 集合按文档顺序的子集（剪贴板/复制的基准）。 */
    function pickByIds(list, ids) {
        var idSet = (ids || []).map(String);
        var picked = (Array.isArray(list) ? list : []).filter(function (item) {
            var id = idOf(item);
            return id !== "" && contains(idSet, id);
        });
        var missing = idSet.filter(function (id) {
            return !picked.some(function (item) { return idOf(item) === id; });
        });
        return { items: picked, missing: missing };
    }

    function cloneItem(item, idFactory) {
        var twin = JSON.parse(JSON.stringify(item));
        var assign = function (node) {
            node.id = idFactory();
            var children = node.data && Array.isArray(node.data.children) ? node.data.children : [];
            children.forEach(assign);
        };
        assign(twin);
        return twin;
    }

    /**
     * 复制给定 id 集合：副本按文档顺序紧跟集合最后一项之后插入，每个副本（含后代）
     * 重新分配 id。返回新列表与副本 id（文档顺序）。
     */
    function duplicateByIds(list, ids, idFactory) {
        var source = Array.isArray(list) ? list : [];
        var picked = pickByIds(source, ids);
        var idSet = (ids || []).map(String);
        var isSet = function (item) {
            var id = idOf(item);
            return id !== "" && contains(idSet, id);
        };
        var lastSetIndex = -1;
        source.forEach(function (item, index) {
            if (isSet(item)) lastSetIndex = index;
        });
        var result = [];
        var newIds = [];
        var insertClones = function () {
            picked.items.forEach(function (origin) {
                var twin = cloneItem(origin, idFactory);
                newIds.push(idOf(twin));
                result.push(twin);
            });
        };
        source.forEach(function (item, index) {
            result.push(item);
            if (index === lastSetIndex) insertClones();
        });
        return { list: result, newIds: newIds, items: picked.items, missing: picked.missing };
    }

    /**
     * 粘贴：把 items（已按文档顺序的剪贴板内容）追加到列表末尾，逐项重配 id。
     */
    function appendCloned(list, items, idFactory) {
        var result = (Array.isArray(list) ? list : []).slice();
        var newIds = [];
        (items || []).forEach(function (origin) {
            var twin = cloneItem(origin, idFactory);
            newIds.push(idOf(twin));
            result.push(twin);
        });
        return { list: result, newIds: newIds };
    }

    /**
     * 把多选作用域解析为「可整体重建的数组引用」；id 缺失/作用域悬空返回 null。
     * sections 由调用方传入（本模块不持引用）。
     */
    function scopeContext(sections, level, parent) {
        var source = Array.isArray(sections) ? sections : [];
        var key = String(parent || "");
        if (level === "section" && key === "root") {
            return { level: level, list: source };
        }
        if (level === "element") {
            var parts = key.split("/");
            var section = null;
            source.forEach(function (s) { if (String(s.id || "") === parts[0]) section = s; });
            var column = null;
            if (section) (section.columns || []).forEach(function (c) { if (String(c.id || "") === parts[1]) column = c; });
            if (!column) return null;
            column.elements = column.elements || [];
            return { level: level, list: column.elements };
        }
        if (level === "child") {
            var hostId = key.replace("children:", "");
            var host = null;
            source.forEach(function (s) {
                (s.columns || []).forEach(function (c) {
                    (c.elements || []).forEach(function (el) { if (String(el.id || "") === hostId) host = el; });
                });
            });
            if (!host || !host.data) return null;
            host.data.children = host.data.children || [];
            return { level: "child", list: host.data.children };
        }
        return null;
    }

    var api = {
        removeByIds: removeByIds,
        pickByIds: pickByIds,
        duplicateByIds: duplicateByIds,
        appendCloned: appendCloned,
        scopeContext: scopeContext,
    };

    global.YikaiBloxMultiActions = api;
    if (typeof module === "object" && module && module.exports) {
        module.exports = api;
    }
})(typeof window !== "undefined" ? window : globalThis);
