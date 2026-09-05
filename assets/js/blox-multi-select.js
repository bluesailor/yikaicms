/**
 * Blox 同级多选状态机（R1：只管选择模型，不含批量操作）。
 *
 * 多选集合按稳定 id 存储（元素/子元素/区块各自的 id），绝不存 si/ci/ei 下标——
 * 批量删除时下标会连锁失效。只允许同一父级内多选：同列元素、同一容器的子元素、
 * 文档根下的区块；跨父级的修饰键点击以新点击项为锚点重新开始，不做跨级合并。
 * 纯函数：不碰 DOM、不发包；所有输入显式传入，同一输入产生同一输出。
 */
(function (global) {
    "use strict";

    function create() {
        return { ids: [], level: "", parent: "", anchor: "" };
    }

    function active(state) {
        return !!state && Array.isArray(state.ids) && state.ids.length >= 2 && state.level !== "";
    }

    function count(state) {
        return state && Array.isArray(state.ids) ? state.ids.length : 0;
    }

    function has(state, id) {
        return !!state && Array.isArray(state.ids) && state.ids.indexOf(String(id)) !== -1;
    }

    function sameScope(state, level, parent) {
        return !!state && state.level === level && state.parent === parent;
    }

    function restart(level, parent, id) {
        return { state: { ids: [id], level: level, parent: parent, anchor: id }, active: false, changed: true };
    }

    /**
     * @param {object|null} state 当前多选状态
     * @param {object} click {mode:"plain"|"toggle"|"shift", level, parent, id, siblings}
     *        siblings：同父级下按文档顺序排列的稳定 id 数组（shift 区间的基准）。
     * @return {{state:object, active:boolean, changed:boolean}}
     */
    function applyClick(state, click) {
        var previous = state || create();
        var mode = click ? String(click.mode || "") : "";
        var level = click ? String(click.level || "") : "";
        var parent = click ? String(click.parent || "") : "";
        var id = click ? String(click.id || "") : "";
        var siblings = click && Array.isArray(click.siblings) ? click.siblings.map(String) : [];

        if (mode !== "plain" && mode !== "toggle" && mode !== "shift") {
            return { state: create(), active: false, changed: count(previous) > 0 };
        }
        if (mode === "plain" || id === "") {
            // 普通点击：清掉激活集合，但保留「休眠锚点」（单击项），后续 shift 区间从它起算。
            if (id === "") return { state: create(), active: false, changed: count(previous) > 0 };
            return {
                state: { ids: [id], level: level, parent: parent, anchor: id },
                active: false,
                changed: count(previous) !== 1 || previous.ids[0] !== id || previous.anchor !== id,
            };
        }
        // 区间/增减的基准必须是当前父级下的合法成员，否则退化为重新开始。
        if (mode !== "plain" && siblings.indexOf(id) === -1) {
            return restart(level, parent, id);
        }

        if (mode === "toggle") {
            if (!sameScope(previous, level, parent)) {
                return restart(level, parent, id);
            }
            var ids = previous.ids.slice();
            var at = ids.indexOf(id);
            if (at === -1) ids.push(id); else ids.splice(at, 1);
            if (ids.length === 0) {
                return { state: create(), active: false, changed: true };
            }
            return {
                state: { ids: ids, level: level, parent: parent, anchor: previous.anchor || id },
                active: ids.length >= 2,
                changed: true,
            };
        }

        // shift：同作用域且有锚点 → 文档顺序区间（与点击方向无关，集合始终按文档序存储，
        // R2 的复制/粘贴以此为准）；否则以新点击项为锚点重新开始。普通点击留下的休眠锚点
        // 也算有效锚点。
        var anchor = sameScope(previous, level, parent) ? previous.anchor : "";
        var from = anchor !== "" ? siblings.indexOf(anchor) : -1;
        var to = siblings.indexOf(id);
        if (from === -1) {
            return restart(level, parent, id);
        }
        var range = siblings.slice(Math.min(from, to), Math.max(from, to) + 1);
        return {
            state: { ids: range, level: level, parent: parent, anchor: anchor },
            active: range.length >= 2,
            changed: true,
        };
    }

    var api = {
        MODES: ["plain", "toggle", "shift"],
        create: create,
        active: active,
        count: count,
        has: has,
        applyClick: applyClick,
        clear: create,
    };

    global.YikaiBloxMultiSelect = api;
    if (typeof module === "object" && module && module.exports) {
        module.exports = api;
    }
})(typeof window !== "undefined" ? window : globalThis);
