<?php
declare(strict_types=1);
?>
            areaPresetLabel(preset) {
                if (!preset) return "";
                return (preset.number > 0 ? String(preset.number).padStart(2, "0") + " · " : "") + (preset.name || "");
            },

            focusDialog(root, initialSelector) {
                var self = this;
                this.$nextTick(function () {
                    if (window.BloxDialogFocus) window.BloxDialogFocus.open(root, initialSelector || "");
                });
            },

            releaseDialog(root) {
                this.$nextTick(function () {
                    if (window.BloxDialogFocus) window.BloxDialogFocus.close(root);
                });
            },

            dialogKeydown(event, root, onEscape) {
                if (window.BloxDialogFocus) window.BloxDialogFocus.keydown(event, root, onEscape);
            },

            templateDialogKeydown(event) {
                if (this.templateSectionsDocked()) {
                    if (event && event.key === "Escape") {
                        event.preventDefault();
                        event.stopPropagation();
                        this.closeTemplates();
                    }
                    return;
                }
                this.dialogKeydown(event, this.$refs.templateDialog, () => this.closeTemplates());
            },

            /**
             * 关闭模板面板 = 取消未消费的定点插入意图（审计 r17-1：Esc/遮罩/关闭按钮
             * 此前只收面板不清 _insertAt，取消后从常规入口添加会落到旧边界）。
             * 成功插入也会走此处，因此先保存预制区块库上下文，再统一收起面板和清理落点。
             */
            closeTemplates() {
                if (!this.templateOpen) return;
                var root = this.$refs.templateDialog;
                this.finishTemplatePanelResize();
                if (this.templateDragItem) this.finishPaletteDrag();
                this.persistTemplateSectionViewState();
                this.templateOpen = false;
                this._insertAt = null;
                this.releaseDialog(root);
            },

            openTemplateDialog() {
                var alreadyOpen = this.templateOpen;
                this.templateOpen = true;
                if (!alreadyOpen) this.focusDialog(this.$refs.templateDialog, "[data-dialog-initial]");
                if (!this.templateLoaded) this.loadTemplates();
            },

            openTemplates() {
                this.persistTemplateSectionViewState();
                this.templateEntry = "all";
                this.templateFilter = "all";
                this.templateCategory = "all";
                this.templatePurpose = "all";
                this.templateQuickFilter = "all";
                this.templateQuery = "";
                this.openTemplateDialog();
            },

            openHeaderPresets() {
                if (!this.areaTemplateMode || this.headerPresets.length === 0) return;
                var current = this.headerPresets.find(function (preset) {
                    return this.isCurrentHeaderPreset(preset);
                }, this);
                this.selectedHeaderPresetSlug = (current || this.headerPresets[0]).slug;
                this.headerPresetOpen = true;
                this.focusDialog(this.$refs.headerPresetDialog, "[data-dialog-initial]");
            },

            closeHeaderPresets() {
                if (!this.headerPresetOpen) return;
                var root = this.$refs.headerPresetDialog;
                if (this.headerPresetPreviewOpen) this.closeHeaderPresetPreview();
                this.headerPresetOpen = false;
                this.releaseDialog(root);
            },

            headerPresetDocument(preset) {
                return {
                    settings: (preset && preset.settings) || {},
                    sections: (preset && preset.sections) || [],
                };
            },

            isCurrentHeaderPreset(preset) {
                if (!preset || !window.BloxTemplateLibrary
                    || typeof window.BloxTemplateLibrary.documentFingerprint !== "function") return false;
                var fingerprint = window.BloxTemplateLibrary.documentFingerprint;
                return fingerprint(this.headerPresetDocument(preset)) === fingerprint({
                    settings: this.docSettings || {},
                    sections: this.sections || [],
                });
            },

            selectHeaderPreset(preset) {
                if (preset && preset.slug) this.selectedHeaderPresetSlug = preset.slug;
            },

            previewHeaderPreset(preset) {
                if (!preset || !preset.slug) return;
                this.selectHeaderPreset(preset);
                this.headerPresetPreviewDevice = "desktop";
                this.headerPresetPreviewState = "normal";
                this.headerPresetPreviewDrawerOpen = false;
                this.headerPresetPreviewLoading = true;
                this.headerPresetPreviewNonce += 1;
                this.headerPresetPreviewOpen = true;
                this.focusDialog(this.$refs.headerPresetPreviewDialog, "[data-dialog-initial]");
            },

            headerPresetPreviewUrl(preset) {
                if (!preset || !/^[a-z0-9-]{1,80}$/.test(String(preset.slug || ""))) return "about:blank";
                var area = this.areaPresetType === "footer" ? "footer" : "header";
                var url = "/admin/blox_preview.php?home=1&template_area=" + area + "&area_preset="
                    + encodeURIComponent(preset.slug);
                var previewLanguage = this.areaLanguage;
                if (previewLanguage) url += "&_lang=" + encodeURIComponent(previewLanguage);
                url += "&preview_instance=" + this.headerPresetPreviewNonce;
                if (area === "header") url += "&header_state=" + encodeURIComponent(this.headerPresetPreviewState);
                if (area === "header" && this.headerPresetPreviewDevice === "mobile" && this.headerPresetPreviewDrawerOpen) {
                    url += "&drawer_open=1";
                }
                if (this.previewContext && this.previewContext !== "home") {
                    url += "&preview_context=" + encodeURIComponent(this.previewContext);
                }
                return url;
            },

            setHeaderPresetPreviewDevice(device) {
                if (device !== "desktop" && device !== "mobile") return;
                this.headerPresetPreviewDevice = device;
                if (device !== "mobile") this.headerPresetPreviewDrawerOpen = false;
                this.reloadHeaderPresetPreview();
            },

            setHeaderPresetPreviewState(state) {
                if (this.areaPresetType !== "header") return;
                if (!["normal", "overlay", "stuck"].includes(state)) return;
                this.headerPresetPreviewState = state;
                this.reloadHeaderPresetPreview();
            },

            toggleHeaderPresetPreviewDrawer() {
                if (this.areaPresetType !== "header" || this.headerPresetPreviewDevice !== "mobile") return;
                this.headerPresetPreviewDrawerOpen = !this.headerPresetPreviewDrawerOpen;
                this.reloadHeaderPresetPreview();
            },

            reloadHeaderPresetPreview() {
                if (!this.headerPresetPreviewOpen) return;
                this.headerPresetPreviewLoading = true;
                this.headerPresetPreviewNonce += 1;
            },

            closeHeaderPresetPreview() {
                if (!this.headerPresetPreviewOpen) return;
                var root = this.$refs.headerPresetPreviewDialog;
                this.headerPresetPreviewOpen = false;
                this.releaseDialog(root);
            },

            selectedHeaderPreset() {
                var slug = this.selectedHeaderPresetSlug;
                return this.headerPresets.find(function (preset) { return preset.slug === slug; })
                    || this.headerPresets[0]
                    || null;
            },

            selectAdjacentHeaderPreset(offset) {
                if (!this.headerPresets.length) return;
                var current = this.headerPresets.findIndex(function (preset) {
                    return preset.slug === this.selectedHeaderPresetSlug;
                }, this);
                var next = (Math.max(0, current) + offset + this.headerPresets.length) % this.headerPresets.length;
                this.selectedHeaderPresetSlug = this.headerPresets[next].slug;
                this.headerPresetPreviewDrawerOpen = false;
                this.reloadHeaderPresetPreview();
            },

            headerPresetComparison(preset) {
                var label = function (type, count) {
                    return (this.elSchema(type).label || type) + (count > 1 ? " ×" + count : "");
                }.bind(this);
                return window.BloxTemplateLibrary.compareSections(this.sections || [], (preset && preset.sections) || [], label);
            },

            headerPresetWarnings(preset) {
                var counts = window.BloxTemplateLibrary.elementCounts((preset && preset.sections) || []);
                var warnings = [];
                if (counts.logo && !this.headerPresetSiteData.logo) warnings.push(this.headerPresetText.missingLogo);
                if ((counts.nav || counts["nav-mega"] || counts["nav-drawer"]) && !this.headerPresetSiteData.navigation) {
                    warnings.push(this.headerPresetText.missingNavigation);
                }
                if (counts["language-switcher"] && !this.headerPresetSiteData.languages) {
                    warnings.push(this.headerPresetText.missingLanguages);
                }
                if (counts["site-contact"] && !this.headerPresetSiteData.contact) {
                    warnings.push(this.headerPresetText.missingContact);
                }
                if (counts["social-links"] && !this.headerPresetSiteData.social) {
                    warnings.push(this.headerPresetText.missingSocial);
                }
                return warnings;
            },

            headerPresetFocusTypes(preset) {
                var counts = window.BloxTemplateLibrary.elementCounts((preset && preset.sections) || []);
                var candidates = this.areaPresetType === "footer"
                    ? ["logo", "nav", "site-contact", "site-search", "social-links", "site-copyright", "site-filing"]
                    : ["logo", counts["nav-mega"] ? "nav-mega" : "nav", "site-search", "language-switcher"];
                return candidates
                    .filter(function (type, index, all) { return counts[type] && all.indexOf(type) === index; });
            },

            focusFirstHeaderElement(type) {
                for (var si = 0; si < this.sections.length; si++) {
                    var columns = this.sections[si].columns || [];
                    for (var ci = 0; ci < columns.length; ci++) {
                        var elements = columns[ci].elements || [];
                        for (var ei = 0; ei < elements.length; ei++) {
                            if (elements[ei].type === type) {
                                this.selectElement(si, ci, ei);
                                return true;
                            }
                            var children = elements[ei].data && Array.isArray(elements[ei].data.children)
                                ? elements[ei].data.children : [];
                            var childIndex = children.findIndex(function (child) { return child.type === type; });
                            if (childIndex >= 0) {
                                this.selectChild(si, ci, ei, childIndex);
                                return true;
                            }
                        }
                    }
                }
                return false;
            },

            saveHeaderAsLocalStyle() {
                if (!this.areaTemplateMode) return;
                var suggested = <?php echo json_encode((string) ($page['name'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
                    + " - " + this.headerPresetText.localCopySuffix;
                var name = window.prompt(this.headerPresetText.saveLocalName, suggested);
                if (!name || !String(name).trim()) return;
                var self = this;
                var body = new URLSearchParams({
                    action: "save_area_copy",
                    type: this.areaPresetType,
                    name: String(name).trim(),
                    blocks_data: this.documentData(),
                    _token: this.csrf,
                });
                fetch("/admin/blox_template_api.php", { method: "POST", body: body })
                    .then(function (response) { return response.json(); })
                    .then(function (result) {
                        self.toast(Number(result.code) === 0 ? self.headerPresetText.saveLocalDone : (result.msg || self.uiText.saveFailed));
                    })
                    .catch(function () { self.toast(self.uiText.saveFailed); });
            },

            applyHeaderPreset(preset, focusType) {
                if (!this.areaTemplateMode || !preset || !Array.isArray(preset.sections)
                    || preset.sections.length === 0) return;
                var self = this;
                var applied = this.commandRunner().execute("apply-area-preset", function () {
                    var fresh = window.BloxTemplateLibrary.freshSections(
                        preset.sections,
                        function (prefix) { return self.uid(prefix); }
                    );
                    self.sections.splice.apply(self.sections, [0, self.sections.length].concat(fresh));
                    self.docSettings = JSON.parse(JSON.stringify(preset.settings || {}));
                    if (self.areaPresetType === "header") self.normalizeHeaderSettings();
                    self.selectedSi = fresh.length > 0 ? 0 : -1;
                    self.selectedCi = -1;
                    self.selectedEi = -1;
                    self.selectedSubEi = -1;
                    self.selLayer = fresh.length > 0 ? "sec" : "";
                    self.closeHeaderPresets();
                });
                if (!applied.ok) return;
                this.toast(this.headerPresetText.applied.replace(":name", this.areaPresetLabel(preset)));
                if (focusType) {
                    var self = this;
                    this.$nextTick(function () { self.focusFirstHeaderElement(focusType); });
                }
            },

            openPrebuiltSections() {
                this.templateEntry = "sections";
                this.templateFilter = "section";
                this.restoreTemplateSectionViewState();
                this.openTemplateDialog();
                if (this.templateLoaded) {
                    this.normalizeTemplateSectionViewState();
                    this.restoreTemplateSectionScroll();
                }
            },

            templateSectionsDocked() {
                this.canvasViewportTick;
                return this.templateEntry === "sections" && window.innerWidth >= 1200 && !this.paletteTapMode;
            },

            templatePanelMaximum() {
                return Math.min(
                    this.templatePanelMax,
                    Math.max(this.templatePanelMin, window.innerWidth - 720)
                );
            },

            templatePanelCurrentWidth() {
                var width = Number(this.templatePanelWidth);
                if (!Number.isFinite(width)) width = 520;
                return Math.round(Math.max(this.templatePanelMin, Math.min(this.templatePanelMaximum(), width)));
            },

            templatePanelStyle() {
                this.canvasViewportTick;
                var maxHeight = this.templateSectionsDocked()
                    ? "max-height:calc(100vh - 3.5rem);"
                    : "max-height:calc(100vh - 4rem);";
                return this.templateSectionsDocked()
                    ? maxHeight + "width:" + this.templatePanelCurrentWidth() + "px;"
                    : maxHeight;
            },

            restoreTemplatePanelWidth() {
                var stored = this.readWorkspacePref("template-panel-width", this.templatePanelStorageKey);
                if (stored !== null && Number.isFinite(Number(stored))) {
                    this.templatePanelWidth = Math.round(Math.max(this.templatePanelMin, Math.min(this.templatePanelMax, Number(stored))));
                }
            },

            persistTemplatePanelWidth() {
                this.writeWorkspacePref("template-panel-width", this.templatePanelWidth, this.templatePanelStorageKey);
            },

            /** 工作区偏好键（站点 + 账号隔离）。 */
            workspacePrefKey(name) {
                return (this.workspacePrefPrefix || "yikai:blox:ws:v2::") + name;
            },

            /**
             * 读工作区偏好：先读隔离键，缺省时只读回退旧版全局键（升级不丢已调好的宽度）。
             * 存储不可用（隐私模式/被策略禁用）时返回 null，交由各 restore 用默认值兜底。
             */
            readWorkspacePref(name, legacyKey) {
                try {
                    var scoped = window.localStorage.getItem(this.workspacePrefKey(name));
                    if (scoped !== null) return scoped;
                    return legacyKey ? window.localStorage.getItem(legacyKey) : null;
                } catch (error) {
                    return null;
                }
            },

            /** 写工作区偏好：只写隔离键；存储不可用时保留本次会话内的值，不抛错。 */
            writeWorkspacePref(name, value, legacyKey) {
                try {
                    window.localStorage.setItem(this.workspacePrefKey(name), String(value));
                } catch (error) {
                    // 存储不可用：本次会话内仍然生效，不影响编辑
                }
            },


            /**
             * 恢复工作区：只把面板显隐/宽度复位并清掉本作用域偏好键，
             * 不触碰文档 JSON、设计内容或保存状态（恢复后 dirty 不应变化）。
             */
            /**
             * 恢复工作区：把本作用域的面板偏好写回默认值，**不删共享旧键**。
             *
             * TASK-002-R01：旧版全局键是所有账号共用的回退来源；此前这里连它一起删，
             * 会让"恢复工作区"越过本账号作用域、改掉别的账号下次读到的宽度。
             * 现在改为把默认值写进本作用域键——本账号从此不再回退旧键，别人的旧键原样保留。
             */
            restoreWorkspace() {
                var self = this;
                this.leftPanelWidth = 288;
                this.leftPanelCollapsed = false;
                this.rightPanelWidth = 256;
                this.rightPanelCollapsed = false;
                this.templatePanelWidth = 520;
                this.writeWorkspacePref("left-panel-width", 288, this.leftPanelStorageKey);
                this.writeWorkspacePref("left-panel-collapsed", "0", this.leftPanelCollapsedStorageKey);
                this.writeWorkspacePref("right-panel-width", 256, this.rightPanelStorageKey);
                this.writeWorkspacePref("right-panel-collapsed", "0", this.rightPanelCollapsedStorageKey);
                this.writeWorkspacePref("template-panel-width", 520, this.templatePanelStorageKey);
                if (typeof this.toast === "function") this.toast(this.uiText.workspaceRestored);
            },

            setTemplatePanelWidth(value, persist) {
                var width = Number(value);
                if (!Number.isFinite(width)) width = 520;
                this.templatePanelWidth = Math.round(Math.max(this.templatePanelMin, Math.min(this.templatePanelMaximum(), width)));
                this.canvasViewportTick++;
                if (persist !== false) this.persistTemplatePanelWidth();
            },

            startTemplatePanelResize(event) {
                if (!this.templateSectionsDocked() || !event || event.button !== 0) return;
                event.preventDefault();
                this.templatePanelResizing = true;
                this._templatePanelPointerId = event.pointerId;
                this._templatePanelResizeStartX = event.clientX;
                this._templatePanelResizeStartWidth = this.templatePanelCurrentWidth();
                document.body.classList.add("blox-panel-resizing");
                if (event.currentTarget && typeof event.currentTarget.setPointerCapture === "function") {
                    event.currentTarget.setPointerCapture(event.pointerId);
                }
            },

            resizeTemplatePanel(event) {
                if (!this.templatePanelResizing || !event) return;
                if (this._templatePanelPointerId !== null && event.pointerId !== this._templatePanelPointerId) return;
                this.setTemplatePanelWidth(
                    this._templatePanelResizeStartWidth + event.clientX - this._templatePanelResizeStartX,
                    false
                );
            },

            finishTemplatePanelResize(event) {
                if (!this.templatePanelResizing) return;
                if (event && this._templatePanelPointerId !== null && event.pointerId !== this._templatePanelPointerId) return;
                this.templatePanelResizing = false;
                this._templatePanelPointerId = null;
                document.body.classList.remove("blox-panel-resizing");
                this.persistTemplatePanelWidth();
            },

            resizeTemplatePanelBy(delta) {
                this.setTemplatePanelWidth(this.templatePanelCurrentWidth() + Number(delta || 0));
            },

            resetTemplatePanelWidth() {
                this.setTemplatePanelWidth(520);
            },

            templateCompactSections() {
                return this.templateEntry === "sections" && this.templateDensity === "compact";
            },

            templateSectionDraggable(item) {
                return this.templateSectionsDocked() && item && item.type === "section"
                    && !item.locked && this.templateInserting === "";
            },

            openPageTemplates() {
                this.persistTemplateSectionViewState();
                this.templateEntry = "pages";
                this.templateScope = "local";
                this.templateFilter = "page";
                this.templateCategory = "page";
                this.templateQuery = "";
                this.openTemplateDialog();
            },

            startBlankPage() {
                if (!this.pageMode || this.templateInserting) return;
                if (this.sections.length > 0 && !window.confirm(this.templateText.blankPageConfirm)) return;
                var self = this;
                var applied = this.commandRunner().execute("blank-page", function () {
                    var blank = {
                        id: self.uid("s"),
                        type: "section",
                        settings: {},
                        columns: [{ id: self.uid("c"), span: 12, settings: {}, elements: [] }],
                    };
                    self.sections.splice.apply(self.sections, [0, self.sections.length, blank]);
                    self.docSettings = {};
                    self.legacyPageContent = false;
                    self.selectedSi = 0;
                    self.selectedCi = 0;
                    self.selectedEi = -1;
                    self.selectedSubEi = -1;
                    self.selLayer = "col";
                    self.closeTemplates();
                });
                if (applied.ok) this.toast(this.templateText.blankPageDone);
            },

            restorePublishedPage() {
                if (!this.pageMode || !this.pagePublished || this.templateInserting) return;
                if (!window.confirm(this.templateText.restorePublishedConfirm)) return;
                var self = this;
                var applied = this.commandRunner().execute("restore-published-page", function () {
                    var published = self.publishedDocument && typeof self.publishedDocument === "object"
                        ? JSON.parse(JSON.stringify(self.publishedDocument))
                        : { settings: {}, sections: [] };
                    var sections = Array.isArray(published.sections) ? published.sections : [];
                    self.sections.splice.apply(self.sections, [0, self.sections.length].concat(sections));
                    self.docSettings = published.settings && typeof published.settings === "object" ? published.settings : {};
                    self.legacyPageContent = false;
                    self.selectedSi = sections.length > 0 ? 0 : -1;
                    self.selectedCi = -1;
                    self.selectedEi = -1;
                    self.selectedSubEi = -1;
                    self.selLayer = sections.length > 0 ? "sec" : "";
                    self.closeTemplates();
                });
                if (applied.ok) this.toast(this.templateText.restorePublishedDone);
            },

            loadTemplates(force) {
                if (this.templateLoading) {
                    if (force) this.templateReloadPending = true;
                    return;
                }
                if (this.templateLoaded && !force) return;
                var self = this;
                this.templateLoading = true;
                this.templateError = "";
                this.templateRemoteError = "";
                var context = this.homeMode ? "home" : "page";
                window.BloxTemplateLibrary.list(
                    "/admin/blox_template_api.php",
                    context,
                    this.templateText.loadFailed,
                    !!force
                )
                    .then(function (items) {
                        self.templateItems = items;
                        self.templateRemoteError = String(items.remoteError || "");
                        self.templateLoaded = true;
                        if (self.templateEntry === "sections") {
                            self.normalizeTemplateSectionViewState();
                            self.restoreTemplateSectionScroll();
                        }
                    })
                    .catch(function (error) {
                        // 刷新失败时保留已显示的本地目录，尤其不能抹掉刚另存成功的模板。
                        if (!self.templateLoaded) {
                            self.templateItems = [];
                            self.templateRemoteError = "";
                        }
                        self.templateError = error.message || self.templateText.loadFailed;
                    })
                    .finally(function () {
                        self.templateLoading = false;
                        if (self.templateReloadPending) {
                            self.templateReloadPending = false;
                            self.loadTemplates(true);
                        }
                    });
            },

            filteredTemplates() {
                var items = window.BloxTemplateLibrary.filter(
                    this.scopedTemplates(),
                    this.templateQuery,
                    this.templateFilter,
                    "all",
                    this.templateCategory,
                    this.templatePurpose,
                    this.templateDataSource
                );
                if (this.templateEntry !== "sections") return items;
                var self = this;
                if (this.templateQuickFilter === "recommended") {
                    return window.BloxTemplateLibrary.recommend(items, this.templatePageIntent);
                }
                if (this.templateQuickFilter === "favorites") {
                    return items.filter(function (item) { return self.isTemplateFavorite(item.key); });
                }
                if (this.templateQuickFilter === "recent") {
                    return items.filter(function (item) { return self.isTemplateRecent(item.key); });
                }
                return items.map(function (item, index) {
                    return {
                        item: item,
                        index: index,
                        rank: self.isTemplateFavorite(item.key) ? 0 : (self.isTemplateRecent(item.key) ? 1 : 2),
                    };
                }).sort(function (a, b) {
                    return a.rank === b.rank ? a.index - b.index : a.rank - b.rank;
                }).map(function (entry) { return entry.item; });
            },

            scopedTemplates() {
                return window.BloxTemplateLibrary.scope(this.templateItems, this.templateScope);
            },

            templateEntryItems(items) {
                var source = Array.isArray(items) ? items : this.scopedTemplates();
                return window.BloxTemplateLibrary.filter(source, "", this.templateFilter, "all", "all");
            },

            templateQuickCount(mode) {
                var self = this;
                var items = this.templateEntryItems();
                if (mode === "recommended") return window.BloxTemplateLibrary.recommend(items, this.templatePageIntent).length;
                if (mode === "favorites") return items.filter(function (item) { return self.isTemplateFavorite(item.key); }).length;
                if (mode === "recent") return items.filter(function (item) { return self.isTemplateRecent(item.key); }).length;
                return items.length;
            },

            templateEmptyReason() {
                if (this.templateEntry === "sections") {
                    if (String(this.templateQuery || "").trim()) return "search";
                    if (this.templateQuickFilter === "recommended") return "recommended";
                    if (this.templateQuickFilter === "favorites") return "favorites";
                    if (this.templateQuickFilter === "recent") return "recent";
                    if (this.templateCategory !== "all") return "category";
                    if (this.templatePurpose !== "all") return "category";
                    if (this.templateDataSource !== "all") return "category";
                }
                return this.templateScope === "remote" ? "remote" : "local";
            },

            templateEmptyMessage() {
                var reason = this.templateEmptyReason();
                if (reason === "search") {
                    return this.templateText.emptySearch.replace(":query", String(this.templateQuery || "").trim());
                }
                if (reason === "favorites") return this.templateText.emptyFavorites;
                if (reason === "recent") return this.templateText.emptyRecent;
                if (reason === "recommended") return this.templateText.emptyRecommended;
                if (reason === "category") return this.templateText.emptyCategory;
                return reason === "remote" ? this.templateText.emptyRemote : this.templateText.emptyLocal;
            },

            templateEmptyIcon() {
                var reason = this.templateEmptyReason();
                if (reason === "search") return "ti-search-off";
                if (reason === "favorites") return "ti-star";
                if (reason === "recent") return "ti-history";
                if (reason === "recommended") return "ti-sparkles";
                if (reason === "category") return "ti-category";
                return reason === "remote" ? "ti-cloud-off" : "ti-template-off";
            },

            templateCanClearFilters() {
                return this.templateEntry === "sections" && (
                    String(this.templateQuery || "").trim() !== ""
                    || this.templateCategory !== "all"
                    || this.templatePurpose !== "all"
                    || this.templateDataSource !== "all"
                    || !["recommended", "all"].includes(this.templateQuickFilter)
                );
            },

            clearTemplateSectionFilters() {
                if (this.templateEntry !== "sections") return;
                this.templateQuery = "";
                this.templateCategory = "all";
                this.templatePurpose = "all";
                this.templateDataSource = "all";
                this.templateQuickFilter = this.templateQuickCount("recommended") > 0 ? "recommended" : "all";
                var scroller = this.$refs.templateScroll;
                if (scroller) scroller.scrollTop = 0;
                this.templateSectionScrollTop = 0;
                this.persistTemplateSectionViewState();
                this.restoreTemplateSectionScroll();
            },

            templateScopeCount(scope) {
                return this.templateEntryItems(window.BloxTemplateLibrary.scope(this.templateItems, scope)).length;
            },

            templateCategoryOptions() {
                return window.BloxTemplateLibrary.categories(this.templateEntryItems());
            },

            templateCategoryLabel(category) {
                return window.BloxTemplateLibrary.categoryLabel(category, this.templateText);
            },

            templatePurposeOptions() {
                return window.BloxTemplateLibrary.purposes(this.templateEntryItems());
            },

            templateDataSourceOptions() {
                var available = window.BloxTemplateLibrary.dataSources(this.templateEntryItems());
                // 静态/动态是区块目录的稳定契约；目录异步加载或旧缓存期间也要保留筛选入口。
                return ["static", "dynamic"].filter(function (value) {
                    return available.indexOf(value) !== -1 || value === "static" || value === "dynamic";
                });
            },

            templatePurposeLabel(purpose) {
                return window.BloxTemplateLibrary.purposeLabel(purpose, this.templateText);
            },

            templateVariantLabel(variant) {
                var value = String(variant || "standard").trim().toLowerCase();
                var key = "variant" + value.split("-").map(function (part) {
                    return part.charAt(0).toUpperCase() + part.slice(1);
                }).join("");
                return this.templateText[key] || value;
            },

            restoreTemplateSectionViewState() {
                var state = {};
                try {
                    state = JSON.parse(window.sessionStorage.getItem(this.templateSectionViewStorageKey) || "{}");
                } catch (error) {
                    state = {};
                }
                this.templateScope = state.scope === "remote" ? "remote" : "local";
                this.templateCategory = typeof state.category === "string" && /^[a-z0-9_-]{1,80}$/i.test(state.category)
                    ? state.category
                    : "all";
                this.templatePurpose = typeof state.purpose === "string" && /^[a-z0-9_-]{1,80}$/i.test(state.purpose)
                    ? state.purpose
                    : "all";
                this.templateDataSource = ["all", "static", "dynamic"].indexOf(state.dataSource) !== -1
                    ? state.dataSource
                    : "all";
                this.templateQuickFilter = ["recommended", "all", "favorites", "recent"].indexOf(state.quickFilter) !== -1
                    ? state.quickFilter
                    : "recommended";
                this.templateQuery = typeof state.query === "string" ? state.query.slice(0, 120) : "";
                var scrollTop = Number(state.scrollTop);
                this.templateSectionScrollTop = Number.isFinite(scrollTop)
                    ? Math.max(0, Math.min(scrollTop, 1000000))
                    : 0;
            },

            normalizeTemplateSectionViewState() {
                if (this.templateCategory !== "all"
                    && this.templateCategoryOptions().indexOf(this.templateCategory) === -1) {
                    this.templateCategory = "all";
                    this.templateSectionScrollTop = 0;
                }
                if (this.templatePurpose !== "all"
                    && this.templatePurposeOptions().indexOf(this.templatePurpose) === -1) {
                    this.templatePurpose = "all";
                    this.templateSectionScrollTop = 0;
                }
                if (this.templateDataSource !== "all"
                    && this.templateDataSourceOptions().indexOf(this.templateDataSource) === -1) {
                    this.templateDataSource = "all";
                    this.templateSectionScrollTop = 0;
                }
                if (this.templateQuickFilter === "recommended" && this.templateQuickCount("recommended") === 0) {
                    this.templateQuickFilter = "all";
                    this.templateSectionScrollTop = 0;
                }
            },

            templateItemRecommended(item) {
                return window.BloxTemplateLibrary.isRecommended(item, this.templatePageIntent);
            },

            rememberTemplateSectionScroll(scrollTop) {
                if (this.templateEntry !== "sections") return;
                scrollTop = Number(scrollTop);
                if (Number.isFinite(scrollTop)) {
                    this.templateSectionScrollTop = Math.max(0, Math.min(scrollTop, 1000000));
                }
            },

            persistTemplateSectionViewState() {
                if (this.templateEntry !== "sections") return;
                var scroller = this.$refs.templateScroll;
                if (scroller) this.rememberTemplateSectionScroll(scroller.scrollTop);
                try {
                    window.sessionStorage.setItem(this.templateSectionViewStorageKey, JSON.stringify({
                        scope: this.templateScope === "remote" ? "remote" : "local",
                        category: this.templateCategory,
                        purpose: this.templatePurpose,
                        dataSource: this.templateDataSource,
                        quickFilter: this.templateQuickFilter,
                        query: String(this.templateQuery || "").slice(0, 120),
                        scrollTop: this.templateSectionScrollTop,
                    }));
                } catch (error) {
                    // 禁用会话存储时仍保留本次页面生命周期内的状态。
                }
            },

            restoreTemplateSectionScroll() {
                var self = this;
                this.$nextTick(function () {
                    window.requestAnimationFrame(function () {
                        var scroller = self.$refs.templateScroll;
                        if (!scroller || !self.templateOpen || self.templateEntry !== "sections") return;
                        var maximum = Math.max(0, scroller.scrollHeight - scroller.clientHeight);
                        scroller.scrollTop = Math.min(self.templateSectionScrollTop, maximum);
                    });
                });
            },

            templateTypeLabel(type) {
                return type === "page" ? this.templateText.page : this.templateText.section;
            },

            templateProviderLabel(item) {
                return window.BloxTemplateLibrary.providerLabel(item, this.templateText);
            },

            canEditLocalTemplate(item) {
                return window.BloxTemplateLibrary.canEditLocal(item);
            },

            localTemplateEditUrl(item) {
                return window.BloxTemplateLibrary.localEditUrl(item);
            },

            // ---- 模板库偏好：收藏 / 最近使用 / 列表密度（localStorage，禁用存储时仅本次会话有效） ----

            restoreTemplateLibraryPreferences() {
                var read = function (key, limit) {
                    try {
                        var value = JSON.parse(window.localStorage.getItem(key) || "[]");
                        if (!Array.isArray(value)) return [];
                        return value.filter(function (item, index) {
                            return typeof item === "string" && item.length > 0 && item.length <= 160
                                && value.indexOf(item) === index;
                        }).slice(0, limit);
                    } catch (error) {
                        return [];
                    }
                };
                this.favoriteTemplateKeys = read(this.favoriteTemplatesStorageKey, 50);
                this.recentTemplateKeys = read(this.recentTemplatesStorageKey, 6);
                try {
                    var density = window.localStorage.getItem(this.templateDensityStorageKey);
                    this.templateDensity = density === "compact" ? "compact" : "standard";
                } catch (error) {
                    this.templateDensity = "standard";
                }
            },

            persistTemplateLibraryPreferences() {
                try {
                    window.localStorage.setItem(this.favoriteTemplatesStorageKey, JSON.stringify(this.favoriteTemplateKeys));
                    window.localStorage.setItem(this.recentTemplatesStorageKey, JSON.stringify(this.recentTemplateKeys));
                    window.localStorage.setItem(this.templateDensityStorageKey, this.templateDensity);
                } catch (error) {
                    // 禁用存储时仍保留本次编辑会话内的快捷筛选。
                }
            },

            isTemplateFavorite(key) {
                return this.favoriteTemplateKeys.indexOf(String(key || "")) !== -1;
            },

            isTemplateRecent(key) {
                return this.recentTemplateKeys.indexOf(String(key || "")) !== -1;
            },

            setTemplateDensity(density) {
                this.templateDensity = density === "compact" ? "compact" : "standard";
                this.persistTemplateLibraryPreferences();
            },

            toggleTemplateFavorite(key) {
                key = String(key || "");
                if (!key) return;
                var index = this.favoriteTemplateKeys.indexOf(key);
                if (index === -1) this.favoriteTemplateKeys.push(key);
                else this.favoriteTemplateKeys.splice(index, 1);
                this.persistTemplateLibraryPreferences();
            },

            rememberRecentTemplate(key) {
                key = String(key || "");
                if (!key) return;
                this.recentTemplateKeys = [key].concat(this.recentTemplateKeys.filter(function (item) {
                    return item !== key;
                })).slice(0, 6);
                this.persistTemplateLibraryPreferences();
            },

            templateLockLabel(item) {
                return window.BloxTemplateLibrary.lockLabel(item, this.templateText);
            },

            /** 预置区块入口里两个标签叫「基础区块 / 精品区块」；全部模板入口保留原来的本地/远程叫法。 */
            templateScopeLabel(scope) {
                if (this.templateEntry === "sections") {
                    return scope === "remote" ? this.templateText.premiumSections : this.templateText.basicSections;
                }
                return scope === "remote" ? this.templateText.remoteLibrary : this.templateText.localLibrary;
            },

            /** 精品入口的统一说明（只在精品标签内出现；有权益时为 null）。 */
            premiumNotice() {
                if (this.templateScope !== "remote") return null;
                return window.BloxTemplateLibrary.premiumNotice(
                    this.templateItems, this.templateRemoteError, !!this.templateText.hasLicenseKey
                );
            },

            premiumNoticeMessage() {
                var notice = this.premiumNotice();
                if (!notice) return "";
                var text = this.templateText;
                switch (notice.state) {
                    case "purchase": return text.premiumPurchase;
                    case "activate": return text.premiumActivate;
                    case "renew": return text.lockedExpired;
                    case "domain": return text.lockedDomain;
                    case "disabled": return text.lockedDisabled;
                    case "module": return text.lockedModule;
                    case "error": return this.templateRemoteError || text.premiumRetryHint;
                    default: return "";
                }
            },

            /** 说明里唯一的动作：购买/续期去官网，已购相关问题去后台授权管理，网络失败原地重试。 */
            premiumNoticeAction() {
                var notice = this.premiumNotice();
                if (!notice) return null;
                var text = this.templateText;
                switch (notice.state) {
                    case "purchase":
                    case "module": return { kind: "link", href: text.proUrl, label: text.viewPro, external: true };
                    case "renew": return { kind: "link", href: text.proUrl, label: text.renewPro, external: true };
                    case "activate":
                    case "domain":
                    case "disabled": return { kind: "link", href: "/admin/license.php", label: text.manageLicense, external: false };
                    case "error": return { kind: "retry", label: text.retry };
                    default: return null;
                }
            },

            showTemplateCardLock(item) {
                return window.BloxTemplateLibrary.showCardLock(item, this.premiumNotice());
            },

            showTemplatePremiumBadge(item) {
                return window.BloxTemplateLibrary.showPremiumBadge(item, this.templateItems);
            },

            anchorIdValid(value) {
                return /^[A-Za-z][A-Za-z0-9_-]{0,63}$/.test(String(value || "").replace(/^#/, ""));
            },

            anchorIdDuplicate(value) {
                var current = String(value || "").replace(/^#/, "").toLowerCase();
                if (!current || !this.anchorIdValid(current)) return false;
                var count = this.sections.filter(function (section) {
                    return String(section && section.settings && section.settings.anchor_id || "")
                        .replace(/^#/, "").toLowerCase() === current;
                }).length;
                return count > 1;
            },

            hasLockedTemplates() {
                return window.BloxTemplateLibrary.hasLockedRemote(this.templateItems);
            },


            insertTemplate(item) {
                this.applyTemplate(item, "append");
            },

            insertTemplateAt(item, index) {
                this.applyTemplate(item, "append", index);
            },

            replaceWithTemplate(item) {
                this.applyTemplate(item, "replace");
            },

            applyTemplate(item, mode, insertAt) {
                if (!item || item.locked || this.templateInserting) return;
                var replacing = mode === "replace";
                var requestedIndex = Number.isInteger(insertAt) ? insertAt : null;
                if (replacing && this.sections.length > 0
                    && !window.confirm(this.templateText.replaceConfirm)) return;
                if (!replacing && item.type === "page" && this.sections.length > 0
                    && !window.confirm(this.templateText.appendConfirm)) return;
                var self = this;
                this.templateInserting = item.key;
                var context = this.homeMode ? "home" : "page";
                // 请求时上下文：结构指纹 + 定点插入锚点的稳定区块 ID。等待检查/确认期间
                // 任何编辑（含撤销重做）都会改变指纹，旧响应不再插入，也不把旧数字
                // index 强行套到新页面。
                var requestContext = {
                    fingerprint: window.BloxTemplateLibrary.documentFingerprint({
                        settings: this.docSettings,
                        sections: this.sections,
                    }),
                    anchorId: requestedIndex !== null && this.sections[requestedIndex]
                        ? String(this.sections[requestedIndex].id || "")
                        : (requestedIndex === this.sections.length && this.sections.length > 0
                            ? String(this.sections[this.sections.length - 1].id || "") : ""),
                    anchorAfter: requestedIndex === this.sections.length,
                };
                window.BloxTemplateLibrary.prepareInsert(
                    "/admin/blox_template_api.php",
                    context,
                    item.key,
                    this.templateText.insertFailed,
                    this.csrf
                )
                    .then(function (data) {
                        if (!data.review_id) {
                            // 本地/插件来源没有包概念：保持既有直接插入路径。
                            self.executeInsertTemplate(item, data.template, mode, requestedIndex, requestContext);
                            return;
                        }
                        self.templateReview = {
                            item: item,
                            mode: mode,
                            requestedIndex: requestedIndex,
                            requestContext: requestContext,
                            context: context,
                            reviewId: data.review_id,
                            templateName: (data.template && data.template.name) || item.name,
                            diagnostics: data.design_diagnostics || {},
                            requirements: (data.template && data.template.requirements) || {},
                            styleMode: "keep",
                            mappings: { tokens: {}, styles: {} },
                            error: "",
                            busy: false,
                        };
                        self.focusDialog(self.$refs.templateReviewDialog);
                    })
                    .catch(function (error) {
                        self.templateError = error.message || self.templateText.insertFailed;
                    })
                    .finally(function () { self.templateInserting = ""; });
            },

            // 已确认/无需检查的模板一次性插入：命令层回滚整组，指纹或锚点失效则要求重选位置。
            executeInsertTemplate(item, template, mode, requestedIndex, requestContext) {
                var self = this;
                var replacing = mode === "replace";
                if (window.BloxTemplateLibrary.documentFingerprint({
                    settings: this.docSettings,
                    sections: this.sections,
                }) !== requestContext.fingerprint) {
                    this.templateError = this.templateText.reviewContextChanged;
                    return;
                }
                var at;
                if (replacing) {
                    at = 0;
                } else if (requestedIndex === null) {
                    at = this.insertIndex();
                } else {
                    var anchorIndex = requestContext.anchorId
                        ? this.sections.findIndex(function (section) {
                            return section && String(section.id || "") === requestContext.anchorId;
                        })
                        : -1;
                    if (anchorIndex === -1 && !(requestedIndex === 0 && this.sections.length === 0)) {
                        this.templateError = this.templateText.reviewContextChanged;
                        return;
                    }
                    at = anchorIndex === -1 ? 0 : anchorIndex + (requestContext.anchorAfter ? 1 : 0);
                }
                // 应用段走命令层：中途异常回滚整组插入，不留半截模板（silent：提示走外层 catch）
                var applied = this.commandRunner().execute("insert-template", function () {
                    var sections = template.sections;
                    if (!sections.length) throw new Error(self.templateText.insertFailed);
                    var fresh = window.BloxTemplateLibrary.freshSections(
                        sections,
                        function (prefix) { return self.uid(prefix); }
                    );
                    self.docSettings = window.BloxTemplateLibrary.applyPageSettings(
                        self.docSettings, template, mode, self.pageTemplateTarget
                    );
                    if (replacing) {
                        self.sections.splice.apply(self.sections, [0, self.sections.length].concat(fresh));
                        self.legacyPageContent = false;
                    } else {
                        self.sections.splice.apply(self.sections, [at, 0].concat(fresh));
                    }
                    self.selectedSi = at;
                    self.selectedCi = -1;
                    self.selectedEi = -1;
                    self.selectedSubEi = -1;
                    self.selLayer = "sec";
                    self.closeTemplates();
                    self.toast((template.name || item.name) + (replacing ? self.templateText.replaced : self.templateText.inserted));
                }, { silent: true });
                if (!applied.ok) throw (applied.error || new Error(this.templateText.insertFailed));
                if (item.type === "section") this.rememberRecentTemplate(item.key);
            },

            // ── 画布插入检查模态：确认前不改文档；取消即回到模板库。 ──
            confirmTemplateReview() {
                var review = this.templateReview;
                if (!review || review.busy || this.templateInserting) return;
                var self = this;
                review.busy = true;
                review.error = "";
                this.templateInserting = review.item.key;
                window.BloxTemplateLibrary.confirmInsert(
                    "/admin/blox_template_api.php",
                    review.context,
                    review.item.key,
                    review.reviewId,
                    {
                        style_mode: review.styleMode,
                        tokens: review.mappings.tokens,
                        styles: review.mappings.styles,
                    },
                    this.templateText.insertFailed,
                    this.csrf
                )
                    .then(function (template) {
                        // 请求在途时对话框已被关闭或换成别的评审：不再插入
                        if (self.templateReview !== review) return;
                        // 先插入再关闭：插入失败（命令回滚等）时错误留在对话框里，而不是随对话框消失
                        self.executeInsertTemplate(
                            review.item, template, review.mode, review.requestedIndex, review.requestContext
                        );
                        self.templateReview = null;
                        self.releaseDialog(self.$refs.templateReviewDialog);
                    })
                    .catch(function (error) {
                        // 失败保留选择与已填映射，用户调整后可直接重试。
                        if (self.templateReview) {
                            self.templateReview.error = error.message || self.templateText.insertFailed;
                        }
                    })
                    .finally(function () {
                        if (self.templateReview) self.templateReview.busy = false;
                        self.templateInserting = "";
                    });
            },

            cancelTemplateReview() {
                // 确认请求在途时不可取消（含 Esc 与点遮罩）：服务端可能已记下导入，界面须等结果
                if (!this.templateReview || this.templateReview.busy) return;
                this.releaseDialog(this.$refs.templateReviewDialog);
                this.templateReview = null;
            },

            templateReviewIssues() {
                var review = this.templateReview;
                if (!review) return [];
                var text = this.templateText;
                var issues = [
                    ["missing", text.reviewIssueMissing],
                    ["archived", text.reviewIssueArchived],
                    ["conflicting", text.reviewIssueConflicting],
                    ["same_name", text.reviewIssueSameName],
                    ["unverified", text.reviewIssueUnverified],
                ];
                var kinds = [["tokens", text.reviewTokens], ["styles", text.reviewStyles]];
                var lines = [];
                issues.forEach(function (issue) {
                    kinds.forEach(function (kind) {
                        var list = review.diagnostics[issue[0] + "_" + kind[0]] || [];
                        if (list.length) lines.push(issue[1] + " · " + kind[1] + ": " + list.join(", "));
                    });
                });
                return lines;
            },

            templateReviewReferences(kind) {
                var review = this.templateReview;
                var refs = review && review.requirements && Array.isArray(review.requirements["design_" + kind])
                    ? review.requirements["design_" + kind] : [];
                return refs.filter(function (reference) {
                    return /^[a-z][a-z0-9_-]{0,47}$/.test(String(reference || ""));
                });
            },

            templateReviewOptions(kind) {
                var items = kind === "tokens" ? this.activeColorTokens() : this.activeGlobalStyles();
                return items.map(function (item) {
                    return { id: item.id, label: item.name + " (" + item.id + ")" };
                });
            },

            // ── 媒体库选择器（复用 media_api.php，与后台其它页的选图弹窗同一数据源） ──
            mediaOpen: false,
            mediaItems: [],
            mediaPage: 1,
            mediaPages: 1,
            mediaTotal: 0,
            mediaKeyword: "",
            mediaLoading: false,
            mediaSource: "local",
            mediaType: "image",
            mediaSort: "default",
            mediaCanSwitchType: false,
            mediaEntitlement: { canImport: false, reason: "" },
            mediaImporting: "",
            mediaUsage: "",
            mediaPreferredMinWidth: 0,
            mediaRequestGuard: window.BloxMediaClient.latestRequestGuard(),
            _mediaVideoPreviewQueue: null,
            _mediaTarget: null,   // 选中回调：拿到 url 写进哪个字段
            _mediaTargets: null,
            _mediaImageUsage: "",
