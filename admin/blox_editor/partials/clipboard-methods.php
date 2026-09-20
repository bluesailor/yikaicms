<?php
/**
 * 选区剪贴板方法（编辑器状态层拆分 · 0b 第二刀）。
 *
 * 归属 bloxEditor() 数据对象：复制/剪切/粘贴的来源解析、目标解析与执行。
 * 0b 起 child 类来源与目标携带 subPath（嵌套容器的深层子级）。
 * 从 admin/blox_editor.php 原样拆出，保持方法体不变。
 */
?>
            selectionClipboardSource() {
                var s = this.sel;
                var top = this.selTopEl;
                if (!s || !top || this.selectedCi < 0 || this.selectedEi < 0) return null;
                if (this.selectedSubPath.length) {
                    var child = this.selEl;
                    return child && child !== top
                        ? { kind: "child", si: this.selectedSi, ci: this.selectedCi, ei: this.selectedEi,
                            cei: this.selectedSubPath[0], subPath: this.selectedSubPath.slice(), id: child.id, node: child }
                        : null;
                }
                return { kind: "element", si: this.selectedSi, ci: this.selectedCi, ei: this.selectedEi, id: top.id, node: top };
            },

            hasClipboardSelection() {
                return !!this.selectionClipboardSource();
            },

            copySelection() {
                var source = this.selectionClipboardSource();
                if (!source) { this.toast(this.clipboardText.empty); return; }
                this.clipboard = {
                    mode: "copy",
                    kind: source.kind,
                    id: source.id,
                    node: JSON.parse(JSON.stringify(source.node)),
                };
                this.toast(this.clipboardText.copyDone);
            },

            cutSelection() {
                var source = this.selectionClipboardSource();
                if (!source) { this.toast(this.clipboardText.empty); return; }
                this.clipboard = {
                    mode: "cut",
                    kind: source.kind,
                    id: source.id,
                    node: JSON.parse(JSON.stringify(source.node)),
                };
                if (!this.removeClipboardSource(source)) {
                    this.clipboard = null;
                    this.toast(this.clipboardText.sourceMissing);
                    return;
                }
                this.selectedSi = -1;
                this.selectedCi = -1;
                this.selectedEi = -1;
                this.setSubSelection([]);
                this.selectedSectionField = "";
                this.selectedHomeField = "";
                this.selectedHomeColumn = "";
                this.selLayer = "sec";
                this.libOpen = false;
                this.closeCtx();
                this.highlightCanvasSelection(false);
                this.toast(this.clipboardText.cutDone);
            },

            removeClipboardSource(source) {
                if (!source) return false;
                var section = this.sections[source.si];
                var column = section && section.columns ? section.columns[source.ci] : null;
                if (!column) return false;
                if (source.kind === "child") {
                    // 0b：深层子级按 subPath 找到直接父级；单层来源无 subPath 时按旧路径取顶层元素
                    var parent = Array.isArray(source.subPath) && source.subPath.length > 1
                        ? this.subPathParent(source.si, source.ci, source.ei, source.subPath)
                        : column.elements[source.ei];
                    var kids = parent && parent.data ? (parent.data.children || []) : [];
                    var childIndex = kids.findIndex(function (item) { return item && item.id === source.id; });
                    if (childIndex < 0) return false;
                    kids.splice(childIndex, 1);
                    return true;
                }
                var elementIndex = (column.elements || []).findIndex(function (item) { return item && item.id === source.id; });
                if (elementIndex < 0) return false;
                column.elements.splice(elementIndex, 1);
                return true;
            },

            pasteTarget(kind, target) {
                target = target || {};
                if (!this.clipboard) return null;
                if (kind === "child") {
                    // 0b：subPath 存在时粘贴到深层子级的父容器内（其后一位）；否则父级=顶层元素
                    var childSubPath = Array.isArray(target.subPath) && target.subPath.length
                        ? target.subPath : [parseInt(target.cei, 10)];
                    var childParent = childSubPath.length > 1
                        ? this.subPathParent(target.si, target.ci, target.ei, childSubPath)
                        : (((this.sections[target.si] || {}).columns || [])[target.ci] || { elements: [] }).elements[target.ei];
                    if (!childParent || !this.elSchema(childParent.type).container) return null;
                    if (!this.canNestElement(childParent, this.clipboard.node)) return null;
                    var childCount = (childParent.data && childParent.data.children || []).length;
                    return { mode: "child", si: target.si, ci: target.ci, ei: target.ei,
                        ownerSubPath: childSubPath.slice(0, -1),
                        index: Math.min(childCount, Math.max(0, childSubPath[childSubPath.length - 1] + 1)) };
                }
                if (kind === "element") {
                    var elementSection = this.sections[target.si];
                    var elementColumn = elementSection && elementSection.columns ? elementSection.columns[target.ci] : null;
                    var element = elementColumn && elementColumn.elements ? elementColumn.elements[target.ei] : null;
                    if (!element) return null;
                    if (this.elSchema(element.type).container) {
                        if (!this.canNestElement(element, this.clipboard.node)) return null;
                        var elementCount = (element.data && element.data.children || []).length;
                        return { mode: "child", si: target.si, ci: target.ci, ei: target.ei, index: elementCount };
                    }
                    return { mode: "element", si: target.si, ci: target.ci, index: Math.max(0, parseInt(target.ei, 10) + 1) };
                }
                if (kind === "column") {
                    var columnSection = this.sections[target.si];
                    var targetColumn = columnSection && columnSection.columns ? columnSection.columns[target.ci] : null;
                    return targetColumn ? { mode: "element", si: target.si, ci: target.ci, index: targetColumn.elements.length } : null;
                }
                if (kind === "container" || kind === "section") {
                    var section = this.sections[target.si];
                    if (!section || !section.columns || !section.columns.length) return null;
                    var ci = parseInt(target.ci, 10);
                    if (isNaN(ci)) ci = this.selectedSi === target.si ? this.targetCi : 0;
                    ci = Math.min(Math.max(ci, 0), section.columns.length - 1);
                    return { mode: "element", si: target.si, ci: ci, index: section.columns[ci].elements.length };
                }
                if (kind === "canvas") {
                    if (this.sections.length === 0) return { mode: "new-section" };
                    var si = this.selectedSi >= 0 ? this.selectedSi : this.sections.length - 1;
                    var current = this.sections[si];
                    var targetCi = current && current.columns ? Math.min(Math.max(this.targetCi, 0), current.columns.length - 1) : 0;
                    return current && current.columns[targetCi] ? { mode: "element", si: si, ci: targetCi, index: current.columns[targetCi].elements.length } : null;
                }
                return null;
            },

            canPasteTo(kind, target) {
                return !!this.pasteTarget(kind, target);
            },

            pasteClipboard(kind, target) {
                if (!this.clipboard) { this.toast(this.clipboardText.empty); return; }
                var destination = this.pasteTarget(kind, target);
                if (!destination) { this.toast(this.clipboardText.invalid); return; }
                if (destination.mode === "new-section") {
                    this.addSection(1, true);
                    destination = this.pasteTarget("section", { si: this.selectedSi, ci: 0 });
                }
                if (!destination) { this.toast(this.clipboardText.invalid); return; }
                var node = this.deepCloneNode(this.clipboard.node, "e");
                if (destination.mode === "child") {
                    var ownerSubPath = Array.isArray(destination.ownerSubPath) ? destination.ownerSubPath : [];
                    var parent = this.sections[destination.si].columns[destination.ci].elements[destination.ei];
                    for (var oi = 0; parent && oi < ownerSubPath.length; oi++) {
                        parent = ((parent.data || {}).children || [])[ownerSubPath[oi]] || null;
                    }
                    if (!parent) { this.toast(this.clipboardText.invalid); return; }
                    parent.data.children = parent.data.children || [];
                    if (this.isHomeBannerHost(parent)) parent.data.items_mode = "custom";
                    parent.data.children.splice(destination.index, 0, node);
                    this.selectDescendant(destination.si, destination.ci, destination.ei,
                        ownerSubPath.concat(destination.index), false);
                } else {
                    var elements = this.sections[destination.si].columns[destination.ci].elements;
                    var index = Math.min(elements.length, Math.max(0, destination.index));
                    elements.splice(index, 0, node);
                    this.selectElement(destination.si, destination.ci, index, false);
                }
                if (this.clipboard.mode === "cut") this.clipboard = null;
                this.closeCtx();
                this.toast(this.clipboardText.pasteDone);
            },

            pasteSelection() { return this.runCommand("paste", function () { return this._pasteSelectionRaw(); }); },
            _pasteSelectionRaw() {
                var target = null;
                if (this.selectedSubPath.length && this.selTopEl) {
                    target = { kind: "child", si: this.selectedSi, ci: this.selectedCi, ei: this.selectedEi,
                        cei: this.selectedSubEi, subPath: this.selectedSubPath.slice() };
                } else if (this.selTopEl) {
                    target = { kind: "element", si: this.selectedSi, ci: this.selectedCi, ei: this.selectedEi };
                } else if (this.selLayer === "col" && this.selectedCi >= 0) {
                    target = { kind: "column", si: this.selectedSi, ci: this.selectedCi };
                } else if (this.selectedSi >= 0) {
                    target = { kind: "section", si: this.selectedSi, ci: this.targetCi };
                } else {
                    target = { kind: "canvas" };
                }
                this.pasteClipboard(target.kind, target);
            },
