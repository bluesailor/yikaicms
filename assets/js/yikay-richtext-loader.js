(function (global) {
    "use strict";
    var script = global.document.currentScript;
    var source = script && script.dataset.hugerteSrc;
    var pending = null;

    function ready() {
        if (!global.hugerte || typeof global.hugerte.init !== "function") throw new Error("Rich-text runtime unavailable");
        if (typeof global.installRichEditorRuntime === "function") global.installRichEditorRuntime();
        return global.hugerte;
    }

    function load() {
        if (global.hugerte && typeof global.hugerte.init === "function") return Promise.resolve().then(ready);
        if (pending) return pending;
        var node;
        pending = new Promise(function (resolve, reject) {
            node = global.document.createElement("script");
            node.src = source || (global.YK_BASE || "") + "/assets/hugerte/hugerte.min.js";
            node.async = true;
            node.onload = function () {
                try { resolve(ready()); } catch (error) { reject(error); }
            };
            node.onerror = function () { reject(new Error("Rich-text runtime could not be loaded")); };
            global.document.head.appendChild(node);
        }).catch(function (error) {
            pending = null;
            if (node) node.remove();
            throw error;
        });
        return pending;
    }

    global.BloxRichTextLoader = { load: load };
})(typeof window !== "undefined" ? window : globalThis);
