<?php
declare(strict_types=1);
?>
            siteTagTarget: "",
            siteTagSearch: "",
            siteDynamicOptions(links) {
                // 单花括号站点标签 + 双花括号上下文标签（v1.24）合并为一个候选面板
                return links
                    ? <?= json_encode(DynamicSiteData::tagOptions(true) + BloxDynamicTags::tagOptions(true), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>
                    : <?= json_encode(DynamicSiteData::tagOptions() + BloxDynamicTags::tagOptions(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
            },
            openSiteTags(key) {
                var target = this.selEl.id + ':' + key;
                this.siteTagTarget = this.siteTagTarget === target ? '' : target;
                this.siteTagSearch = '';
            },
            siteTagInput(event, key) {
                if (event.inputType === 'insertText' && event.data === '{') {
                    this.siteTagTarget = this.selEl.id + ':' + key;
                    this.siteTagSearch = '';
                }
            },
            insertSiteTag(key, tag) {
                var input = Array.from(document.querySelectorAll('[data-dynamic-key]')).find(function (el) {
                    return el.dataset.dynamicKey === key && el.getClientRects().length;
                });
                var value = String(this.selEl.data[key] || '');
                var start = input && input.selectionStart !== null ? input.selectionStart : value.length;
                var end = input && input.selectionEnd !== null ? input.selectionEnd : start;
                // 触发字符回收：最多吃掉两个前导 { （用户可能连敲出 {{ 再选双花括号标签）
                var trimmedBraces = 0;
                while (start > 0 && value[start - 1] === '{' && trimmedBraces < 2) { start--; trimmedBraces++; }
                this.selEl.data[key] = value.slice(0, start) + tag + value.slice(end);
                this.siteTagTarget = '';
                this.$nextTick(function () {
                    if (input) { input.focus(); input.setSelectionRange(start + tag.length, start + tag.length); }
                });
            },
            openMedia(setter, options) {
                options = options || {};
                this.mediaType = options.type === "video" ? "video" : "image";
                this._mediaTargets = options.targets
                    && typeof options.targets.image === "function"
                    && typeof options.targets.video === "function"
                    ? options.targets
                    : null;
                this.mediaCanSwitchType = this._mediaTargets !== null;
                this._mediaTarget = this.mediaCanSwitchType ? this._mediaTargets[this.mediaType] : setter;
                this._mediaImageUsage = String(options.usage || "");
                this.mediaUsage = this.mediaType === "image" ? this._mediaImageUsage : "";
                this.mediaPreferredMinWidth = this.mediaUsage === "hero-bg" ? 1920 : 0;
                this.mediaSource = options.source === "official" && this.mediaType === "image" ? "official" : "local";
                this.mediaEntitlement = { canImport: false, reason: "" };
                this.mediaImporting = "";
                this.mediaOpen = true;
                this.mediaKeyword = "";
                this.focusDialog(this.$refs.mediaDialog, "[data-dialog-initial]");
                this.loadMedia(1);
            },

            closeMedia() {
                if (!this.mediaOpen) return;
                var root = this.$refs.mediaDialog;
                this.resetMediaVideoPreviews();
                this.mediaOpen = false;
                this._mediaTarget = null;
                this._mediaTargets = null;
                this.mediaCanSwitchType = false;
                this.mediaType = "image";
                this.mediaUsage = "";
                this._mediaImageUsage = "";
                this.mediaPreferredMinWidth = 0;
                this.mediaImporting = "";
                this.mediaRequestGuard.invalidate();
                this.mediaLoading = false;
                this.releaseDialog(root);
            },

            setMediaType(type) {
                if (!this.mediaCanSwitchType || !["image", "video"].includes(type) || type === this.mediaType) return;
                this.mediaRequestGuard.invalidate();
                this.mediaType = type;
                this._mediaTarget = this._mediaTargets[type];
                this.mediaUsage = type === "image" ? this._mediaImageUsage : "";
                this.mediaPreferredMinWidth = this.mediaUsage === "hero-bg" ? 1920 : 0;
                this.mediaSource = "local";
                this.mediaKeyword = "";
                this.mediaEntitlement = { canImport: false, reason: "" };
                this.mediaImporting = "";
                this.loadMedia(1);
            },

            setMediaSource(source) {
                if (source === "official" && this.mediaType !== "image") return;
                this.mediaSource = source === "official" ? "official" : "local";
                this.mediaEntitlement = { canImport: false, reason: "" };
                this.mediaImporting = "";
                this.loadMedia(1);
            },

            loadMedia(page) {
                var self = this;
                this.resetMediaVideoPreviews();
                this.mediaItems = [];
                var requestId = this.mediaRequestGuard.begin();
                this.mediaLoading = true;
                this.mediaPage = page;
                var request = this.mediaSource === "official"
                    ? window.OfficialMediaClient.list("/admin/media_api.php", page, this.mediaKeyword, { usage: this.mediaUsage })
                    : window.BloxMediaClient.list("/admin/media_api.php", page, this.mediaKeyword, {
                        usage: this.mediaUsage,
                        type: this.mediaType,
                        sort: this.mediaSort,
                    });
                request
                    .then(function (result) {
                        if (!self.mediaRequestGuard.isCurrent(requestId)) return;
                        if (result.ok) {
                            self.mediaItems = result.items;
                            self.mediaPages = result.pages;
                            self.mediaTotal = result.total;
                            self.mediaEntitlement = result.entitlement || { canImport: false, reason: "" };
                        } else {
                            self.mediaItems = [];
                            self.mediaPages = 0;
                            self.mediaTotal = 0;
                            self.mediaEntitlement = { canImport: false, reason: "" };
                            self.toast(result.message || (self.mediaSource === "official" ? self.uiText.officialMediaFailed : self.uiText.mediaLoadFailed));
                        }
                    })
                    .catch(function () {
                        if (!self.mediaRequestGuard.isCurrent(requestId)) return;
                        self.mediaItems = [];
                        self.mediaPages = 0;
                        self.mediaTotal = 0;
                        self.mediaEntitlement = { canImport: false, reason: "" };
                        self.toast(self.mediaSource === "official" ? self.uiText.officialMediaFailed : self.uiText.mediaFailed);
                    })
                    .finally(function () {
                        if (self.mediaRequestGuard.isCurrent(requestId)) self.mediaLoading = false;
                    });
            },

            mediaRecommended(item) {
                return this.mediaPreferredMinWidth > 0
                    && Number((item && item.width) || 0) >= this.mediaPreferredMinWidth;
            },

            mediaDimensions(item) {
                var preview = this.mediaVideoPreview(item);
                var width = Math.max(0, Number((preview && preview.width) || (item && item.width) || 0));
                var height = Math.max(0, Number((preview && preview.height) || (item && item.height) || 0));
                var bytes = Math.max(0, Number((item && item.size) || 0));
                var values = [];
                if (width > 0 && height > 0) values.push(Math.round(width) + "×" + Math.round(height));
                if (preview && preview.duration > 0) values.push(window.BloxMediaClient.formatDuration(preview.duration));
                if (bytes > 0) values.push(window.BloxMediaClient.formatBytes(bytes));
                return values.join(" · ");
            },

            mediaVideoPreview(item) {
                return item && item._videoPreview && typeof item._videoPreview === "object"
                    ? item._videoPreview
                    : { status: "idle", width: 0, height: 0, duration: 0 };
            },

            mediaVideoStatusText(item) {
                return this.mediaVideoPreview(item).status === "error"
                    ? this.uiText.mediaVideoPreviewUnavailable
                    : this.uiText.mediaVideoPreviewLoading;
            },

            registerMediaVideoPreview(video, item) {
                if (!this.mediaOpen || this.mediaType !== "video" || !video || !item) return;
                if (!this._mediaVideoPreviewQueue) {
                    this._mediaVideoPreviewQueue = window.BloxMediaClient.createVideoPreviewQueue({
                        root: this.$refs.mediaScroll,
                        maxConcurrent: 2,
                    });
                }
                this._mediaVideoPreviewQueue.observe(video, item.url, function (state) {
                    item._videoPreview = state;
                });
            },

            resetMediaVideoPreviews() {
                if (this._mediaVideoPreviewQueue) this._mediaVideoPreviewQueue.reset();
                this._mediaVideoPreviewQueue = null;
            },

            mediaDate(item) {
                var timestamp = Math.max(0, Number((item && item.created_at) || 0));
                if (timestamp <= 0) return "";
                var date = new Date(timestamp * 1000);
                if (!Number.isFinite(date.getTime())) return "";
                var month = String(date.getMonth() + 1).padStart(2, "0");
                var day = String(date.getDate()).padStart(2, "0");
                return date.getFullYear() + "-" + month + "-" + day;
            },

            mediaItemName(item) {
                var lang = String(document.documentElement.lang || "").toLowerCase();
                if (lang.indexOf("ja") === 0 && item && item.name_ja) return String(item.name_ja);
                if (lang.indexOf("en") === 0 && item && item.name_en) return String(item.name_en);
                return String((item && (item.name || item.name_en || item.name_ja || item.id)) || "");
            },

            officialPreviewUrl(item) {
                var url = String((item && (item.preview_large_url || item.preview_url)) || "");
                if (/^https:\/\/(update|media)\.yikaicms\.com\//i.test(url)) return url;
                if (/^http:\/\/(127\.0\.0\.1|localhost)(:\d+)?\//i.test(url)) return url;
                return "";
            },

            previewOfficialMedia(item) {
                var url = this.officialPreviewUrl(item);
                if (!url) {
                    this.toast(this.uiText.officialMediaFailed);
                    return;
                }
                window.open(url, "_blank", "noopener,noreferrer");
            },

            importOfficialMedia(item) {
                var assetId = String((item && item.id) || "");
                if (!assetId || this.mediaImporting) return;
                var self = this;
                this.mediaImporting = assetId;
                window.OfficialMediaClient.importAsset("/admin/media_api.php", assetId, { csrf: this.csrf })
                    .then(function (result) {
                        if (!result.ok) {
                            self.toast(result.message || self.uiText.officialMediaFailed);
                            return;
                        }
                        if (self._mediaTarget) self._mediaTarget(result.url, result.data);
                        self.closeMedia();
                    })
                    .catch(function () { self.toast(self.uiText.officialMediaFailed); })
                    .finally(function () { self.mediaImporting = ""; });
            },

            pickMedia(url) {
                if (this._mediaTarget) this._mediaTarget(url);
                this.closeMedia();
            },

            mediaUploading: false,

            // Rich-text dialogs own their TinyMCE instance and detached popups.
            rteOpen: false,
            _rteTarget: null,
            _rteInited: false,

            openRte(getter, setter, dynamicTags) {
                this._rteTarget = setter;
                this.rteOpen = true;
                this.focusDialog(this.$refs.rteDialog, "[data-dialog-initial]");
                var initial = getter() || "";
                var self = this;
                this.$nextTick(function () {
                    if (!self.rteOpen) return;
                    if (self._rteInited) {
                        var ed = hugerte.get("bloxRte");
                        if (ed) ed.setContent(initial);
                        return;
                    }
                    self._rteInited = true;
                    hugerte.init({
                        selector: "#bloxRte",
                        // 与后台通用编辑器同一张语言表：英文后台不再被强行换成中文（复审 R09）
                        language: (window.editorLanguage ? window.editorLanguage(document.documentElement.lang) : "") || undefined,
                        height: 420,
                        formats: {
                            alignleft: { selector: "p,h1,h2,h3,h4,h5,h6,div", classes: "text-left" },
                            aligncenter: { selector: "p,h1,h2,h3,h4,h5,h6,div", classes: "text-center" },
                            alignright: { selector: "p,h1,h2,h3,h4,h5,h6,div", classes: "text-right" }
                        },
                        content_style: ".text-left{text-align:left}.text-center{text-align:center}.text-right{text-align:right}",
                        menubar: false,
                        plugins: "autolink lists link image charmap searchreplace visualblocks code codesample insertdatetime media table wordcount",
                        toolbar: "undo redo | styles fontsize | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright | bullist numlist | link image media codesample | table | removeformat code" + (dynamicTags ? " | ykDynamic" : ""),
                        branding: false, promotion: false, convert_urls: false,
                        images_upload_handler: function (blobInfo) {
                            return new Promise(function (resolve, reject) {
                                window.BloxMediaClient.upload("/admin/media_api.php", blobInfo.blob(), {
                                    csrf: self.csrf,
                                    filename: blobInfo.filename(),
                                    maxDimension: <?php echo max(0, (int) config('upload_max_width', 1920)); ?>,
                                    quality: <?php echo max(50, min(95, (int) config('upload_jpeg_quality', 85))) / 100; ?>,
                                })
                                    .then(function (result) { result.ok ? resolve(result.url) : reject(result.message || self.uiText.uploadFailedShort); })
                                    .catch(function () { reject(self.uiText.uploadFailedShort); });
                            });
                        },
                        // Reuse the editor media library.
                        file_picker_types: "image",
                        file_picker_callback: function (cb, value, meta) {
                            if (meta.filetype === "image") self.openMedia(function (u) { cb(u, { alt: "" }); });
                        },
                        setup: function (ed) {
                            if (dynamicTags) {
                                ed.ui.registry.addMenuButton('ykDynamic', {
                                    icon: 'insert-time',
                                    tooltip: <?= json_encode(__('blox_dynamic_insert'), JSON_UNESCAPED_UNICODE) ?>,
                                    fetch: function (callback) {
                                        callback(Object.entries(self.siteDynamicOptions(false)).map(function (entry) {
                                            return { type: 'menuitem', text: entry[1] + ' ' + entry[0], onAction: function () { ed.insertContent(entry[0]); } };
                                        }));
                                    }
                                });
                                ed.ui.registry.addAutocompleter('ykDynamicTags', {
                                    trigger: '{', minChars: 0, columns: 1,
                                    fetch: function (pattern) {
                                        return Promise.resolve(Object.entries(self.siteDynamicOptions(false)).filter(function (entry) {
                                            return (entry[0] + entry[1]).toLowerCase().includes(pattern.toLowerCase());
                                        }).map(function (entry) { return { value: entry[0], text: entry[1] + ' ' + entry[0] }; }));
                                    },
                                    onAction: function (api, range, value) { ed.selection.setRng(range); ed.insertContent(value); api.hide(); }
                                });
                            }
                            ed.on("init", function () {
                                if (!self.rteOpen) { ed.remove(); return; }
                                ed.setContent(initial);
                            });
                        }
                    });
                });
            },

            closeRte() {
                if (!this.rteOpen) return;
                var root = this.$refs.rteDialog;
                var editor = window.hugerte && hugerte.get("bloxRte");
                if (editor) editor.remove();
                this._rteInited = false;
                this.rteOpen = false;
                this._rteTarget = null;
                this.releaseDialog(root);
            },

            saveRte() {
                var ed = hugerte.get("bloxRte");
                if (ed && this._rteTarget) this._rteTarget(ed.getContent());
                this.closeRte();
            },

            /** 上传成功直接选用（上传的目的就是马上用）；失败提示原因留在弹窗里重试 */
            mediaUploadMessage(result) {
                if (!result || !result.optimized || result.uploadBytes >= result.originalBytes) {
                    return this.uiText.uploadedSelected;
                }
                return this.uiText.uploadedOptimized
                    .replace(":from", window.BloxMediaClient.formatBytes(result.originalBytes))
                    .replace(":to", window.BloxMediaClient.formatBytes(result.uploadBytes));
            },

            uploadMedia(file) {
                if (!file || this.mediaUploading) return;
                var self = this;
                this.mediaUploading = true;
                window.BloxMediaClient.upload("/admin/media_api.php", file, {
                    csrf: this.csrf,
                    type: this.mediaType,
                    maxBytes: <?php echo max(0, (int) UPLOAD_MAX_SIZE); ?>,
                    maxDimension: <?php echo max(0, (int) config('upload_max_width', 1920)); ?>,
                    quality: <?php echo max(50, min(95, (int) config('upload_jpeg_quality', 85))) / 100; ?>,
                })
                    .then(function (result) {
                        if (result.ok) {
                            self.toast(self.mediaUploadMessage(result));
                            self.pickMedia(result.url);
                        } else if (result.error === "too_large") {
                            self.toast(self.uiText.uploadTooLarge
                                .replace(":size", window.BloxMediaClient.formatBytes(result.originalBytes))
                                .replace(":limit", window.BloxMediaClient.formatBytes(result.limitBytes)));
                        } else {
                            self.toast(result.message || self.uiText.uploadFailedShort);
                        }
                    })
                    .catch(function () { self.toast(self.uiText.uploadFailed); })
                    .finally(function () { self.mediaUploading = false; });
            },
            targetCi: 0,                // 插入到选中区块的第几列
            elementLib: <?php echo json_encode($elementLib, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            catLabels: <?php echo json_encode($catLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            elementSchemas: <?php echo json_encode($elementSchemas, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            homeBannerSeeds: <?php echo json_encode($homeBannerSeeds, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,

            // 元素选中：-1 表示当前选的是区块本身；selectedSubEi ≥0 = 选的是容器内的子元素
            selectedCi: -1,
            selectedEi: -1,
            selectedSubEi: -1,
            // 0b：子级选区路径（顶层元素以下逐层子索引）。selectedSubEi 是首段镜像，
            // 供只认识单层 children 的旧消费方；写入必须走 setSubSelection 保持同步。
            selectedSubPath: [],
            // 同级多选（R1）：稳定 id 集合，仅同列/同容器/根区块内；批量操作条由 multiSelActive 门控
            multiSel: null,
            // 批量剪贴板（R2）：有序列表 {level, parent, items}；单选剪贴板（clipboard）不受影响
            batchClipboard: null,
            multiText: <?php echo json_encode([
                'count' => __('blox_multi_selected_count'),
                'clipboardCount' => __('blox_batch_clipboard_count'),
                'hint' => __('blox_multi_hint'),
                'actions' => [
                    'delete' => __('blox_batch_delete'),
                    'duplicate' => __('blox_batch_duplicate'),
                    'cut' => __('blox_batch_cut'),
                    'paste' => __('blox_batch_paste'),
                ],
                'deleteDone' => __('blox_batch_delete_done'),
                'duplicateDone' => __('blox_batch_duplicate_done'),
                'cutDone' => __('blox_batch_cut_done'),
                'pasteDone' => __('blox_batch_paste_done'),
                'pasteRejected' => __('blox_batch_paste_rejected'),
                'failed' => __('blox_batch_failed'),
            ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
            selectedSectionField: "",
            selectedHomeField: "",
            homeFieldRevision: 0,
            selectedHomeColumn: "",
            _emptyCol: {
                card_bg: "",
                card_bg_image: "",
                card_bg_overlay_color: "",
                card_bg_overlay_opacity: 0,
            },
            // 区块的选中层（Bricks 分层树）：sec=全宽背景层，con=内容容器层
            selLayer: "sec",

            get sel() { return this.selectedSi >= 0 && this.sections[this.selectedSi] ? this.sections[this.selectedSi] : null; },

            /** 列级选中元素（容器场景下=容器本身，不下钻子元素） */
            get selTopEl() {
                var s = this.sel;
                if (!s || this.selectedCi < 0 || this.selectedEi < 0) return null;
                var col = s.columns[this.selectedCi];
                return (col && col.elements[this.selectedEi]) ? col.elements[this.selectedEi] : null;
            },

            /** 当前选中的元素对象；子级选中时沿 selectedSubPath 逐层下钻（0b 起支持嵌套容器） */
            get selEl() {
                var el = this.selTopEl;
                var subPath = this.selectedSubPath || [];
                for (var i = 0; el && i < subPath.length; i++) {
                    var kids = (el.data && el.data.children) || [];
                    el = kids[subPath[i]] || null;
                }
                return el;
            },

            /** 元素 schema。未知类型（插件卸载后残留等）也要给个兜底，不能让设置面板炸掉 */
