<?php
/**
 * 主题页头/页尾文案（content-fields.json 里 area: header/footer）的面板内编辑。
 * 与版权文字同一模式：不进草稿，保存即全站生效，保存后刷新画布。
 */

declare(strict_types=1);
?>
            themeContentFields(area) {
                return (this.themeContent.fields || []).filter(function (field) { return !area || field.area === area; });
            },
            /** 画布点主题默认页头/页尾：主题声明了这一区的文案就在面板里改，否则仍去原来的编辑入口 */
            openThemeContent(area) {
                if (!this.themeContentFields(area).length) return false;
                this.themeContentOpen = true;
                // 表单在右侧结构面板顶部：宽屏展开收起的右栏，窄屏切到结构抽屉
                if (window.innerWidth < 1440) this.mobilePanel = "structure";
                else this.rightPanelCollapsed = false;
                this.$nextTick(function () {
                    var target = document.querySelector('[data-testid="blox-theme-content-' + area + '"]')
                        || document.querySelector('[data-testid="blox-theme-content"]');
                    if (!target) return;
                    target.scrollIntoView({ block: "nearest" });
                    var input = target.querySelector("input, textarea");
                    if (input) input.focus({ preventScroll: true });
                });
                return true;
            },
            saveThemeContent() {
                if (!this.themeContent.can_edit || this.themeContentSaving || !this.themeContentChanged) return;
                var body = new URLSearchParams();
                body.set("action", "save_theme_content");
                body.set("lang", this.themeContent.language);
                body.set("fingerprint", String(this.themeContent.fingerprint || ""));
                var values = this.themeContent.values || {};
                this.themeContentFields("").forEach(function (field) {
                    body.set("fields[" + field.key + "]", String(values[field.key] ?? ""));
                });
                body.set("_token", this.csrf);
                var self = this;
                this.themeContentSaving = true;
                fetch(this.siteCopyrightEndpoint, { method: "POST", body: body })
                    .then(function (response) { return response.json(); })
                    .then(function (result) {
                        if (!result || Number(result.code) !== 0 || !result.data || !result.data.state) {
                            throw new Error((result && result.msg) || self.themeContentText.failed);
                        }
                        Object.assign(self.themeContent, result.data.state);
                        self.themeContentChanged = false;
                        self.refreshPreview();
                        self.toast(self.themeContentText.saved);
                    })
                    .catch(function (error) { self.toast(error.message || self.themeContentText.failed); })
                    .finally(function () { self.themeContentSaving = false; });
            },
