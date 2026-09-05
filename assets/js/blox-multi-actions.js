/**
 * Blox 批量动作纯逻辑（R2：删除/复制/剪切/粘贴的数组运算）。
 *
 * 一切按稳定 id 解析后「整体重建」目标数组——绝不正序按下标删（下标会连锁失效）。
 * 不碰 DOM、不发包；同一输入产生同一输出。复制与粘贴的新 id 由调用方注入
 * idFactory，保持本模块可测、可回滚（回滚由编辑器 runCommand 的快照层负责）。
 */
(function (global) {
    "use strict";

    function isObject(value) {
        return !!value && typeof value === "object";
    }

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

    // 结构化克隆：区块=section→columns[*]→elements[*]→data.children[*] 四层，
    // 每一层都重配 id（kind 决定前缀：section/column/element）；漏层就会留下重复 ID。
    function cloneElement(el, idFactory) {
        var twin = JSON.parse(JSON.stringify(el));
        twin.id = idFactory("element");
        var children = twin.data && Array.isArray(twin.data.children) ? twin.data.children : [];
        children.forEach(function (child, index) {
            children[index] = cloneElement(child, idFactory);
        });
        return twin;
    }
    function cloneColumn(column, idFactory) {
        var twin = JSON.parse(JSON.stringify(column));
        twin.id = idFactory("column");
        var elements = Array.isArray(twin.elements) ? twin.elements : [];
        elements.forEach(function (el, index) {
            elements[index] = cloneElement(el, idFactory);
        });
        return twin;
    }
    function cloneSection(section, idFactory) {
        var twin = JSON.parse(JSON.stringify(section));
        twin.id = idFactory("section");
        var columns = Array.isArray(twin.columns) ? twin.columns : [];
        columns.forEach(function (col, index) {
            columns[index] = cloneColumn(col, idFactory);
        });
        return twin;
    }
    function cloneByKind(item, idFactory, kind) {
        if (kind === "section") return cloneSection(item, idFactory);
        return cloneElement(item, idFactory);
    }

    /**
     * 复制给定 id 集合：副本按文档顺序紧跟集合最后一项之后插入，每个副本（含后代）
     * 重新分配 id。返回新列表与副本 id（文档顺序）。
     */
    function duplicateByIds(list, ids, idFactory, kind) {
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
                var twin = cloneByKind(origin, idFactory, kind);
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
    function appendCloned(list, items, idFactory, kind) {
        var result = (Array.isArray(list) ? list : []).slice();
        var newIds = [];
        (items || []).forEach(function (origin) {
            var twin = cloneByKind(origin, idFactory, kind);
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
            return { level: "child", list: host.data.children, host: host };
        }
        return null;
    }

    /**
     * 批量删除/剪切/复制的编排计划：挑集合、做上限/下限检查、产出新数组。
     * 宿主业务规则由调用方折叠成 maxCount/minCount（0 = 不限）；
     * 返回 error: "minimum" | "limit" | "failed" 时调用方不得改动文档。
     */
    function planBatchAction(kind, list, ids, idFactory, cloneKind, maxCount, minCount) {
        var source = Array.isArray(list) ? list : [];
        var picked = pickByIds(source, ids);
        if (!picked.items.length) return { error: "failed" };
        var resulting = source.length - picked.items.length;
        if (kind === "delete" || kind === "cut") {
            if (minCount && resulting < minCount) return { error: "minimum" };
            return {
                list: removeByIds(source, ids).list,
                picked: picked.items,
                removed: picked.items.length,
                error: null,
            };
        }
        if (kind === "duplicate") {
            resulting = source.length + picked.items.length;
            if (maxCount && resulting > maxCount) return { error: "limit" };
            var dup = duplicateByIds(source, ids, idFactory, cloneKind);
            return { list: dup.list, newIds: dup.newIds, removed: 0, error: null };
        }
        return { error: "failed" };
    }

    /**
     * 批量粘贴编排：追加剪贴板内容并重配 id；opts.maxCount 为宿主上限，
     * opts.canNest(item, twin) 为容器的逐项许可（返回 false 即整体拒绝）。
     * 拒绝时返回 error，调用方不得改动文档。
     */
    function planPaste(list, items, idFactory, cloneKind, opts) {
        var source = Array.isArray(list) ? list : [];
        var incoming = (items || []).filter(isObject);
        if (!incoming.length) return { error: "empty" };
        var maxCount = opts && opts.maxCount;
        if (maxCount && source.length + incoming.length > maxCount) return { error: "limit" };
        var result = source.slice();
        var newIds = [];
        for (var i = 0; i < incoming.length; i++) {
            var twin = cloneByKind(incoming[i], idFactory, cloneKind);
            if (opts && typeof opts.canNest === "function" && !opts.canNest(incoming[i], twin)) {
                return { error: "rejected" };
            }
            newIds.push(idOf(twin));
            result.push(twin);
        }
        return { list: result, newIds: newIds, error: null };
    }

    var api = {
        removeByIds: removeByIds,
        pickByIds: pickByIds,
        duplicateByIds: duplicateByIds,
        appendCloned: appendCloned,
        scopeContext: scopeContext,
        planBatchAction: planBatchAction,
        planPaste: planPaste,
    };

    global.YikaiBloxMultiActions = api;
    if (typeof module === "object" && module && module.exports) {
        module.exports = api;
    }
})(typeof window !== "undefined" ? window : globalThis);
