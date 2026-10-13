(function (root) {
    "use strict";
    root.YikaiBloxSectionInsert = {
        mixin: function (text) {
            return {
                sectionInsertOpen: false,
                sectionInsertAfterId: null,
                sectionInsertLabel: "",
                sectionInsertStyle: "",
                // 同一个选择器两种用途（2.1.0）：section＝新建区块；split＝把列 / 容器 / 布局块拆成几栏
                sectionInsertMode: "section",
                splitTarget: null,
                layoutSplitText: text,
                quickAddTargetId: "",
                quickAddContextId: function () {
                    var section = this.sections[this.selectedSi];
                    var column = section && section.columns && section.columns[this.selectedCi];
                    if (!column) return "";
                    if (this.selectedEi >= 0) {
                        var host = this.selEl;
                        return host && this.elSchema(host.type).container ? "element:" + host.id : "";
                    }
                    return "column:" + column.id;
                },
                hasQuickAddTarget: function () {
                    return !!(this.libOpen && this.quickAddTargetId
                        && this.quickAddTargetId === this.quickAddContextId());
                },
                openSectionInsert: function (index, event, canvasAnchor) {
                    if (!Number.isInteger(index) || index < 0 || index > this.sections.length) return;
                    var previous = index > 0 ? this.sections[index - 1] : null;
                    this.sectionInsertMode = "section";
                    this.splitTarget = null;
                    this.sectionInsertAfterId = previous ? previous.id : null;
                    this.sectionInsertLabel = previous
                        ? text.after.replace(":name", this.sectionLabel(previous, index - 1)) : text.start;
                    this.openLayoutPopover(event, canvasAnchor);
                },
                // 定位弹层：event＝按钮旁；canvasAnchor＝画布内坐标（按缩放换算）；point＝视口坐标（右键菜单处）
                openLayoutPopover: function (event, canvasAnchor, point) {
                    var opener = event && event.currentTarget;
                    var rect = opener ? opener.getBoundingClientRect() : this.$refs.canvas.getBoundingClientRect();
                    var x = rect.left + rect.width / 2;
                    var y = rect.bottom + 6;
                    if (canvasAnchor) {
                        var scale = rect.width / (this.$refs.canvas.clientWidth || rect.width || 1);
                        x = rect.left + canvasAnchor.x * scale;
                        y = rect.top + canvasAnchor.y * scale + 6;
                    } else if (point) {
                        x = point.x;
                        y = point.y;
                    }
                    this._sectionInsertOpener = opener || this.$refs.canvas;
                    var width = Math.min(304, window.innerWidth - 24);
                    var left = Math.max(12, Math.min(x - width / 2, window.innerWidth - width - 12));
                    var place = function (height) {
                        // 底部留出约 100px：弹层贴着窗口底边时既难看也容易被浏览器状态栏挡住
                        var top = Math.max(12, Math.min(y, window.innerHeight - height - 100));
                        return "width:" + width + "px;left:" + left + "px;top:" + top + "px";
                    };
                    this.sectionInsertStyle = place(340);
                    this.sectionInsertOpen = true;
                    var self = this;
                    this.$nextTick(function () {
                        var picker = self.$refs.sectionInsertPicker;
                        // 按实际高度复算：导入按钮是否显示、窄屏换行都会改变弹层高度
                        if (picker && picker.offsetHeight > 0) self.sectionInsertStyle = place(picker.offsetHeight);
                        // 拆分模式隐藏了「1 列」等项，焦点给第一个看得见的
                        var choices = picker ? Array.prototype.slice.call(picker.querySelectorAll('[data-layout-choice]')) : [];
                        var first = choices.filter(function (node) { return node.offsetParent !== null; })[0] || choices[0];
                        if (first) first.focus();
                    });
                },
                closeSectionInsert: function () {
                    this.sectionInsertOpen = false;
                    if (this._sectionInsertOpener && this._sectionInsertOpener.isConnected) this._sectionInsertOpener.focus();
                },
                chooseSectionLayout: function (count) {
                    if (!this.sectionInsertOpen || !Number.isInteger(count) || count < 1 || count > 6) return;
                    if (this.sectionInsertMode === "split") {
                        if (12 % count !== 0 || count < 2) return;
                        var equal = [];
                        for (var n = 0; n < count; n++) equal.push(12 / count);
                        this.chooseSplit(equal);
                        return;
                    }
                    var index = this.resolveSectionInsert();
                    if (index === null) return;
                    this._insertAt = index;
                    try { this.addSection(count); } finally { this._insertAt = null; }
                },
                // 按比例插入（2.1.0）：各列宽度合计不超过 12，单列可以不足整行
                chooseSectionPreset: function (spans) {
                    if (!this.sectionInsertOpen || !Array.isArray(spans) || spans.length < 1 || spans.length > 6) return;
                    var total = 0;
                    for (var i = 0; i < spans.length; i++) {
                        if (!Number.isInteger(spans[i]) || spans[i] < 1 || spans[i] > 12) return;
                        total += spans[i];
                    }
                    if (total > 12) return;
                    if (this.sectionInsertMode === "split") {
                        this.chooseSplit(spans);
                        return;
                    }
                    var index = this.resolveSectionInsert();
                    if (index === null) return;
                    this._insertAt = index;
                    try { this.addSection(spans.slice()); } finally { this._insertAt = null; }
                },
                // ── 拆分（2.1.0，参照 Bricks 在 Block 上再插列）：列、容器、布局块里放一个网格容器，按比例分成几个布局块；
                // 每个布局块还能接着拆。数据仍是既有的容器 grid + 子项 grid_span，不新增结构。
                splitTargetHost: function (target) {
                    if (!target || typeof target !== "object") return null;
                    if (target.kind === "column") {
                        var section = this.sections[target.sec];
                        return section && section.columns && section.columns[target.col] ? section.columns[target.col] : null;
                    }
                    if (target.kind === "container") {
                        var host = this.elementAtPath(target.path);
                        return host && (host.type === "container" || host.type === "div") ? host : null;
                    }
                    return null;
                },
                openSplitPicker: function (target, event, canvasAnchor, point) {
                    var host = this.splitTargetHost(target);
                    if (!host) return;
                    this.sectionInsertMode = "split";
                    this.splitTarget = target.kind === "column"
                        ? { kind: "column", sec: target.sec, col: target.col }
                        : { kind: "container", path: String(target.path) };
                    this._splitHost = host;
                    this.sectionInsertLabel = text.splitHint;
                    this.openLayoutPopover(event, canvasAnchor, point);
                },
                // 列面板 / 容器面板上的「拆分」：拆当前选中的列，或选中的容器、布局块
                openSplitForSelection: function (event) {
                    var target = this.selectedEi >= 0
                        ? { kind: "container", path: [this.selectedSi, this.selectedCi, this.selectedEi].concat(this.selectedSubPath || []).join(".") }
                        : { kind: "column", sec: this.selectedSi, col: this.selectedCi };
                    this.openSplitPicker(target, event);
                },
                ctxSplitTarget: function () {
                    var kind = this.ctx.kind;
                    var t = this.ctx.target || {};
                    var target = kind === "column" ? { kind: "column", sec: t.si, col: t.ci }
                        : kind === "element" || kind === "child"
                            ? { kind: "container", path: [t.si, t.ci, t.ei].concat(kind === "child" ? this.ctxChildSubPath(t) : []).join(".") }
                            : null;
                    return target && this.splitTargetHost(target) ? target : null;
                },
                openSplitFromCtx: function () {
                    var target = this.ctxSplitTarget();
                    var point = { x: this.ctx.x, y: this.ctx.y };
                    this.closeCtx();
                    if (target) this.openSplitPicker(target, null, null, point);
                },
                // 画布拖尺寸（2.1.0）：列 / 容器 / 布局块的最小高度，单列区块的列宽；一次拖动一步撤销
                applyCanvasSizeDrag: function (payload) {
                    payload = payload || {};
                    var target = payload.target || {};
                    var value = parseInt(payload.value, 10);
                    if (!Number.isInteger(value) || value < 0) return;
                    if (target.kind === "column") {
                        var section = this.sections[target.sec];
                        var col = section && section.columns ? section.columns[target.col] : null;
                        if (!col) return;
                        if (payload.prop === "span") {
                            if (section.columns.length !== 1 || value < 1 || value > 12) return;
                            this.selectColumn(target.sec, target.col, false);
                            this.runCommand("canvas-size-drag", function () {
                                this.writeSpan(col, value, this.rawSpanT(col));
                                if (value >= 12) delete col.position;
                            });
                        } else if (payload.prop === "min_height" && value <= 1200) {
                            this.selectColumn(target.sec, target.col, false);
                            this.runCommand("canvas-size-drag", function () {
                                if (value > 0) col.min_height = value; else delete col.min_height;
                            });
                        } else {
                            return;
                        }
                    } else if (target.kind === "container" && payload.prop === "min_height" && value <= 1200) {
                        var el = this.elementAtPath(String(target.path || ""));
                        if (!el || (el.type !== "container" && el.type !== "div")) return;
                        this.selectPath(String(target.path), false);
                        this.runCommand("canvas-size-drag", function () {
                            if (!el.data || typeof el.data !== "object") el.data = {};
                            if (value > 0) el.data.min_height_px = value; else delete el.data.min_height_px;
                        });
                    } else {
                        return;
                    }
                    this.highlightCanvasSelection();
                },
                // 网格容器（拆分出来的就是）：面板里的布局预览按列数和各子项跨度画；排列方向、换行是 flex 设置，不适用
                containerIsGrid: function () {
                    return !!(this.selEl && this.selEl.type === "container" && (this.selEl.data || {}).layout === "grid");
                },
                containerGridColumns: function () {
                    var raw = (this.selEl && this.selEl.data || {}).grid_cols;
                    var cols = parseInt(raw && typeof raw === "object" ? raw.d : raw, 10);
                    return [1, 2, 3, 4, 6, 12].indexOf(cols) === -1 ? 3 : cols;
                },
                containerPreviewItemStyle: function (n) {
                    if (!this.containerIsGrid()) return "";
                    var child = ((this.selEl.data || {}).children || [])[n - 1];
                    var raw = child && child.data ? child.data.grid_span : "";
                    var span = raw && typeof raw === "object" ? raw.d : raw;
                    if (span === "full") return "grid-column: 1 / -1";
                    span = parseInt(span, 10);
                    return span >= 1 ? "grid-column: span " + span + " / span " + span : "";
                },
                splitNode: function (spans) {
                    var self = this;
                    var equal = spans.every(function (span) { return span === spans[0]; }) && spans[0] * spans.length === 12;
                    var data = JSON.parse(JSON.stringify(this.elSchema("container").defaults || {}));
                    data.layout = "grid";
                    data.grid_cols = String(equal ? spans.length : 12);
                    data.children = spans.map(function (span) {
                        var child = JSON.parse(JSON.stringify(self.elSchema("div").defaults || {}));
                        if (!equal) child.grid_span = String(span);
                        child.children = [];
                        return { id: self.uid("e"), type: "div", data: child };
                    });
                    return { id: this.uid("e"), type: "container", data: data };
                },
                chooseSplit: function (spans) {
                    if (!Array.isArray(spans) || spans.length < 2 || spans.length > 6) return;
                    var total = 0;
                    for (var i = 0; i < spans.length; i++) {
                        if (!Number.isInteger(spans[i]) || [2, 3, 4, 6, 8, 9].indexOf(spans[i]) === -1) return;
                        total += spans[i];
                    }
                    if (total !== 12) return;
                    var target = this.splitTarget;
                    var host = this.splitTargetHost(target);
                    this.closeSectionInsert();
                    // 弹层开着时文档被撤销 / 改动过，原位置不再是同一个列或容器：不猜，提示重选
                    if (!host || host !== this._splitHost) {
                        this.toast(text.changed);
                        return;
                    }
                    var node = this.splitNode(spans);
                    return this.runCommand("split-layout", function () {
                        return this.insertElementAt(node, target, this.elSchema("container").label);
                    });
                },
                // 顶栏「添加布局」（2.1.0）：插在选中区块之后，没选中就加在末尾；与画布「+」同一个选择器
                openLayoutPicker: function (event) {
                    this.openSectionInsert(this.insertIndex(), event);
                },
                chooseSectionTemplate: function () {
                    if (!this.sectionInsertOpen) return;
                    var index = this.resolveSectionInsert();
                    if (index === null) return;
                    this._insertAt = index;
                    this.openPrebuiltSections();
                },
                resolveSectionInsert: function () {
                    var index = this.sectionInsertAfterId === null ? 0
                        : this.sections.findIndex(function (section) { return section.id === this.sectionInsertAfterId; }, this) + 1;
                    this.closeSectionInsert();
                    if (this.sectionInsertAfterId !== null && index === 0) {
                        this.toast(text.changed);
                        return null;
                    }
                    return index;
                },
                insertAtBoundary: function (payload) {
                    if (payload.kind === "picker") {
                        this.openSectionInsert(payload.index, null, payload.anchor);
                        return;
                    }
                    this._insertAt = payload.index;
                    if (payload.kind === "templates") {
                        this.openPrebuiltSections();
                        return;
                    }
                    var spans = payload.kind === "layout" && Array.isArray(payload.spans) ? payload.spans : 1;
                    this.addSection(spans);
                }
            };
        }
    };
})(window);
