<?php

declare(strict_types=1);
// 站点资料（版权文字 / 备案号）面板内编辑方法：由 admin/blox_editor.php 的 Alpine 方法表 require。
?>
            /** 控件声明 site_langs 时：语言固定且不在列表内则隐藏（如非简体中文页脚的备案开关） */
            siteLanguageControlApplies(ctrl) {
                if (!Array.isArray(ctrl.site_langs) || !this.siteCopyright.language_fixed) return true;
                return ctrl.site_langs.indexOf(this.siteCopyright.language) !== -1;
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

