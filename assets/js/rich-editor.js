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
     * 无障碍（2.0.4）：HugeRTE 状态栏有两处 ARIA 不合规，读屏可能念错或不念——
     * 元素路径（p › strong）是 role="button" 却带 aria-level；拖动改高度的把手是没有角色的 div 却带 aria-label。
     * 路径随光标所在节点重画，所以 init 时补一次，之后盯住状态栏的 DOM 变化再补。
     */
    function fixStatusbar(editor) {
        var container = editor.getContainer && editor.getContainer();
        if (!container) return;
        container.querySelectorAll('.tox-statusbar__path-item[aria-level]').forEach(function (item) {
            item.removeAttribute('aria-level');
        });
        container.querySelectorAll('.tox-statusbar__resize-handle[aria-label]:not([role])').forEach(function (handle) {
            handle.setAttribute('role', 'button');
        });
    }
    if (global.hugerte && typeof global.hugerte.on === 'function') {
        global.hugerte.on('AddEditor', function (event) {
            var editor = event.editor;
            editor.on('init', function () {
                fixStatusbar(editor);
                // 状态栏在 NodeChange 处理链里重画，比这里的监听晚：直接盯 DOM 变化再补
                var bar = editor.getContainer() && editor.getContainer().querySelector('.tox-statusbar');
                if (bar && global.MutationObserver) {
                    new global.MutationObserver(function () { fixStatusbar(editor); }).observe(bar, { childList: true, subtree: true });
                }
            });
        });
    }

    /**
     * 后台界面语言 → 编辑器语言包名。随包有 ja / zh_CN / zh_TW（zh_TW 由 tools/i18n/zh-tw.php
     * 从 zh_CN 生成），其余语言用组件自带的英文界面（返回空串，调用方传 undefined 即可）。
     * 旧写法是「不是 ja 就按中文」，英文后台的编辑器整条工具栏都是中文（复审 R09）。
     */
    global.editorLanguage = function (lang) {
        var packs = { 'ja': 'ja', 'zh-cn': 'zh_CN', 'zh': 'zh_CN', 'zh-tw': 'zh_TW' };
        return packs[String(lang || '').toLowerCase()] || '';
    };
})(window);
