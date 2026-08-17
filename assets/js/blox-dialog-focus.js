(function (global) {
    "use strict";

    var stack = [];
    var focusableSelector = [
        "a[href]",
        "button:not([disabled])",
        "input:not([disabled])",
        "select:not([disabled])",
        "textarea:not([disabled])",
        "[contenteditable='true']",
        "[tabindex]:not([tabindex='-1'])",
    ].join(",");

    function visible(element) {
        return !!element
            && element.getAttribute("aria-hidden") !== "true"
            && (typeof element.getClientRects !== "function" || element.getClientRects().length > 0);
    }

    function focusables(root) {
        if (!root || typeof root.querySelectorAll !== "function") return [];
        return Array.prototype.filter.call(root.querySelectorAll(focusableSelector), visible);
    }

    function top() {
        return stack.length ? stack[stack.length - 1] : null;
    }

    function open(root, initialSelector) {
        if (!root) return;
        for (var i = stack.length - 1; i >= 0; i--) {
            if (stack[i].root === root) stack.splice(i, 1);
        }
        stack.push({ root: root, opener: global.document ? global.document.activeElement : null });
        var schedule = typeof global.requestAnimationFrame === "function"
            ? global.requestAnimationFrame
            : function (callback) { global.setTimeout(callback, 0); };
        schedule(function () {
            var entry = top();
            if (!entry || entry.root !== root) return;
            var initial = initialSelector && typeof root.querySelector === "function"
                ? root.querySelector(initialSelector)
                : null;
            var candidates = focusables(root);
            var target = visible(initial) ? initial : (candidates[0] || root);
            if (target && typeof target.focus === "function") target.focus();
        });
    }

    function close(root) {
        var index = -1;
        for (var i = stack.length - 1; i >= 0; i--) {
            if (stack[i].root === root) { index = i; break; }
        }
        if (index < 0) return;
        var wasTop = index === stack.length - 1;
        var entry = stack[index];
        stack.splice(index, 1);
        if (!wasTop) return;
        var opener = entry.opener;
        if (opener && opener.isConnected !== false && typeof opener.focus === "function") opener.focus();
    }

    function keydown(event, root, onEscape) {
        var entry = top();
        if (!event || !entry || entry.root !== root) return;
        if (event.key === "Escape") {
            event.preventDefault();
            if (typeof event.stopPropagation === "function") event.stopPropagation();
            if (typeof onEscape === "function") onEscape();
            return;
        }
        if (event.key !== "Tab") return;
        var candidates = focusables(root);
        if (!candidates.length) {
            event.preventDefault();
            if (typeof root.focus === "function") root.focus();
            return;
        }
        var active = global.document ? global.document.activeElement : null;
        var first = candidates[0];
        var last = candidates[candidates.length - 1];
        if (event.shiftKey && (active === first || active === root)) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && active === last) {
            event.preventDefault();
            first.focus();
        }
    }

    global.BloxDialogFocus = {
        open: open,
        close: close,
        keydown: keydown,
        focusables: focusables,
        reset: function () { stack = []; },
    };
})(window);
