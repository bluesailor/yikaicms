<?php
/**
 * 元素库偏好与元素面板交互方法（编辑器状态层拆分 · 0a 启动）。
 *
 * 归属 bloxEditor() 数据对象：收藏/最近使用的本地存储、面板输入模式、点击/拖拽插入、
 * PRO 锁定提示与拖拽幽灵。从 admin/blox_editor.php 原样拆出，保持方法体不变。
 */
?>
            restoreElementLibraryPreferences() {
                var known = {};
                this.elementLib.forEach(function (el) {
                    if (!el.deprecated) known[el.type] = true;
                });
                var read = function (key) {
                    try {
                        var value = JSON.parse(window.localStorage.getItem(key) || "[]");
                        if (!Array.isArray(value)) return [];
                        return value.filter(function (type, index) {
                            return typeof type === "string" && known[type] && value.indexOf(type) === index;
                        });
                    } catch (error) {
                        return [];
                    }
                };
                this.favoriteElementTypes = read(this.favoriteElementsStorageKey);
                this.recentElementTypes = read(this.recentElementsStorageKey).slice(0, 6);
            },

            persistElementLibraryPreferences() {
                try {
                    window.localStorage.setItem(this.favoriteElementsStorageKey, JSON.stringify(this.favoriteElementTypes));
                    window.localStorage.setItem(this.recentElementsStorageKey, JSON.stringify(this.recentElementTypes));
                } catch (error) {
                    // 禁用存储时仍保留本次编辑会话内的快捷分组。
                }
            },

            isElementFavorite(type) {
                return this.favoriteElementTypes.indexOf(type) !== -1;
            },

            toggleElementFavorite(type) {
                var index = this.favoriteElementTypes.indexOf(type);
                if (index === -1) this.favoriteElementTypes.push(type);
                else this.favoriteElementTypes.splice(index, 1);
                this.persistElementLibraryPreferences();
            },

            rememberRecentElement(type) {
                if (!type) return;
                this.recentElementTypes = [type].concat(this.recentElementTypes.filter(function (item) {
                    return item !== type;
                })).slice(0, 6);
                this.persistElementLibraryPreferences();
            },

            // 触屏 / 窄屏标记：只影响「先选区块」提示的展示方式，插入逻辑桌面与触屏一致。
            syncPaletteInputMode() {
                this.paletteTapMode = window.innerWidth <= 1023
                    || !!(window.matchMedia && window.matchMedia("(pointer: coarse)").matches);
            },

            // 单击元素即插入到当前选中位置（2.0 新手：单击只弹「请拖动」会让人以为坏了）。
            // 不猜位置：页面已有区块却没选中任何一个时，提示先选区块。拖拽照常可用。
            activatePaletteElement(el, event) {
                if (!el) return;
                if (el.locked) {
                    this.paletteSelected = "";
                    this.toast(this.professionalLockedMessage(el.proFeature));
                    return;
                }
                this.paletteSelected = el.type;
                if (this.sections.length > 0 && this.selectedSi < 0) {
                    this.paletteSelected = "";
                    this.toast(this.uiText.pickSectionFirst);
                    return;
                }
                this.quickAddTargetId = "";
                this.addElement(el);
                this.paletteSelected = "";
            },

            /**
             * 目标列下标。夹在有效范围内——换了区块后 targetCi 可能越界
             * （比如从 3 列区块切到 1 列），越界会把元素塞进不存在的列里丢掉。
             */
            colIndex() {
                var s = this.sel;
                if (!s || !s.columns.length) return 0;
                return Math.min(Math.max(this.targetCi, 0), s.columns.length - 1);
            },

            // ── 元素库分类折叠态（Bricks 式；默认全开，搜索时强制全开） ──
            catOpen: {},
            isCatOpen(cat) { return this.catOpen[cat] !== false; },

            // ── 拖拽插入（路线图③）：库瓦片拖到结构树/画布 ──
            dragEl: null,          // 正在拖的库条目
            templateDragItem: null,
            canvasDragActive: false,
            treeDropIntent: null,  // {key, intent, target, valid, label}，与画布使用同一目标协议

            clearPaletteDragGhost() {
                if (this._paletteDragGhost && this._paletteDragGhost.parentNode) {
                    this._paletteDragGhost.parentNode.removeChild(this._paletteDragGhost);
                }
                this._paletteDragGhost = null;
            },

            createPaletteDragGhost(el, event) {
                this.clearPaletteDragGhost();
                if (!event || !event.dataTransfer || typeof event.dataTransfer.setDragImage !== "function") return;
                var iconName = String(el.icon || "box").replace(/[^a-z0-9-]/gi, "") || "box";
                var ghost = document.createElement("div");
                ghost.setAttribute("data-testid", "blox-palette-drag-ghost");
                ghost.setAttribute("aria-hidden", "true");
                ghost.className = "blox-palette-drag-ghost";
                var icon = document.createElement("span");
                icon.className = "blox-palette-drag-ghost-icon";
                var iconGlyph = document.createElement("i");
                iconGlyph.className = "ti ti-" + iconName;
                icon.appendChild(iconGlyph);
                var label = document.createElement("span");
                label.className = "blox-palette-drag-ghost-label";
                label.textContent = String(el.label || el.type || "");
                ghost.appendChild(icon);
                ghost.appendChild(label);
                document.body.appendChild(ghost);
                this._paletteDragGhost = ghost;
                try {
                    event.dataTransfer.setDragImage(ghost, 18, 18);
                } catch (error) {
                    this.clearPaletteDragGhost();
                }
            },

            professionalLockedMessage(feature) {
                var state = this.professionalFeatures[feature] || {};
                return state.message || this.uiText.proLocked;
            },

            startPaletteDrag(el, event) {
                if (el && el.locked) {
                    if (event) event.preventDefault();
                    return;
                }
                if (!el || !event || !event.dataTransfer) return;
                this.paletteSelected = el.type;
                this.dragEl = el;
                this.canvasDragActive = true;
                event.dataTransfer.effectAllowed = "copy";
                event.dataTransfer.setData("application/x-yikai-blox", JSON.stringify({
                    version: 1,
                    source: "palette",
                    type: el.type,
                }));
                event.dataTransfer.setData("text/plain", el.type);
                this.createPaletteDragGhost(el, event);
                this.canvasBridge().post({ ykDragType: el.type });
            },

            startTemplateDrag(item, event) {
                if (!this.templateSectionDraggable(item) || !event || !event.dataTransfer) return;
                this.templateDragItem = item;
                this.canvasDragActive = true;
                event.dataTransfer.effectAllowed = "copy";
                event.dataTransfer.setData("application/x-yikai-blox-template", JSON.stringify({
                    version: 1,
                    source: "template",
                    key: item.key,
                }));
                event.dataTransfer.setData("text/plain", item.name || item.key);
                this.createPaletteDragGhost({ type: "section", label: item.name, icon: "layout-grid-add" }, event);
                this.canvasBridge().post({ ykDragType: "__section_template" });
            },

            finishPaletteDrag() {
                this.clearPaletteDragGhost();
                this.paletteSelected = "";
                this.dragEl = null;
                this.templateDragItem = null;
                this.canvasDragActive = false;
                this.treeDropIntent = null;
                this.canvasBridge().post({ ykPaletteDrag: { version: 1, phase: "cancel" } });
                this.canvasBridge().post({ ykDragType: "" });
            },
