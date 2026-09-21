(function (root) {
    "use strict";

    root.YikaiBloxPageSettings = {
        mixin: function (initial) {
            return {
                pageFrameOpen: false,
                // 对话框标记在打开前就会被 Alpine 求值，初始形状必须完整：
                // 只给 {} 会让 dot_nav.position / dot_nav.mobile 这类绑定在初始化时读到
                // undefined 的属性（2026-09-16 回归：控制台刷 Alpine Expression Error）。
                pageFrameDraft: { page_header_hidden: false, page_footer_hidden: false, dot_nav: { enabled: false, position: "right", mobile: false } },
                pageLayout: initial.layout || { values: {}, fields: {} },
                pageFrameModes: {},
                pageFrameError: "",
                pageUrl: initial.url || "",
                pageSlug: initial.slug || "",
                pageSlugDraft: "",
                pageUrlSaving: false,
                pageUrlConfirm: false,
                pageUrlError: "",
                pageUrlText: initial.text || {},
                addElement: function (el, target, tableReady) {
                    if (el.type === 'table' && !tableReady && typeof this.openTableCreate === 'function') {
                        this.openTableCreate(el, target);
                        return;
                    }
                    var before = this.historyData();
                    var outcome = this.runCommand("add-element", function () {
                        this._addElementRaw(el, target);
                    });
                    if (outcome && outcome.ok && this.historyData() !== before) this.rememberRecentElement(el.type);
                    return outcome;
                },
                pageIsPublishedCurrent: function () {
                    // A published revision alone does not mean the current canvas is live.
                    return !!(this.pagePublished && this.publishedDocument
                        && root.BloxDraftSummary && typeof this.draftSummary === "function"
                        && !this.draftSummary().changed);
                },
                pageFrontPreviewUrl: function () {
                    if (!this.pageUrl) return "#";
                    var url = new URL(this.pageUrl, window.location.origin);
                    if (this.pageIsPublishedCurrent()) {
                        url.searchParams.delete("preview");
                        url.searchParams.delete("blox_draft");
                    } else {
                        url.searchParams.set("preview", "draft");
                        url.searchParams.set("blox_draft", "page:" + initial.id);
                    }
                    return url.pathname + url.search + url.hash;
                },
                openPageFrame: function () {
                    this.mobileActionsOpen = false;
                    var dotNav = this.docSettings.dot_nav && typeof this.docSettings.dot_nav === "object"
                        ? this.docSettings.dot_nav : {};
                    this.pageFrameDraft = {
                        page_header_hidden: !!this.docSettings.page_header_hidden,
                        page_footer_hidden: !!this.docSettings.page_footer_hidden,
                        // R7A：圆点导航是布局设置——应用到草稿，发布后生效
                        dot_nav: {
                            enabled: !!dotNav.enabled,
                            position: dotNav.position === "left" ? "left" : "right",
                            mobile: !!dotNav.mobile
                        }
                    };
                    this.pageSlugDraft = this.pageSlug;
                    this.pageFrameError = "";
                    this.pageFrameModes = {};
                    Object.keys(this.pageLayout.fields).forEach((key) => {
                        var has = Object.prototype.hasOwnProperty.call(this.docSettings, key);
                        var value = has ? this.docSettings[key] : this.pageLayout.values[key];
                        this.pageFrameModes[key] = !has ? "inherit" : (value === null ? "clear" : "set");
                        this.pageFrameDraft[key] = value === null ? "#ffffff" : value;
                    });
                    this.pageUrlError = "";
                    this.pageUrlConfirm = false;
                    this.pageFrameOpen = true;
                    this.focusDialog(this.$refs.pageFrameDialog, "[data-dialog-initial]");
                },
                closePageFrame: function () {
                    if (this.pageUrlSaving) return;
                    this.pageFrameOpen = false;
                    this.releaseDialog(this.$refs.pageFrameDialog);
                },
                applyPageFrame: function () {
                    this.pageFrameError = "";
                    for (var key of Object.keys(this.pageLayout.fields)) {
                        var field = this.pageLayout.fields[key];
                        var value = this.pageFrameDraft[key];
                        if (this.pageFrameModes[key] !== "set") continue;
                        if ((field.type === "number" && (!Number.isInteger(Number(value)) || value === "" || Number(value) < field.min || Number(value) > field.max))
                            || (field.type === "color" && !/^#[0-9a-fA-F]{6}$/.test(value))) {
                            this.pageFrameError = this.pageUrlText.layoutInvalid;
                            return;
                        }
                    }
                    this.runCommand("page-frame", function () {
                        Object.assign(this.docSettings, this.pageFrameDraft);
                        Object.keys(this.pageLayout.fields).forEach((key) => {
                            var mode = this.pageFrameModes[key];
                            if (mode === "inherit") delete this.docSettings[key];
                            else if (mode === "clear") this.docSettings[key] = null;
                            else if (this.pageLayout.fields[key].type === "number") this.docSettings[key] = Number(this.pageFrameDraft[key]);
                        });
                        this.markDocumentSettingsChanged();
                        this.schedulePreview();
                    });
                    this.closePageFrame();
                },
                savePageUrl: async function (confirmed) {
                    if (this.pageUrlSaving || this.pageSlugDraft === this.pageSlug) return;
                    this.pageUrlError = "";
                    var slug = this.pageSlugDraft.trim().toLowerCase();
                    if (!/^[a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?$/.test(slug)) {
                        this.pageUrlError = this.pageUrlText.invalid;
                        return;
                    }
                    if (confirmed !== true) {
                        this.pageUrlConfirm = true;
                        return;
                    }
                    this.pageUrlSaving = true;
                    try {
                        var response = await fetch(this.endpoint, {
                            method: "POST",
                            body: new URLSearchParams({ action: "save_page_url", id: String(initial.id), slug: slug, expected_slug: this.pageSlug, _token: this.csrf })
                        });
                        var result = await response.json();
                        if (!response.ok || Number(result.code) !== 0 || !result.data) {
                            throw new Error(result.message || result.msg || this.pageUrlText.failed);
                        }
                        this.pageSlug = result.data.slug;
                        this.pageSlugDraft = result.data.slug;
                        this.pageUrl = result.data.url;
                        this.pageUrlConfirm = false;
                        this.toast(this.pageUrlText.saved);
                        this.schedulePreview();
                    } catch (error) {
                        this.pageUrlError = error.message || this.pageUrlText.failed;
                    } finally {
                        this.pageUrlSaving = false;
                    }
                }
            };
        }
    };
})(window);
