/**
 * Blox 控件显示规则求值器（r15，Elementor prop-dependencies 契约的显示子集）。
 *
 * 声明形态（元素 controls() 里，全部可序列化——插件只能声明，不能下发代码）：
 *   'required'     => [key, '='|'!=', value|values[]]          // 既有单条件，继续支持
 *   'visible_when' => ['relation' => 'and'|'or', 'terms' => [[key, op, value?], ...]]
 *
 * 操作符封闭枚举：= != in not_in empty not_empty > <
 * （empty/not_empty 不需要 value；> < 数值比较）。未知操作符视为不满足
 * （fail-closed：写错的条件宁可隐藏控件，也不显示一个"条件失效"的控件——
 * 引用/操作符的正确性由 BuilderSchemaContractTest 全元素扫描在测试期兜底）。
 */
(function (global) {
    "use strict";

    var OPS = ["=", "!=", "in", "not_in", "empty", "not_empty", ">", "<"];

    /** required 数组 / visible_when 对象 → 统一 {relation, terms}；无规则返回 null */
    function normalizeRule(ctrl) {
        if (!ctrl) return null;
        var vw = ctrl.visible_when;
        if (vw && typeof vw === "object" && Array.isArray(vw.terms)) {
            return {
                relation: vw.relation === "or" ? "or" : "and",
                terms: vw.terms.filter(function (t) { return Array.isArray(t) && t.length >= 2; }),
            };
        }
        var req = ctrl.required;
        if (Array.isArray(req) && req.length >= 3) {
            return { relation: "and", terms: [[req[0], req[1] === "!=" ? "!=" : "=", req[2]]] };
        }
        return null;
    }

    function isEmptyValue(v) {
        return v === undefined || v === null || v === "" || v === false || v === 0 || v === "0"
            || (Array.isArray(v) && v.length === 0);
    }

    function termMet(term, getValue) {
        var op = String(term[1]);
        if (OPS.indexOf(op) === -1) return false; // fail-closed：未知操作符
        var actual = getValue(String(term[0]));
        if (op === "empty") return isEmptyValue(actual);
        if (op === "not_empty") return !isEmptyValue(actual);
        var expected = term[2];
        if (op === "in" || op === "not_in") {
            var list = Array.isArray(expected) ? expected.map(String) : [String(expected)];
            var hit = list.indexOf(String(actual === undefined || actual === null ? "" : actual)) !== -1;
            return op === "in" ? hit : !hit;
        }
        if (op === ">" || op === "<") {
            var a = parseFloat(actual), b = parseFloat(expected);
            if (isNaN(a) || isNaN(b)) return false;
            return op === ">" ? a > b : a < b;
        }
        var matched = Array.isArray(expected)
            ? expected.map(String).indexOf(String(actual === undefined || actual === null ? "" : actual)) !== -1
            : String(actual === undefined || actual === null ? "" : actual) === String(expected === undefined || expected === null ? "" : expected);
        return op === "!=" ? !matched : matched;
    }

    /** 规则求值；无规则=显示。getValue(key) 由宿主提供（编辑器传控件当前值解析器）。 */
    function visibleWhenMet(ctrl, getValue) {
        var rule = normalizeRule(ctrl);
        if (!rule || rule.terms.length === 0) return true;
        var results = rule.terms.map(function (t) { return termMet(t, getValue); });
        return rule.relation === "or"
            ? results.indexOf(true) !== -1
            : results.indexOf(false) === -1;
    }

    var api = { normalizeRule: normalizeRule, visibleWhenMet: visibleWhenMet, OPS: OPS };
    if (typeof module !== "undefined" && module.exports) {
        module.exports = api;
    }
    global.BloxControlRules = api;
})(typeof window !== "undefined" ? window : globalThis);
