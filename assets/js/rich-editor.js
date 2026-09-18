/**
 * 富文本编辑器的公共胶水：后台通用页（admin/includes/footer.php）与 Blox 编辑器
 * （admin/blox_editor.php）共用，紧跟在 /assets/hugerte/hugerte.min.js 之后加载。
 *
 * 编辑器是 HugeRTE：TinyMCE 6 在改 GPL 之前那个 MIT 提交处的分支，API 一致，
 * 全局对象名是 hugerte（v1.20.0 起替换随包的 TinyMCE 6.8.5，见复审 R03）。
 */
(function (global) {
    'use strict';

    // 给仍按 tinymce.* 取编辑器实例的第三方插件和站点自定义脚本留一个别名。
    // 本仓自己的代码一律直接用 hugerte。
    if (global.hugerte && !global.tinymce) {
        global.tinymce = global.hugerte;
    }

    /**
     * 后台界面语言 → 编辑器语言包名。随包只有 ja / zh_CN 两个语言包，其余语言用组件
     * 自带的英文界面（返回空串，调用方传 undefined 即可）。
     * 旧写法是「不是 ja 就按中文」，英文后台的编辑器整条工具栏都是中文（复审 R09）。
     */
    global.editorLanguage = function (lang) {
        var packs = { 'ja': 'ja', 'zh-cn': 'zh_CN', 'zh': 'zh_CN', 'zh-tw': 'zh_CN' };
        return packs[String(lang || '').toLowerCase()] || '';
    };
})(window);
