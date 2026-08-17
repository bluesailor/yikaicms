(function () {
    "use strict";
    function enabledForDevice(root) {
        var mode = root.dataset.device || "all";
        var mobile = window.matchMedia("(max-width: 767px)").matches;
        return mode === "all" || (mode === "mobile" ? mobile : !mobile);
    }
    function storage(root) {
        var frequency = root.dataset.frequency || "session";
        if (frequency === "session") return window.sessionStorage;
        if (frequency === "hours") return window.localStorage;
        return null;
    }
    function storageKey(root) { return "yikai:blox-popup:" + root.dataset.bloxPopup + ":" + root.dataset.version; }
    function canOpen(root) {
        var store = storage(root);
        if (!store) return true;
        var seen = Number(store.getItem(storageKey(root)) || 0);
        if (!seen) return true;
        if ((root.dataset.frequency || "") === "session") return false;
        return Date.now() - seen >= Math.max(1, Number(root.dataset.hours || 24)) * 3600000;
    }
    function remember(root) {
        var store = storage(root);
        if (store) store.setItem(storageKey(root), String(Date.now()));
    }
    function focusable(root) {
        return Array.prototype.slice.call(root.querySelectorAll("a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex='-1'])"));
    }
    function setup(root) {
        if (!enabledForDevice(root) || !canOpen(root)) return;
        var opener = null;
        var opened = false;
        function close() {
            if (!opened) return;
            opened = false;
            root.classList.remove("is-open");
            root.setAttribute("aria-hidden", "true");
            document.body.classList.remove("yk-popup-open");
            if (opener && typeof opener.focus === "function") opener.focus();
        }
        function open(source) {
            if (opened || !canOpen(root)) return;
            opener = source || document.activeElement;
            opened = true;
            remember(root);
            root.classList.add("is-open");
            root.setAttribute("aria-hidden", "false");
            document.body.classList.add("yk-popup-open");
            var targets = focusable(root);
            (targets[0] || root.querySelector(".yk-blox-popup__panel")).focus();
        }
        root.addEventListener("click", function (event) {
            if (event.target.closest("[data-popup-close]")) close();
        });
        root.addEventListener("keydown", function (event) {
            if (event.key === "Escape") { close(); return; }
            if (event.key !== "Tab") return;
            var targets = focusable(root);
            if (!targets.length) { event.preventDefault(); return; }
            var first = targets[0], last = targets[targets.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        });
        var trigger = root.dataset.trigger || "delay";
        if (trigger === "click") {
            var selector = root.dataset.selector || "";
            if (selector) document.addEventListener("click", function (event) {
                var source = event.target.closest(selector);
                if (source) { event.preventDefault(); open(source); }
            });
        } else if (trigger === "exit") {
            document.addEventListener("mouseout", function onExit(event) {
                if (event.clientY > 0 || event.relatedTarget) return;
                document.removeEventListener("mouseout", onExit);
                open();
            });
        } else {
            window.setTimeout(function () { open(); }, Math.max(0, Number(root.dataset.delay || 0)) * 1000);
        }
    }
    document.querySelectorAll("[data-blox-popup]").forEach(setup);
})();
