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
     * v1 产品模板的**只读适配**：{mode:all|selected, ids[], lang, source} → 面板的初始视图。
     * 与 DetailTemplateResolver::legacyScope() 同一语义（v1 只有产品详情模板）：
     *   mode=all      → 一条 all 规则
     *   mode=selected → 一条 item 规则；ids 为空表示 v1 的"未应用"（空 include），不是 all
     * 仅在打开面板时用于生成初始行，**绝不写回文档**；也不带 version，避免被误当成已声明的 v2 契约。
     */
    function legacyProductScope(legacy) {
        var source = legacy && typeof legacy === 'object' ? legacy : {};
        var include;
        if (source.mode === 'all') {
            include = [{ kind: 'all' }];
        } else {
            var ids = (Array.isArray(source.ids) ? source.ids : [])
                .map(function (id) { return String(id); })
                .filter(function (id) { return Number(id) > 0; })
                .filter(function (id, index, list) { return list.indexOf(id) === index; });
            include = ids.length ? [{ kind: 'item', ids: ids }] : [];
        }
        return {
            lang: typeof source.lang === 'string' ? source.lang : '',
            source: source.source === 'native' ? 'native' : 'custom',
            priority: 0,
            include: include,
            exclude: [],
        };
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
     * 归一成"面板负责的字段"：include/exclude（统一成行模型形状）＋ priority。
     * 为什么要归一：基线来自服务端归一后的作用域（`all` 规则可能带或不带 ids/子级、priority 可能是数字或数字串），
     * 行模型总是补齐这几列——直接比原样会得出"保存成功却仍显示已修改"的假阳性。
     * priority 是面板可编辑的第二类输入，**必须**参与比较（TASK-007）：只改优先级也要能标脏、能保存。
     * source/lang 不参与：它们不是面板可编辑项，只要求原样保留。
     */
    function panelState(input) {
        var rows = rowsFromScope(input);
        var value = input && typeof input === 'object' ? input : {};
        var priority = Number(value.priority);
        return {
            include: rows.include,
            exclude: rows.exclude,
            priority: isFinite(priority) && Math.floor(priority) === priority ? priority : 0,
        };
    }

    /** 面板状态的签名（形状统一，不依赖服务端是否补齐字段）。 */
    function signature(input) {
        return JSON.stringify(panelState(input));
    }

    /** 面板状态相对基线是否发生了变化（决定保存时是否附带 conditions_json）。 */
    function changed(before, after) {
        return signature(before) !== signature(after);
    }

    /** 优先级是否合法：0..max 的整数（含数字字符串；空串＝未填，算非法）。 */
    function isValidPriority(value, maxPriority) {
        var max = Number.isInteger(maxPriority) && maxPriority > 0 ? maxPriority : 100;
        if (value === '' || value === null || typeof value === 'undefined') return false;
        var number = Number(value);
        return isFinite(number) && Math.floor(number) === number && number >= 0 && number <= max;
    }

    /** 提交前的可读问题清单（空数组＝可提交）。 */
    function problems(rows, priority, maxPriority) {
        var out = [];
        if (typeof priority !== 'undefined' && priority !== null && !isValidPriority(priority, maxPriority)) {
            out.push({ side: '', index: -1, code: 'bad_priority' });
        }
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
        legacyProductScope: legacyProductScope,
        scopeFromRows: scopeFromRows,
        panelState: panelState,
        signature: signature,
        changed: changed,
        isValidPriority: isValidPriority,
        problems: problems,
    };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    if (root) root.BloxDetailConditions = api;
})(typeof window !== 'undefined' ? window : null);
