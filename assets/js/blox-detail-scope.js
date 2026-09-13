/**
 * 详情模板"简单范围 UI"的可表达能力判定（TASK-005 A）。
 *
 * 背景：文章模板编辑器的简单范围控件（模式选择 + 文章勾选）会用**单条规则整体替换** include
 * （blox_editor.php 的 setArticleScopeMode/toggleArticleScopeId），因此当 include 里存在它
 * 无法无损表达的规则（按栏目 category、或多条规则）时，任何范围操作都会静默删除这些规则。
 *
 * 这里只做**纯判定**：哪些 scope 允许用简单 UI 编辑。不解析命中、不定义 none 语义、不改写规则。
 * 说明：两个写方法只赋值 include，不触碰 exclude/priority/lang/source，所以 exclude 不属于
 * "会被覆盖丢失"的范围（它交由只读呈现解释），不在此处拦截。
 */
(function (root) {
    'use strict';

    /** include 规则数组（非数组/缺失一律视为空）。 */
    function includeRules(scope) {
        return scope && Array.isArray(scope.include) ? scope.include : [];
    }

    /**
     * 是否含"简单 UI 无法无损表达"的 include 规则。
     * - 空 include：none，简单 UI 可表达；
     * - 多条规则：简单 UI 只会写单条 → 覆盖会丢信息；
     * - 单条但 kind 不是 all/item（如 category）：不可表达。
     */
    function hasComplexInclude(scope) {
        var list = includeRules(scope);
        if (list.length === 0) return false;
        if (list.length > 1) return true;
        var kind = list[0] && list[0].kind;
        return kind !== 'all' && kind !== 'item';
    }

    /** 允许用简单范围 UI 编辑（false 时调用方必须禁用控件并在方法入口同样拦截）。 */
    function canEditWithSimpleUi(scope) {
        return !hasComplexInclude(scope);
    }

    var api = {
        includeRules: includeRules,
        hasComplexInclude: hasComplexInclude,
        canEditWithSimpleUi: canEditWithSimpleUi,
    };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    if (root) root.BloxDetailScope = api;
})(typeof window !== 'undefined' ? window : null);
