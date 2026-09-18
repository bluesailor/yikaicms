/* Blox 价格方案：按月 / 按年切换（无 JS 时始终显示按月价格）。事件挂在 document 上，画布局部刷新后无需重新绑定。 */
(function () {
    "use strict";

    var ACTIVE = ["bg-white", "text-gray-900", "shadow-sm"];
    var IDLE = ["text-gray-500"];

    function setCycle(root, cycle) {
        root.setAttribute("data-yk-pricing-cycle", cycle);
        root.querySelectorAll("[data-yk-pricing-price]").forEach(function (price) {
            price.hidden = price.getAttribute("data-yk-pricing-price") !== cycle;
        });
        root.querySelectorAll("[data-yk-pricing-cycle-button]").forEach(function (button) {
            var active = button.getAttribute("data-yk-pricing-cycle-button") === cycle;
            button.setAttribute("aria-pressed", active ? "true" : "false");
            ACTIVE.forEach(function (name) { button.classList.toggle(name, active); });
            IDLE.forEach(function (name) { button.classList.toggle(name, !active); });
        });
    }

    function handleClick(event) {
        var button = event.target && event.target.closest ? event.target.closest("[data-yk-pricing-cycle-button]") : null;
        var root = button ? button.closest("[data-yk-pricing]") : null;
        if (!root) return;
        setCycle(root, button.getAttribute("data-yk-pricing-cycle-button") === "yearly" ? "yearly" : "monthly");
    }

    if (typeof module !== "undefined" && module.exports) {
        module.exports = { setCycle: setCycle, handleClick: handleClick };
        return;
    }
    document.addEventListener("click", handleClick);
})();
