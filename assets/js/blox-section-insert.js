(function (root) {
    "use strict";
    root.YikaiBloxSectionInsert = {
        mixin: function (text) {
            return {
                sectionInsertOpen: false,
                sectionInsertAfterId: null,
                sectionInsertLabel: "",
                sectionInsertStyle: "",
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
                    this.sectionInsertAfterId = previous ? previous.id : null;
                    this.sectionInsertLabel = previous
                        ? text.after.replace(":name", this.sectionLabel(previous, index - 1)) : text.start;
                    var opener = event && event.currentTarget;
                    var rect = opener ? opener.getBoundingClientRect() : this.$refs.canvas.getBoundingClientRect();
                    var x = rect.left + rect.width / 2;
                    var y = rect.bottom + 6;
                    if (canvasAnchor) {
                        var scale = rect.width / (this.$refs.canvas.clientWidth || rect.width || 1);
                        x = rect.left + canvasAnchor.x * scale;
                        y = rect.top + canvasAnchor.y * scale + 6;
                    }
                    this._sectionInsertOpener = opener || this.$refs.canvas;
                    var width = Math.min(304, window.innerWidth - 24);
                    var left = Math.max(12, Math.min(x - width / 2, window.innerWidth - width - 12));
                    var top = Math.max(12, Math.min(y, window.innerHeight - 300));
                    this.sectionInsertStyle = "width:" + width + "px;left:" + left + "px;top:" + top + "px";
                    this.sectionInsertOpen = true;
                    var self = this;
                    this.$nextTick(function () {
                        var first = self.$refs.sectionInsertPicker.querySelector('[data-layout-choice]');
                        if (first) first.focus();
                    });
                },
                closeSectionInsert: function () {
                    this.sectionInsertOpen = false;
                    if (this._sectionInsertOpener && this._sectionInsertOpener.isConnected) this._sectionInsertOpener.focus();
                },
                chooseSectionLayout: function (count) {
                    if (!this.sectionInsertOpen || !Number.isInteger(count) || count < 1 || count > 6) return;
                    var index = this.resolveSectionInsert();
                    if (index === null) return;
                    this._insertAt = index;
                    try { this.addSection(count); } finally { this._insertAt = null; }
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
