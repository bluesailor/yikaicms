<?php
/**
 * 建站人员的高级配置方法（V2.0.0）：元素 ID / CSS 类 / 属性 / 自定义 CSS，页面 CSS 对话框，页面切换器。
 * 归属 bloxEditor() 数据对象；数据校验以服务端 BloxCustomCode 为准，这里只做即时提示与写入。
 */
?>
            // ── 元素「高级」面板 ──────────────────────────────────────
            advancedIdKey() {
                // 标题本来就有「锚点 ID」控件（html_id），沿用它，不再另存一份
                var controls = this.selEl ? (this.elSchema(this.selEl.type).controls || []) : [];
                return controls.some(function (control) { return control.key === "html_id"; }) ? "html_id" : "_html_id";
            },

            advancedHasValues() {
                var data = this.selEl && this.selEl.data ? this.selEl.data : {};
                return !!(data._html_id || data._css_classes || data._custom_css
                    || (Array.isArray(data._attributes) && data._attributes.length));
            },

            advancedIdInvalid() {
                var value = this.selEl && this.selEl.data ? String(this.selEl.data[this.advancedIdKey()] || "") : "";
                return value !== "" && !/^[A-Za-z][A-Za-z0-9_-]{0,63}$/.test(value);
            },

            setAdvancedValue(key, value) {
                if (!this.selEl) return;
                var text = typeof value === "string" ? value.trim() : "";
                if (key === "_css_classes") text = window.BloxCustomCode ? window.BloxCustomCode.classList(text) : text;
                if (text === "") delete this.selEl.data[key];
                else this.selEl.data[key] = text;
            },

            advancedAttributes() {
                var list = this.selEl && Array.isArray(this.selEl.data._attributes) ? this.selEl.data._attributes : [];
                return list;
            },

            addAdvancedAttribute() {
                if (!this.selEl) return;
                var list = this.advancedAttributes().slice();
                if (list.length >= 10) return;
                list.push({ name: "", value: "" });
                this.selEl.data._attributes = list;
            },

            setAdvancedAttribute(index, field, value) {
                if (!this.selEl) return;
                var list = this.advancedAttributes().map(function (item) { return Object.assign({}, item); });
                if (!list[index]) return;
                list[index][field] = field === "name" ? String(value || "").trim().toLowerCase() : String(value || "");
                this.selEl.data._attributes = list;
            },

            removeAdvancedAttribute(index) {
                if (!this.selEl) return;
                var list = this.advancedAttributes().filter(function (item, position) { return position !== index; });
                if (list.length) this.selEl.data._attributes = list;
                else delete this.selEl.data._attributes;
            },

            // ── 区块「高级」面板：ID 复用区块锚点 anchor_id，类与自定义 CSS 存在区块 settings ──
            sectionAdvancedHasValues() {
                var settings = this.sel && this.sel.settings ? this.sel.settings : {};
                return !!(settings._css_classes || settings._custom_css);
            },

            setSectionAdvancedValue(key, value) {
                if (!this.sel || !this.sel.settings) return;
                var text = typeof value === "string" ? value.trim() : "";
                if (key === "_css_classes") text = window.BloxCustomCode ? window.BloxCustomCode.classList(text) : text;
                if (text === "") delete this.sel.settings[key];
                else this.sel.settings[key] = text;
            },

            customCssError(value, max) {
                return window.BloxCustomCode ? window.BloxCustomCode.check(value, max) : "";
            },

            customCssErrorText(value, max) {
                var code = this.customCssError(value, max);
                return code ? (this.customCssErrors[code] || code) : "";
            },

            // ── 页面 CSS 对话框 ───────────────────────────────────────
            openPageCode() {
                this.pageCodeDraft = String((this.docSettings && this.docSettings.custom_css) || "");
                this.pageCodeOpen = true;
                this.focusDialog(this.$refs.pageCodeDialog, "textarea");
            },

            closePageCode() {
                if (!this.pageCodeOpen) return;
                this.pageCodeOpen = false;
                this.releaseDialog(this.$refs.pageCodeDialog);
            },

            applyPageCode() {
                if (!this.canManageDesign || this.customCssError(this.pageCodeDraft, 20000)) return;
                var css = String(this.pageCodeDraft || "").trim();
                if (!this.docSettings || typeof this.docSettings !== "object") this.docSettings = {};
                if (css === (this.docSettings.custom_css || "")) {
                    this.closePageCode();
                    return;
                }
                if (css === "") delete this.docSettings.custom_css;
                else this.docSettings.custom_css = css;
                // 文档设置不在 sections 监听里：与吸顶等设置同样显式记脏、进历史并刷新画布
                this.markDocumentSettingsChanged();
                this.refreshPreview();
                this.closePageCode();
            },

            // ── 页面切换器 ────────────────────────────────────────────
            pageSwitcherMatches() {
                var query = String(this.pageSwitcherQuery || "").trim().toLowerCase();
                return (this.pageSwitcherItems || []).filter(function (item) {
                    return !query || String(item.name).toLowerCase().indexOf(query) !== -1
                        || String(item.slug || "").toLowerCase().indexOf(query) !== -1;
                });
            },
