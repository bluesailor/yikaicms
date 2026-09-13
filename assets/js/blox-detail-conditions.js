/**
 * 完整条件面板的行模型（TASK-006 第一批）。
 *
 * 面板编辑的是"行"（每条 include/exclude 规则一行），落库的是 v2 作用域。
 * 这里的函数是**纯函数**：不读 DOM、不写文档、不判定命中；匹配语义一律由服务端
 * DetailTemplateResolver 负责，客户端不复制一套。
 */
(function (root) {
    'use strict';

    /** v2 作用域 → 行模型（保持顺序；缺少的侧给空数组）。 */
    function rowsFromScope(scope) {
        var out = { include: [], exclude: [] };
        ['include', 'exclude'].forEach(function (side) {
            var rules = scope && Array.isArray(scope[side]) ? scope[side] : [];
            out[side] = rules.filter(function (rule) {
                return rule && typeof rule === 'object';
            }).map(function (rule) {
                return {
                    kind: String(rule.kind || 'item'),
                    // 目标 id 统一成字符串：多选框的 option.value 天生是字符串，
                    // 用数字会让"重开后面板显示未选中"（数据其实还在，界面误导）。
                    // 提交时由 scopeFromRows 再转回整数。
                    ids: Array.isArray(rule.ids) ? rule.ids.map(function (id) { return String(id); })
                        .filter(function (id) { return Number(id) > 0; }) : [],
                    include_children: rule.include_children === true,
                };
            });
        });
        return out;
    }

    /**
     * 行模型 → v2 作用域（供 conditions_json 提交）。
     * base 携带不编辑但必须原样保留的字段：content_type / lang / source / priority。
     * 空目标的行会被剔除（面板另有 conditionProblems() 先拦，避免提交后被服务端拒绝）。
     */
    function scopeFromRows(rows, base) {
        var origin = base || {};
        var scope = {
            version: 2,
            content_type: origin.content_type,
            lang: origin.lang,
            source: origin.source,
            priority: origin.priority,
            include: [],
            exclude: [],
        };
        ['include', 'exclude'].forEach(function (side) {
            (rows && Array.isArray(rows[side]) ? rows[side] : []).forEach(function (row) {
                if (!row) return;
                if (row.kind === 'all') {
                    scope[side].push({ kind: 'all' });
                    return;
                }
                var ids = (Array.isArray(row.ids) ? row.ids : []).map(Number)
                    .filter(function (id) { return Number.isInteger(id) && id > 0; });
                ids = ids.filter(function (id, index) { return ids.indexOf(id) === index; });
                if (!ids.length) return;
                scope[side].push({
                    kind: row.kind,
                    ids: ids,
                    // 子级只对分类有意义（与服务端归一后的形状一致）
                    include_children: row.kind === 'category' && row.include_children === true,
                });
            });
        });
        return scope;
    }

    /**
     * 只比较面板负责的字段：include / exclude，且先归一成行模型的统一形状。
     * 为什么要归一：基线来自服务端归一后的作用域（`all` 规则可能带或不带 ids/子级），
     * 而行模型总是补齐这三列——直接比原样会得出"保存成功却仍显示已修改"的假阳性。
     */
    function signature(scope) {
        return JSON.stringify(rowsFromScope(scope));
    }

    /** 条件是否相对基线发生了变化（决定保存时是否附带 conditions_json）。 */
    function changed(before, after) {
        return signature(before) !== signature(after);
    }

    /** 提交前的可读问题清单（空数组＝可提交）。 */
    function problems(rows) {
        var out = [];
        ['include', 'exclude'].forEach(function (side) {
            (rows && Array.isArray(rows[side]) ? rows[side] : []).forEach(function (row, index) {
                if (!row) return;
                if (row.kind === 'all') return;
                var ids = Array.isArray(row.ids) ? row.ids : [];
                if (!ids.length) out.push({ side: side, index: index, code: 'missing_target' });
            });
        });
        return out;
    }

    var api = {
        rowsFromScope: rowsFromScope,
        scopeFromRows: scopeFromRows,
        signature: signature,
        changed: changed,
        problems: problems,
    };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    if (root) root.BloxDetailConditions = api;
})(typeof window !== 'undefined' ? window : null);
