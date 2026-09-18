<?php

declare(strict_types=1);
// 站点资料（版权文字 / 备案号）面板内编辑方法：由 admin/blox_editor.php 的 Alpine 方法表 require。
?>
            /**
             * 站点资料类控件的显示规则：
             * - site_langs：语言固定且不在列表内则隐藏（如非简体中文页脚的备案开关）；
             * - legacy_filing：版权元素的旧备案开关，两项都关闭后隐藏，备案改用独立元素。
             */
            siteLanguageControlApplies(ctrl) {
                if (ctrl.legacy_filing && !this.copyrightHasFiling()) return false;
                if (!Array.isArray(ctrl.site_langs) || !this.siteCopyright.language_fixed) return true;
                return ctrl.site_langs.indexOf(this.siteCopyright.language) !== -1;
            },

            /** 选中的版权元素是否仍在输出备案号（旧数据缺省视为开启） */
            copyrightHasFiling() {
                var el = this.selEl;
                if (!el || el.type !== "site-copyright") return false;
                var on = function (key) {
                    return !Object.prototype.hasOwnProperty.call(el.data || {}, key)
                        || [false, 0, "0", "", null].indexOf(el.data[key]) === -1;
                };
                return on("show_icp") || on("show_police");
            },

            /** 把旧版「版权 + 备案」拆成两个同级元素：备案元素紧跟其后，沿用对齐与色调，可再单独排版 */
            splitCopyrightFiling() {
                var el = this.selEl, path = this.selectedPath();
                var lib = this.elementLib.find(function (item) { return item.type === "site-filing"; });
                if (!el || el.type !== "site-copyright" || !path || !lib || !this.copyrightHasFiling()) return;
                var on = function (key) {
                    return !Object.prototype.hasOwnProperty.call(el.data || {}, key)
                        || [false, 0, "0", "", null].indexOf(el.data[key]) === -1;
                };
                var parts = path.split(".");
                this.flushHistory(true);
                this.runCommand("split-copyright-filing", function () {
                    var node = this.newElementNode(lib);
                    node.data.show_icp = on("show_icp");
                    node.data.show_police = on("show_police");
                    node.data.align = el.data.align || "left";
                    node.data.tone = el.data.tone || "dark";
                    el.data.show_icp = false;
                    el.data.show_police = false;
                    this.insertElementAt(node, { kind: "element", sec: parts[0], col: parts[1], path: path, position: "after" }, lib.label);
                });
                this.flushHistory(true);
            },
            /** 备案号是全站单值：只有固定为非简体中文的模板/页面才隐藏，共享模板始终可改 */
            siteCopyrightFilingEditable() {
                return !!this.siteCopyright.filing || !this.siteCopyright.language_fixed;
            },

            saveSiteCopyright() {
                if (!this.siteCopyright.can_edit || this.siteCopyrightSaving || !this.siteCopyrightChanged) return;
                var body = new URLSearchParams();
                body.set("action", "save_copyright");
                body.set("lang", this.siteCopyright.language);
                body.set("copyright", String(this.siteCopyright.copyright || ""));
                if (this.siteCopyrightFilingEditable()) {
                    body.set("icp", String(this.siteCopyright.icp || ""));
                    body.set("police", String(this.siteCopyright.police || ""));
                }
                body.set("_token", this.csrf);
                var self = this;
                this.siteCopyrightSaving = true;
                fetch(this.siteCopyrightEndpoint, { method: "POST", body: body })
                    .then(function (response) { return response.json(); })
                    .then(function (result) {
                        if (!result || Number(result.code) !== 0 || !result.data || !result.data.state) {
                            throw new Error((result && result.msg) || self.siteCopyrightText.failed);
                        }
                        Object.assign(self.siteCopyright, result.data.state);
                        self.siteCopyrightChanged = false;
                        self.refreshPreview();
                        self.toast(self.siteCopyrightText.saved);
                    })
                    .catch(function (error) { self.toast(error.message || self.siteCopyrightText.failed); })
                    .finally(function () { self.siteCopyrightSaving = false; });
            },

