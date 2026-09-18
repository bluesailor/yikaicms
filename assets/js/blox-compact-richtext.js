(function (global) {
    "use strict";

    function plainHtml(value) {
        var text = String(value == null ? "" : value);
        if (!text) return "";
        return "<p>" + text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
            .replace(/\r\n?|\n/g, "<br>") + "</p>";
    }

    function content(value) {
        return value.format === "html" ? String(value.text || "") : plainHtml(value.text);
    }

    function create(read, write, labels) {
        var editor = null, destroyed = false, syncing = false, last = "", editorHtml = "";
        var baseline = null;
        var owner = read().id;
        labels = labels || {};
        return {
            sourceMode: false,
            sourceValue: "",
            ready: false,
            failed: false,
            init: function () {
                var self = this;
                self.sync();
                self.$watch(function () { return JSON.stringify(read()); }, function () { self.sync(); });
                self.$nextTick(function () { self.mount(); });
            },
            sync: function () {
                if (destroyed) return;
                var value = read(), snapshot = JSON.stringify(value);
                if (value.id !== owner || snapshot === last) return;
                last = snapshot;
                this.sourceValue = content(value);
                if (editor && this.ready && !this.sourceMode) this.loadContent();
            },
            loadContent: function () {
                if (!editor || !this.ready) return;
                syncing = true;
                try {
                    editor.setContent(this.sourceValue);
                    editorHtml = editor.getContent();
                    var value = read();
                    baseline = { html: editorHtml, text: value.text, format: value.format };
                    editor.undoManager.clear();
                    editor.undoManager.add();
                } finally { syncing = false; }
            },
            commit: function (html, format) {
                if (arguments.length < 2) format = "html";
                var value = read();
                if (destroyed || value.id !== owner) return;
                this.sourceValue = content({ text: html, format: format });
                last = JSON.stringify(Object.assign({}, value, { text: html, format: format }));
                write(html, format);
            },
            sourceInput: function (html) {
                this.commit(html);
            },
            toggleSource: function () {
                this.sourceMode = !this.sourceMode;
                // init 之前 editor 已由 setup() 赋值但还没有 body，hide()/show() 会在 null 上设
                // contentEditable 而抛错；未就绪时只记状态，init 回调会按 sourceMode 补一次 hide()。
                if (this.sourceMode) {
                    if (editor && this.ready) editor.hide();
                    this.$nextTick(function () { if (this.$refs.source) this.$refs.source.focus(); }.bind(this));
                } else {
                    if (editor && this.ready) editor.show();
                    this.loadContent();
                    this.$nextTick(function () { if (editor && this.ready) editor.focus(); }.bind(this));
                }
            },
            mount: function () {
                if (destroyed) return;
                var self = this;
                var tiny = global.hugerte;
                if (!tiny) { self.failed = true; self.sourceMode = true; return; }
                var language = (global.document.documentElement.lang || "zh-CN").toLowerCase();
                tiny.init({
                    target: self.$refs.editor,
                    inline: true,
                    language: language.indexOf("ja") === 0 ? "ja" : (language.indexOf("zh") === 0 ? "zh_CN" : "en"),
                    menubar: false, toolbar: false, statusbar: false,
                    branding: false, promotion: false, contextmenu: false,
                    plugins: "quickbars lists link",
                    quickbars_insert_toolbar: false,
                    quickbars_image_toolbar: false,
                    quickbars_selection_toolbar: "bold italic underline | forecolor backcolor | bullist numlist | descriptionLink removeformat",
                    valid_elements: "p[style],br,strong,b,em,i,u,s,span[style],mark,small,sub,sup,ul,ol[start|type],li,a[href|target|rel|title]",
                    invalid_elements: "script,style,template,iframe,object,embed,svg,math,form,input,button,select,textarea,img,video,audio,source",
                    valid_styles: { "*": "color,background-color,text-decoration" },
                    allow_script_urls: false, allow_html_data_urls: false, allow_unsafe_link_target: false,
                    convert_urls: false, relative_urls: false, paste_data_images: false,
                    link_context_toolbar: false, link_title: false,
                    color_map: ["111827", "Black", "4B5563", "Gray", "2563EB", "Blue", "047857", "Green", "B91C1C", "Red", "FDE68A", "Yellow"],
                    color_cols: 6,
                    setup: function (instance) {
                        if (destroyed) { instance.on("init", function () { instance.remove(); }); return; }
                        editor = instance;
                        instance.ui.registry.addButton("descriptionLink", {
                            icon: "link", tooltip: labels.link,
                            onAction: function () { if (read().allowLinks) instance.execCommand("mceLink"); },
                            onSetup: function (api) {
                                var update = function () { api.setEnabled(!!read().allowLinks); };
                                update(); instance.on("NodeChange", update);
                                return function () { instance.off("NodeChange", update); };
                            }
                        });
                        instance.on("init", function () {
                            if (destroyed) { instance.remove(); return; }
                            self.ready = true;
                            self.loadContent();
                            if (self.sourceMode) instance.hide();
                        });
                        instance.on("input change Undo Redo", function () {
                            if (destroyed || syncing || !self.ready || self.sourceMode) return;
                            var html = instance.getContent();
                            if (html === editorHtml) return;
                            editorHtml = html;
                            if (baseline && html === baseline.html) self.commit(baseline.text, baseline.format);
                            else self.commit(html);
                        });
                    }
                }).then(function (editors) {
                    if (destroyed) editors.forEach(function (item) { item.remove(); });
                }).catch(function () {
                    if (!destroyed) { self.failed = true; self.sourceMode = true; }
                });
            },
            destroy: function () {
                destroyed = true;
                if (editor) { editor.remove(); editor = null; }
            }
        };
    }

    var api = { plainHtml: plainHtml, content: content, create: create };
    global.BloxCompactRichText = api;
    if (typeof module !== "undefined" && module.exports) module.exports = api;
})(typeof window !== "undefined" ? window : globalThis);
