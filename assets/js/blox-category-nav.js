/* 栏目 / 分类导航元素（CategoryNavElement）：子级手风琴开合；小屏下拉框选中即跳转。 */
(function () {
    "use strict";

    document.addEventListener("click", function (event) {
        var target = event.target;
        var button = target && target.closest ? target.closest("[data-yk-category-nav-toggle]") : null;
        if (!button) return;
        var panel = document.getElementById(button.getAttribute("aria-controls") || "");
        if (!panel) return;
        var open = button.getAttribute("aria-expanded") !== "true";
        button.setAttribute("aria-expanded", open ? "true" : "false");
        panel.hidden = !open;
    });

    document.addEventListener("change", function (event) {
        var select = event.target;
        if (!select || !select.matches || !select.matches("[data-yk-category-nav-select]")) return;
        // 编辑器画布里只预览，不跳走
        if (document.querySelector(".yk-canvas-region")) return;
        var url = select.value;
        if (url && url !== "#") window.location.href = url;
    });
})();
