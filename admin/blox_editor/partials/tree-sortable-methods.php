<?php

declare(strict_types=1);
?>
            initTreeSortable() {
                if (typeof Sortable === "undefined" || !this.$refs.tree) return;
                if (Sortable.active) {
                    this.scheduleTreeSortable(50);
                    return;
                }
                // 挂拖拽的容器还是同一批 DOM 节点（改文字、改样式时 x-for 按 key 复用节点）就不重建：
                // Sortable 自己处理容器里增减的项，回调里的位置也是事件发生时才从 dataset 读。
                // 2.1.3 实测 2000 个元素的页面，每次按键在这里整棵重绑要 20 多 ms。
                var secRootNow = this.$refs.tree.querySelector("[data-sort-sections]") || this.$refs.tree;
                // 一次查询按文档顺序取全部容器（每次按键都要跑，三遍查询在大页面上要 4 ms 多）
                var containersNow = [secRootNow]
                    .concat(Array.prototype.slice.call(this.$refs.tree.querySelectorAll("[data-sort-elements], [data-sort-children]")));
                // 记在树的 DOM 节点上而不是 Alpine 状态里：不进响应式，也不会被代理包一层
                var bound = this.$refs.tree._ykSortableContainers || [];
                if (this._sortables.length && bound.length === containersNow.length
                    && containersNow.every(function (el, i) { return bound[i] === el && el.isConnected; })) {
                    return;
                }
                this.$refs.tree._ykSortableContainers = containersNow;
                this._sortables.forEach(function (s) {
                    try {
                        var sortable = window.Alpine && typeof Alpine.raw === "function" ? Alpine.raw(s) : s;
                        sortable.destroy();
                    } catch (e) {}
                });
                this._sortables = [];
                var self = this;
                var secRoot = this.$refs.tree.querySelector("[data-sort-sections]") || this.$refs.tree;
                this._sortables.push(new Sortable(secRoot, {
                    animation: 150,
                    draggable: "[data-section-id]",
                    handle: "[data-section-drag-handle]",
                    onEnd: function (evt) { self.sortSections((evt.oldDraggableIndex ?? evt.oldIndex), (evt.newDraggableIndex ?? evt.newIndex)); }
                }));
                this.$refs.tree.querySelectorAll("[data-sort-elements]").forEach(function (el) {
                    self._sortables.push(new Sortable(el, {
                        animation: 150,
                        group: { name: "blox-elements", pull: true, put: true },
                        ghostClass: "blox-sort-ghost",
                        draggable: "[data-sort-el-item]",
                        handle: "[data-element-drag-handle]",
                        onEnd: function (evt) {
                            var from = evt.from || el;
                            var to = evt.to || el;
                            self.moveElementsBetweenColumns(
                                parseInt(from.dataset.si, 10), parseInt(from.dataset.ci, 10),
                                parseInt(to.dataset.si, 10), parseInt(to.dataset.ci, 10),
                                (evt.oldDraggableIndex ?? evt.oldIndex), (evt.newDraggableIndex ?? evt.newIndex)
                            );
                        }
                    }));
                });
                this.$refs.tree.querySelectorAll("[data-sort-children]").forEach(function (el) {
                    self._sortables.push(new Sortable(el, {
                        animation: 150,
                        draggable: "[data-sort-child-item]",
                        handle: "[data-child-drag-handle]",
                        onEnd: function (evt) {
                            // 0b：嵌套容器的子列表带 data-sub-path（拥有者路径）；顶层列表为空数组
                            var ownerSubPath = [];
                            try { ownerSubPath = JSON.parse(el.dataset.subPath || "[]"); } catch (e) { ownerSubPath = []; }
                            if (!Array.isArray(ownerSubPath)) ownerSubPath = [];
                            self.sortChildrenAt(
                                parseInt(el.dataset.si, 10), parseInt(el.dataset.ci, 10), parseInt(el.dataset.ei, 10),
                                ownerSubPath,
                                (evt.oldDraggableIndex ?? evt.oldIndex), (evt.newDraggableIndex ?? evt.newIndex)
                            );
                        }
                    }));
                });
            },

            scheduleTreeSortable(delay) {
                var self = this;
                var wait = Math.max(0, parseInt(delay, 10) || 0);
                this.$nextTick(function() {
                    if (self._treeSortableTimer) clearTimeout(self._treeSortableTimer);
                    self._treeSortableTimer = setTimeout(function() {
                        self._treeSortableTimer = null;
                        self.initTreeSortable();
                    }, wait);
                });
            },

