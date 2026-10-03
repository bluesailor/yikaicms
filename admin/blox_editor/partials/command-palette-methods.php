<?php
/**
 * 命令面板（Ctrl+K / ⌘K，2.0.4，借鉴 Avada 7.16）：跳到页面里的元素、插入元素、常用操作、
 * 整页复制粘贴与文字查找替换。纯函数（匹配排序、整页格式、只改文字的替换）在
 * assets/js/blox-command-palette.js，可单测；这里只组装命令清单并调用编辑器已有的方法。
 * 改动文档的命令都走 runCommand，进撤销历史。
 */
$commandPaletteText = [
    'placeholder' => __('blox_cmd_placeholder'),
    'groups' => [
        'actions' => __('blox_cmd_group_actions'),
        'elements' => __('blox_cmd_group_elements'),
        'insert' => __('blox_cmd_group_insert'),
        'page' => __('blox_cmd_group_page'),
    ],
    'save' => __('blox_cmd_save'),
    'publish' => __('blox_cmd_publish'),
    'undo' => __('blox_cmd_undo'),
    'redo' => __('blox_cmd_redo'),
    'preview' => __('blox_cmd_preview'),
    'library' => __('blox_cmd_library'),
    'templates' => __('blox_cmd_templates'),
    'design' => __('blox_cmd_design'),
    'insert' => __('blox_cmd_insert'),
    'copyPage' => __('blox_cmd_copy_page'),
    'pastePage' => __('blox_cmd_paste_page'),
    'replace' => __('blox_cmd_replace'),
    'copied' => __('blox_cmd_copied'),
    'pasteInvalid' => __('blox_cmd_paste_invalid'),
    'pasteDone' => __('blox_cmd_paste_done'),
    'find' => __('blox_cmd_find'),
    'replaceWith' => __('blox_cmd_replace_with'),
    'replaceCount' => __('blox_cmd_replace_count'),
    'replaceApply' => __('blox_cmd_replace_apply'),
    'replaceDone' => __('blox_cmd_replace_done'),
    'empty' => __('blox_cmd_empty'),
    'hint' => __('blox_cmd_hint'),
];
?>
            cmdOpen: false,
            cmdQuery: "",
            cmdIndex: 0,
            cmdMode: "",          // "" = 命令列表；"replace" = 查找替换表单
            cmdFind: "",
            cmdReplace: "",
            cmdText: <?php echo json_encode($commandPaletteText, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,

            openCommandPalette() {
                this.cmdOpen = true;
                this.cmdMode = "";
                this.cmdQuery = "";
                this.cmdIndex = 0;
                var self = this;
                this.$nextTick(function () { if (self.$refs.cmdInput) self.$refs.cmdInput.focus(); });
            },

            closeCommandPalette() {
                this.cmdOpen = false;
                this.cmdMode = "";
            },

            /** 点工具栏上已有的按钮：沿用它自己的确认、校验与保存流程 */
            clickToolbarButton(testid) {
                var button = document.querySelector('[data-testid="' + testid + '"]');
                if (button && !button.disabled && button.getClientRects().length) { button.click(); return true; }
                return false;
            },

            commandActionItems() {
                var self = this, t = this.cmdText, items = [];
                var visible = function (testid) {
                    var el = document.querySelector('[data-testid="' + testid + '"]');
                    return !!(el && !el.disabled && el.getClientRects().length);
                };
                if (visible("blox-save")) items.push({ id: "save", icon: "device-floppy", label: t.save, keywords: "save ctrl+s", run: function () { self.clickToolbarButton("blox-save"); } });
                if (visible("blox-publish-page")) items.push({ id: "publish", icon: "world-upload", label: t.publish, keywords: "publish", run: function () { self.clickToolbarButton("blox-publish-page"); } });
                items.push({ id: "undo", icon: "arrow-back-up", label: t.undo, keywords: "undo ctrl+z", run: function () { self.undo(); } });
                items.push({ id: "redo", icon: "arrow-forward-up", label: t.redo, keywords: "redo ctrl+y", run: function () { self.redo(); } });
                (this.devices || []).forEach(function (device) {
                    items.push({ id: "device:" + device.key, icon: String(device.icon || "device-desktop").replace(/^ti-/, ""), label: t.preview.replace(":device", device.label),
                        keywords: "preview device " + device.key, run: function () { self.previewDevice = device.key; } });
                });
                if (visible("blox-library-open")) items.push({ id: "library", icon: "layout-grid-add", label: t.library, keywords: "add element library", run: function () { self.clickToolbarButton("blox-library-open"); } });
                if (typeof this.openTemplates === "function") items.push({ id: "templates", icon: "template", label: t.templates, keywords: "template section", run: function () { self.openTemplates(); } });
                if (this.canManageDesign && typeof this.openDesignSystem === "function") items.push({ id: "design", icon: "palette", label: t.design, keywords: "design color token style", run: function () { self.openDesignSystem("colors"); } });
                return items.map(function (item) { item.group = "actions"; return item; });
            },

            commandElementItems() {
                var self = this, out = [];
                function walk(el, path) {
                    if (!el || typeof el !== "object") return;
                    var schema = self.elSchema(el.type) || {};
                    out.push({ id: "el:" + path, group: "elements", icon: schema.icon || "square", label: self.elLabel(el),
                        keywords: el.type + " " + (schema.label || ""), run: function () { self.selectPath(path); } });
                    ((el.data || {}).children || []).forEach(function (child, index) { walk(child, path + "." + index); });
                }
                (this.sections || []).forEach(function (section, si) {
                    (section.columns || []).forEach(function (column, ci) {
                        (column.elements || []).forEach(function (el, ei) { walk(el, si + "." + ci + "." + ei); });
                    });
                });
                return out;
            },

            commandInsertItems() {
                var self = this, t = this.cmdText;
                var host = this.selTopEl && this.elSchema(this.selTopEl.type).container ? this.selTopEl : null;
                return (this.elementLib || []).filter(function (el) {
                    if (!el || el.type === "__section") return false;
                    if (host) return self.canNestElement(host, { type: el.type });
                    return el.paletteVisible === true && !el.deprecated;
                }).map(function (el) {
                    return { id: "insert:" + el.type, group: "insert", icon: el.icon || "plus", label: t.insert.replace(":name", el.label),
                        keywords: el.type, locked: !!el.locked, run: function () { self.activatePaletteElement(el); } };
                });
            },

            commandPageItems() {
                var self = this, t = this.cmdText;
                return [
                    { id: "copy-page", group: "page", icon: "copy", label: t.copyPage, keywords: "copy page json", run: function () { self.copyPageToClipboard(); } },
                    { id: "paste-page", group: "page", icon: "clipboard", label: t.pastePage, keywords: "paste page json", run: function () { self.pastePageFromClipboard(); } },
                    { id: "replace", group: "page", icon: "replace", label: t.replace, keywords: "find replace search", keep: true,
                        run: function () { self.cmdMode = "replace"; self.cmdFind = ""; self.cmdReplace = "";
                            self.$nextTick(function () { if (self.$refs.cmdFind) self.$refs.cmdFind.focus(); }); } },
                ];
            },

            /** 无输入时只列操作与整页命令（元素与插入项太多）；有输入时全部参与匹配 */
            commandResults() {
                var query = this.cmdQuery.trim();
                var pool = this.commandActionItems().concat(this.commandPageItems());
                if (query) pool = pool.concat(this.commandElementItems(), this.commandInsertItems());
                return window.BloxCommandPalette.rank(pool, query, 40);
            },

            commandKeydown(event) {
                var results = this.commandResults();
                if (event.key === "ArrowDown") { event.preventDefault(); this.cmdIndex = results.length ? (this.cmdIndex + 1) % results.length : 0; }
                else if (event.key === "ArrowUp") { event.preventDefault(); this.cmdIndex = results.length ? (this.cmdIndex - 1 + results.length) % results.length : 0; }
                else if (event.key === "Enter") { event.preventDefault(); if (results[this.cmdIndex]) this.runPaletteCommand(results[this.cmdIndex]); }
                else if (event.key === "Escape") { event.preventDefault(); this.closeCommandPalette(); }
            },

            runPaletteCommand(item) {
                if (!item) return;
                if (!item.keep) this.closeCommandPalette();
                item.run();
            },

            pageClipboardKey() {
                return this.workspacePrefPrefix + "page-clipboard:v1";
            },

            copyPageToClipboard() {
                var text = window.BloxCommandPalette.serializePage(JSON.parse(JSON.stringify(this.sections || [])));
                // 同浏览器另一个编辑器也能粘贴：系统剪贴板不可用（非 https 等）时退回本地存储
                try { window.localStorage.setItem(this.pageClipboardKey(), text); } catch (error) {}
                var self = this;
                var done = function () { self.toast(self.cmdText.copied); };
                if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done, done);
                else done();
            },

            pastePageFromClipboard() {
                var self = this;
                var apply = function (text) {
                    var sections = window.BloxCommandPalette.parsePage(text);
                    if (!sections) {
                        try { sections = window.BloxCommandPalette.parsePage(window.localStorage.getItem(self.pageClipboardKey())); } catch (error) { sections = null; }
                    }
                    if (!sections) { self.toast(self.cmdText.pasteInvalid); return; }
                    // 追加到页面末尾，全部换新 ID；保存时照常走服务端的能力与保护字段校验
                    self.runCommand("paste-page", function () {
                        sections.forEach(function (section) {
                            var copy = JSON.parse(JSON.stringify(section));
                            copy.id = self.uid("s");
                            (copy.columns || []).forEach(function (column) {
                                column.id = self.uid("c");
                                column.elements = (column.elements || []).map(function (el) { return self.deepCloneNode(el, "e"); });
                            });
                            self.sections.push(copy);
                        });
                    });
                    self.toast(self.cmdText.pasteDone.replace(":n", sections.length));
                };
                if (navigator.clipboard && navigator.clipboard.readText) navigator.clipboard.readText().then(apply, function () { apply(""); });
                else apply("");
            },

            /** 遍历区块标题与全部元素的可见文字；dryRun 只计数 */
            replaceAcrossPage(dryRun) {
                var find = this.cmdFind, replacement = this.cmdReplace, total = 0;
                if (!find) return 0;
                (this.sections || []).forEach(function (section) {
                    var head = { title: section.title, subtitle: section.subtitle };
                    var n = window.BloxCommandPalette.replaceInData(head, find, replacement, dryRun);
                    if (n && !dryRun) {
                        if (typeof section.title === "string") section.title = head.title;
                        if (typeof section.subtitle === "string") section.subtitle = head.subtitle;
                    }
                    total += n;
                    (section.columns || []).forEach(function (column) {
                        (column.elements || []).forEach(function (el) {
                            if (el && el.data) total += window.BloxCommandPalette.replaceInData(el.data, find, replacement, dryRun);
                        });
                    });
                });
                return total;
            },

            replaceMatchCount() {
                return this.replaceAcrossPage(true);
            },

            applyReplace() {
                if (!this.cmdFind || this.replaceMatchCount() === 0) return;
                var self = this, total = 0;
                this.runCommand("find-replace", function () { total = self.replaceAcrossPage(false); });
                this.toast(this.cmdText.replaceDone.replace(":n", total));
                this.closeCommandPalette();
            },
