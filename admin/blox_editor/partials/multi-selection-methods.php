<?php
declare(strict_types=1);
?>
            multiSelModule() {
                var M = window.YikaiBloxMultiSelect;
                return M && typeof M.applyClick === "function" && typeof M.create === "function" ? M : null;
            },

            multiSelActive() {
                var M = this.multiSelModule();
                return !!(M && M.active(this.multiSel));
            },

            batchClipboardCount() {
                return this.batchClipboard && Array.isArray(this.batchClipboard.items) ? this.batchClipboard.items.length : 0;
            },

            multiSelCount() {
                var M = this.multiSelModule();
                return M ? M.count(this.multiSel) : 0;
            },

            isMultiSelected(id) {
                var M = this.multiSelModule();
                return !!(M && M.has(this.multiSel, id) && M.active(this.multiSel));
            },

            /** 只折叠「激活」的多选集合；休眠锚点保留（shift 区间从上次单击项起算）。单选入口调用。 */
            multiSelClear() {
                if (this._keepMulti) return;
                var M = this.multiSelModule();
                if (!M || !M.active(this.multiSel)) return;
                this.multiSel = M.create();
                this.syncMultiSelectionToCanvas();
            },

            /** 全量重置（Esc / 文档变化）：休眠锚点一并清掉。 */
            multiSelReset() {
                if (this._keepMulti) return;
                var M = this.multiSelModule();
                if (!M || !this.multiSel || !this.multiSel.ids || !this.multiSel.ids.length) return;
                this.multiSel = M.create();
                this.syncMultiSelectionToCanvas();
            },

            /** 普通点击：留下休眠锚点供后续 shift 区间起算（不改变单选的任何可见行为）。 */
            multiPlainClick(level, parent, id, siblings) {
                var M = this.multiSelModule();
                if (!M) return;
                this.multiSel = M.applyClick(this.multiSel, {
                    mode: "plain", level: level, parent: parent, id: id, siblings: siblings,
                }).state;
            },

            syncMultiSelectionToCanvas() {
                var M = this.multiSelModule();
                var ids = M && M.active(this.multiSel) ? this.multiSel.ids.slice(0, 100) : [];
                this.canvasBridge().post({ ykMultiIds: ids });
            },

            /** 修饰键点击入口：shift=区间、ctrl/cmd=增减；普通点击返回 false 走原单选。siblings=同父级文档序稳定 id。 */
            multiModClick(event, level, parent, id, siblings) {
                if (!event || (!event.shiftKey && !event.ctrlKey && !event.metaKey)) {
                    this.multiPlainClick(level, parent, id, siblings);
                    return false;
                }
                var M = this.multiSelModule();
                if (!M || !id || !Array.isArray(siblings) || siblings.indexOf(id) === -1) {
                    this.multiSelClear();
                    return false;
                }
                var result = M.applyClick(this.multiSel, {
                    mode: event.shiftKey ? "shift" : "toggle",
                    level: level,
                    parent: parent,
                    id: id,
                    siblings: siblings,
                });
                this.multiSel = result.state;
                this.syncMultiSelectionToCanvas();
                return true;
            },

            elementScopeAt(si, ci) {
                var section = this.sections[si];
                var column = section && section.columns ? section.columns[ci] : null;
                if (!section || !column || !section.id || !column.id) return null;
                return {
                    parent: String(section.id) + "/" + String(column.id),
                    siblings: (column.elements || []).map(function (el) { return String(el.id || ""); }),
                };
            },

            childScopeAt(si, ci, ei) {
                var section = this.sections[si];
                var column = section && section.columns ? section.columns[ci] : null;
                var host = column && column.elements ? column.elements[ei] : null;
                if (!host || !host.id) return null;
                var children = (host.data && host.data.children) || [];
                return {
                    parent: "children:" + String(host.id),
                    siblings: children.map(function (child) { return String(child.id || ""); }),
                };
            },

            sectionScope() {
                return {
                    parent: "root",
                    siblings: this.sections.map(function (section) { return String(section.id || ""); }),
                };
            },

            treeSectionClick(event, si) {
                var scope = this.sectionScope();
                if (!this.multiModClick(event, "section", scope.parent, String((this.sections[si] || {}).id || ""), scope.siblings)) {
                    this.selectSectionFromTree(si);
                    return;
                }
                this._keepMulti = true;
                this.selectSectionFromTree(si);
                this._keepMulti = false;
            },

            treeElementClick(event, si, ci, ei) {
                var scope = this.elementScopeAt(si, ci);
                if (!this.multiModClick(event, "element", scope ? scope.parent : "", this.elementIdAt(si, ci, ei), scope ? scope.siblings : [])) {
                    this.selectElement(si, ci, ei);
                    return;
                }
                this._keepMulti = true;
                this.selectElement(si, ci, ei);
                this._keepMulti = false;
            },

            treeChildClick(event, si, ci, ei, cei) {
                var scope = this.childScopeAt(si, ci, ei);
                if (!this.multiModClick(event, "child", scope ? scope.parent : "", this.childIdAt(si, ci, ei, cei), scope ? scope.siblings : [])) {
                    this.selectChild(si, ci, ei, cei);
                    return;
                }
                this._keepMulti = true;
                this.selectChild(si, ci, ei, cei);
                this._keepMulti = false;
            },

            elementIdAt(si, ci, ei) {
                var section = this.sections[si];
                var column = section && section.columns ? section.columns[ci] : null;
                var el = column && column.elements ? column.elements[ei] : null;
                return el && el.id ? String(el.id) : "";
            },

            childIdAt(si, ci, ei, cei) {
                var section = this.sections[si];
                var column = section && section.columns ? section.columns[ci] : null;
                var el = column && column.elements ? column.elements[ei] : null;
                var children = el && el.data && el.data.children ? el.data.children : [];
                var child = children[cei];
                return child && child.id ? String(child.id) : "";
            },

            /** 画布带修饰键的元素点击（path 深度区分元素/子元素）；无修饰键走原单选。 */
            canvasPickElement(target) {
                if (target && target.mods && (target.mods.shift || target.mods.toggle)) {
                    var parts = String(target.path || "").split(".").map(function (v) { return parseInt(v, 10); });
                    if (parts.length === 4) {
                        var childScope = this.childScopeAt(parts[0], parts[1], parts[2]);
                        var childId = this.childIdAt(parts[0], parts[1], parts[2], parts[3]);
                        if (this.multiModClick(this.modsFrom(target.mods), "child", childScope ? childScope.parent : "", childId, childScope ? childScope.siblings : [])) {
                            this._keepMulti = true;
                            this.selectChild(parts[0], parts[1], parts[2], parts[3], false);
                            this._keepMulti = false;
                            return;
                        }
                    } else if (parts.length === 3) {
                        var scope = this.elementScopeAt(parts[0], parts[1]);
                        if (this.multiModClick(this.modsFrom(target.mods), "element", scope ? scope.parent : "", this.elementIdAt(parts[0], parts[1], parts[2]), scope ? scope.siblings : [])) {
                            this._keepMulti = true;
                            this.selectElement(parts[0], parts[1], parts[2], false);
                            this._keepMulti = false;
                            return;
                        }
                    }
                }
                this.multiSelPlainFallback(target, "element");
                this.selectElementTarget(target, false);
            },

            canvasPickSection(target) {
                if (target && target.mods && (target.mods.shift || target.mods.toggle)) {
                    var scope = this.sectionScope();
                    if (this.multiModClick(this.modsFrom(target.mods), "section", scope.parent, String(target.id || ""), scope.siblings)) {
                        this._keepMulti = true;
                        this.selectSectionTarget(target, false);
                        this._keepMulti = false;
                        return;
                    }
                }
                this.multiSelPlainFallback(target, "section");
                this.selectSectionTarget(target, false);
            },

            /** 画布普通点击：与树普通点击同语义——留休眠锚点，再走原单选。 */
            multiSelPlainFallback(target, level) {
                var parts = String((target && target.path) || "").split(".").map(function (v) { return parseInt(v, 10); });
                if (level === "section") {
                    var scope = this.sectionScope();
                    this.multiPlainClick("section", scope.parent, String((target && target.id) || ""), scope.siblings);
                    return;
                }
                if (parts.length === 4) {
                    var childScope = this.childScopeAt(parts[0], parts[1], parts[2]);
                    this.multiPlainClick("child", childScope ? childScope.parent : "", this.childIdAt(parts[0], parts[1], parts[2], parts[3]), childScope ? childScope.siblings : []);
                    return;
                }
                var elScope = this.elementScopeAt(parts[0], parts[1]);
                this.multiPlainClick("element", elScope ? elScope.parent : "", this.elementIdAt(parts[0], parts[1], parts[2]), elScope ? elScope.siblings : []);
            },

            modsFrom(mods) {
                return { shiftKey: !!(mods && mods.shift), ctrlKey: !!(mods && mods.toggle), metaKey: false };
            },

            // 批量数组运算在独立模块，这里只保留命令桥接。
            actionsModule() {
                var A = window.YikaiBloxMultiActions;
                return A
                    && typeof A.removeByIds === "function"
                    && typeof A.appendCloned === "function"
                    && typeof A.planBatchAction === "function"
                    && typeof A.planPaste === "function" ? A : null;
            },

            multiScopeContext() {
                var A = this.actionsModule();
                if (!A) return null;
                return A.scopeContext(this.sections, this.multiSel.level, this.multiSel.parent);
            },

            replaceList(list, next) {
                list.length = 0;
                for (var i = 0; i < next.length; i++) list.push(next[i]);
            },

            batchDelete() {
                var A = this.actionsModule();
                if (!A || !this.multiSelActive()) return;
                this.runCommand("batch-delete", function () { this._runBatchActionRaw("delete", A); });
            },

            batchDuplicate() {
                var A = this.actionsModule();
                if (!A || !this.multiSelActive()) return;
                this.runCommand("batch-duplicate", function () { this._runBatchActionRaw("duplicate", A); });
            },

            batchCut() {
                var A = this.actionsModule();
                if (!A || !this.multiSelActive()) return;
                this.runCommand("batch-cut", function () { this._runBatchActionRaw("cut", A); });
            },

            batchPaste() {
                var A = this.actionsModule();
                if (!A) return;
                this.runCommand("batch-paste", function () { this._runBatchPasteRaw(A); });
            },

            batchDone(count, label) {
                this.toast(this.multiText[label].replace(":count", count));
            },

            _runBatchActionRaw(kind, A) {
                var ctx = this.multiScopeContext();
                var ids = this.multiSel ? this.multiSel.ids.slice() : [];
                if (!ctx || !ids.length) { this.toast(this.multiText.failed); return; }
                var isSteps = !!(ctx.host && ctx.host.type === "process-steps");
                var plan = A.planBatchAction(kind, ctx.list, ids, this.batchIdFactory(), ctx.level === "section" ? "section" : "element", isSteps ? 20 : 0, isSteps ? 1 : 0);
                if (plan.error === "minimum") { this.toast(this.processText.minimum); return; }
                if (plan.error === "limit") { this.toast(this.processText.limit); return; }
                if (plan.error) { this.toast(this.multiText.failed); return; }
                if (kind === "cut") {
                    this.batchClipboard = { level: ctx.level, parent: ctx.parent, items: JSON.parse(JSON.stringify(plan.picked)) };
                }
                this.replaceList(ctx.list, plan.list);
                if (kind === "delete" || kind === "cut") this.deselectAll(); // 复制后保留原选择
                if (isSteps) this.syncProcessNumbersFor(ctx.host);
                var label = kind === "delete" ? "deleteDone" : (kind === "duplicate" ? "duplicateDone" : "cutDone");
                var count = kind === "duplicate" ? plan.newIds.length : plan.removed;
                this.batchDone(count, label);
            },

            batchIdFactory() {
                var self = this;
                return function (kind) {
                    if (kind === "section") return self.uid("s");
                    if (kind === "column") return self.uid("c");
                    return self.uid("e");
                };
            },

            pasteTargetContext(clipLevel) {
                if (clipLevel === "section") {
                    return { level: "section", list: this.sections };
                }
                var path = this.selectedPath();
                if (!path) return null;
                var parts = path.split(".");
                if (clipLevel === "element" && parts.length === 3) {
                    var scope = this.elementScopeAt(parseInt(parts[0], 10), parseInt(parts[1], 10));
                    if (!scope) return null;
                    var column = this.sections[parseInt(parts[0], 10)].columns[parseInt(parts[1], 10)];
                    return { level: "element", list: column.elements };
                }
                if (clipLevel === "child" && parts.length === 4) {
                    var host = this.sections[parseInt(parts[0], 10)].columns[parseInt(parts[1], 10)].elements[parseInt(parts[2], 10)];
                    if (!host) return null;
                    host.data.children = host.data.children || [];
                    return { level: "child", list: host.data.children, host: host };
                }
                return null;
            },

            _runBatchPasteRaw(A) {
                var clip = this.batchClipboard;
                if (!clip || !clip.items || !clip.items.length) { this.toast(this.multiText.pasteRejected); return; }
                var target = this.pasteTargetContext(clip.level);
                if (!target) { this.toast(this.multiText.pasteRejected); return; }
                var self = this;
                var isChild = target.level === "child";
                var host = target.host || null;
                // 与单项粘贴同一容器许可（canNest 逐项校验在模块内）
                if (isChild && (!host || !this.elSchema(host.type).container)) { this.toast(this.multiText.pasteRejected); return; }
                var plan = A.planPaste(target.list, clip.items, this.batchIdFactory(), clip.level === "section" ? "section" : "element", {
                    maxCount: isChild && host.type === "process-steps" ? 20 : 0,
                    canNest: isChild ? function (item) { return self.canNestElement(host, item); } : null,
                });
                if (plan.error === "limit") { this.toast(this.processText.limit); return; }
                if (plan.error) { this.toast(this.multiText.pasteRejected); return; }
                this.replaceList(target.list, plan.list);
                // Banner 宿主切自定义数据，否则前台仍渲染继承项
                if (isChild && this.isHomeBannerHost(host)) host.data.items_mode = "custom";
                if (isChild && host.type === "process-steps") this.syncProcessNumbersFor(host);
                this.batchDone(plan.newIds.length, "pasteDone");
            },

