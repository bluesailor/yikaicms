(function () {
    "use strict";

    var selector = '[data-yk-language-switcher="dropdown"] details[open]';

    function close(details, restoreFocus) {
        if (!details) return;
        details.removeAttribute("open");
        if (restoreFocus) {
            var trigger = details.querySelector("summary");
            if (trigger) trigger.focus();
        }
    }

    document.addEventListener("click", function (event) {
        document.querySelectorAll(selector).forEach(function (details) {
            if (!details.contains(event.target)) close(details, false);
        });
    });

    document.addEventListener("keydown", function (event) {
        if (event.key !== "Escape") return;
        var open = Array.from(document.querySelectorAll(selector));
        if (open.length === 0) return;
        var active = open.find(function (details) {
            return details.contains(document.activeElement);
        }) || open[open.length - 1];
        open.forEach(function (details) { close(details, false); });
        close(active, true);
        event.preventDefault();
    });
})();
