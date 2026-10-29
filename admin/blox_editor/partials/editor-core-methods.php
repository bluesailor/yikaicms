<?php

declare(strict_types=1);
?>
            allowedChildTypes(host) {
                var schema = this.elSchema(host && host.type);
                var allowed = Array.isArray(schema.allowedChildren) ? schema.allowedChildren.slice() : [];
                var data = (host && host.data) || {};
                (schema.childRules || []).forEach(function(rule) {
                    var actual = data[rule.field];
                    var matches = rule.operator === "!="
                        ? actual !== rule.value
                        : (Array.isArray(rule.value) ? rule.value.indexOf(actual) !== -1 : actual === rule.value);
                    if (matches) {
                        allowed = Array.isArray(rule.allowedChildren) ? rule.allowedChildren.slice() : [];
                    }
                });
                return allowed;
            },

            canNestElement(host, node) {
                if (!host || !node || !this.elSchema(host.type).container) return false;
                var childSchema = this.elSchema(node.type);
                if (childSchema.deprecated) return false;
                var allowed = this.allowedChildTypes(host);
                if (allowed.indexOf(node.type) !== -1) return true;
                return allowed.indexOf("*") !== -1
                    && !childSchema.container
                    && childSchema.genericChild !== false
                    && childSchema.paletteVisible === true;
            },

            supportsBoxStyles(type) {
                return this.elSchema(type).supportsBoxStyles === true;
            },

            boxSpacingOptions(allowAuto) {
                return allowAuto ? this.boxSpacingBase : this.boxSpacingBase.filter(function (opt) {
                    return opt.k !== "auto";
                });
            },

            /**
             * 间距四档响应式（2.0.4）：画布切到平板 / 手机 / 宽屏时，间距面板读写该档的值。
             * 桌面且没有分档时仍存字符串（与改版前一致）；分档存 {d,t,m,w}，只剩桌面档时收回成字符串。
             * 读取按继承：平板←桌面、手机←平板、宽屏←桌面（与渲染 AbstractElement::boxSpacing 一致）。
             */
            boxGet(key) {
                var raw = this.selEl && this.selEl.data ? this.selEl.data[key] : undefined;
                if (typeof raw === "string") return raw;
                if (!raw || typeof raw !== "object") return "";
                var d = raw.d || "", t = raw.t || d, m = raw.m || t, w = raw.w || d;
                return { d: d, t: t, m: m, w: w }[this.responsiveDeviceKey()] || "";
            },

            boxSet(key, value) {
                if (!this.selEl || !this.selEl.data) return;
                var data = this.selEl.data, raw = data[key], device = this.responsiveDeviceKey();
                if (device === "d" && (raw === undefined || raw === null || typeof raw === "string")) {
                    if (value === "") delete data[key]; else data[key] = value;
                    return;
                }
                var slots = typeof raw === "string" ? { d: raw } : (raw && typeof raw === "object" ? Object.assign({}, raw) : {});
                if (value === "") delete slots[device]; else slots[device] = value;
                var filled = Object.keys(slots).filter(function (k) { return slots[k] !== "" && slots[k] !== undefined && slots[k] !== null; });
                if (filled.length === 0) delete data[key];
                else if (filled.length === 1 && filled[0] === "d") data[key] = slots.d;
                else data[key] = slots;
            },

            /** 当前编辑的不是桌面档时，面板顶部提示正在改哪一档 */
            boxDeviceHint() {
                var device = this.responsiveDeviceKey();
                if (device === "d") return "";
                return this.responsiveDeviceRangeLabel(this.previewDevice);
            },

            setBoxSpacing(kind, side, value) {
                if (!this.selEl || !this.selEl.data) return;
                var key = "style_" + kind + (side ? "_" + side : "");
                if (value === "exact") {
                    this.boxSet(key, "");
                    this.clearBoxSides(kind);
                    this.boxOpen[kind] = false;
                    this.boxExactOpen[kind] = true;
                    return;
                }
                if (value === "custom") {
                    // 四边独立设置：清掉总值、展开该类自己的四边环
                    this.boxSet(key, "");
                    this.boxExactOpen[kind] = false;
                    this.boxOpen[kind] = true;
                    return;
                }
                this.boxSet(key, value);
                if (!side) {
                    this.clearBoxSides(kind);
                    this.boxOpen[kind] = false;
                    this.boxExactOpen[kind] = false;
                }
            },

            clearBoxSides(kind) {
                if (!this.selEl || !this.selEl.data) return;
                var self = this;
                ["top", "right", "bottom", "left"].forEach(function (s2) { self.boxSet("style_" + kind + "_" + s2, ""); });
            },

            hasKindSides(kind) {
                if (!this.selEl || !this.selEl.data) return false;
                var self = this;
                return ["top", "right", "bottom", "left"].some(function (s2) {
                    return self.boxGet("style_" + kind + "_" + s2) !== "";
                });
            },

            kindBoxVisible(kind) { return this.boxOpen[kind] || this.hasKindSides(kind); },

            resetBoxPanelState() {
                this.boxOpen.margin = false;
                this.boxOpen.padding = false;
                this.boxExactOpen.margin = false;
                this.boxExactOpen.padding = false;
            },

            /** 总值 select 显示：该类存在任一四边值 → 显示「自定义」档；否则显示总值档位 */
            spacingSelectValue(kind, side) {
                var self = this;
                if (!side) {
                    var hasSides = ["top", "right", "bottom", "left"].some(function (s2) {
                        return self.boxGet("style_" + kind + "_" + s2) !== "";
                    });
                    if (hasSides) return "custom";
                }
                var v = this.boxGet("style_" + kind + (side ? "_" + side : ""));
                if (v === "") return "";
                var known = this.boxSpacingBase.some(function (opt) {
                    return opt.k !== "exact" && opt.k !== "custom" && opt.k === v;
                });
                return known ? v : "exact";
            },

            kindExactVisible(kind) {
                if (this.boxExactOpen[kind]) return true;
                var value = this.boxGet("style_" + kind);
                if (value === "") return false;
                return !this.boxSpacingBase.some(function (opt) {
                    return opt.k !== "exact" && opt.k !== "custom" && opt.k === value;
                });
            },

            boxOverallDisplay(kind) {
                var value = this.boxGet("style_" + kind);
                return this.spacingRegex(kind).test(String(value || "")) ? value : "";
            },

            setBoxOverall(kind, ev) {
                if (!this.selEl || !this.selEl.data) return;
                var key = "style_" + kind;
                var raw = String(ev.target.value).trim();
                if (raw === "") {
                    this.boxSet(key, "");
                    this.boxExactOpen[kind] = false;
                    return;
                }
                if (!this.spacingRegex(kind).test(raw)) {
                    this.toast(runtimeText.spacingInvalid);
                    ev.target.value = this.boxOverallDisplay(kind);
                    return;
                }
                this.clearBoxSides(kind);
                this.boxSet(key, raw);
                this.boxOpen[kind] = false;
                this.boxExactOpen[kind] = true;
            },

            spacingRegex(kind) {
                return kind === "margin"
                    ? /^(-?\d{1,4}(\.\d{1,2})?(px|rem|em|%|vw|vh)|auto|0)$/
                    : /^(\d{1,4}(\.\d{1,2})?(px|rem|em|%|vw|vh)|0)$/;
            },

            /** 盒模型输入框显示值（档位或精确值原样显示，空=未设置） */
            boxSideDisplay(kind, side) {
                return this.boxGet("style_" + kind + "_" + side);
            },

            /** 盒模型输入提交：档位关键词或精确值皆可；非法值提示并回显原值 */
            setBoxSide(kind, side, ev) {
                if (!this.selEl || !this.selEl.data) return;
                var key = "style_" + kind + "_" + side;
                var raw = String(ev.target.value).trim();
                if (raw === "") { this.boxSet(key, ""); return; }
                var tokenOk = this.boxSpacingBase.some(function (o) { return o.k === raw && o.k !== "" && o.k !== "custom"; });
                if (tokenOk && !(kind === "padding" && raw === "auto")) { this.boxSet(key, raw); return; }
                if (this.spacingRegex(kind).test(raw)) { this.boxSet(key, raw); return; }
                this.toast(runtimeText.spacingInvalid);
                ev.target.value = this.boxSideDisplay(kind, side);
            },

            resetBoxSpacing() {
                if (!this.selEl || !this.selEl.data) return;
                var data = this.selEl.data;
                var self = this;
                ["margin", "padding"].forEach(function (kind) {
                    delete data["style_" + kind];
                    ["top", "right", "bottom", "left"].forEach(function (side) {
                        delete data["style_" + kind + "_" + side];
                    });
                    self.boxOpen[kind] = false;
                    self.boxExactOpen[kind] = false;
                });
            },

            containerChildCount() {
                if (!this.isSelectedContainerEl()) return 0;
                return ((this.selEl.data && this.selEl.data.children) || []).length;
            },

            containerPreviewItemCount() {
                return Math.min(Math.max(this.containerChildCount(), 3), 6);
            },

            containerControl(key) {
                if (!this.isSelectedContainerEl()) return null;
                return (this.elSchema(this.selEl.type).controls || []).find(function (control) {
                    return control.key === key;
                }) || null;
            },

            containerControlValue(key) {
                var control = this.containerControl(key);
                return control ? this.controlValue(control) : "";
            },

            setContainerControlValue(key, value) {
                var control = this.containerControl(key);
                if (control) this.setControlValue(control, value);
            },

            containerControlState(key, device) {
                var control = this.containerControl(key);
                return control
                    ? this.controlResponsiveState(control, device)
                    : { device: "d", value: "", source: "d", overridden: false, inherited: false };
            },

            inheritContainerControl(key) {
                var control = this.containerControl(key);
                if (control) this.inheritControlValue(control);
            },

            containerHasResponsiveOverride(device) {
                var self = this;
                return ["direction", "gap", "padding"].some(function (key) {
                    return self.containerControlState(key, device).overridden;
                });
            },

            containerPreviewClass() {
                if (!this.isSelectedContainerEl()) return "";
                if (this.containerIsGrid()) return "grid";
                var d = this.selEl.data || {};
                var isDiv = this.selEl.type === "div";
                var isFlex = !isDiv || (d.display || "block") === "flex";
                var isRow = this.containerControlValue("direction") === "row";
                var cls = isFlex ? (isRow ? "flex flex-row" : "flex flex-col") : "block";
                if (isFlex) {
                    cls += isRow ? " blox-preview-row" : " blox-preview-column";
                    var wrap = d.wrap || "auto";
                    if (wrap === "wrap" || (wrap === "auto" && isRow)) cls += " flex-wrap";
                    else if (wrap === "nowrap") cls += " flex-nowrap";
                    var align = d.align || "stretch";
                    cls += align === "stretch" ? " blox-preview-align-stretch" : "";
                    cls += " " + ({stretch:"items-stretch", start:"items-start", center:"items-center", end:"items-end", baseline:"items-baseline"}[align] || "items-stretch");
                    cls += " " + ({start:"justify-start", center:"justify-center", end:"justify-end", between:"justify-between", around:"justify-around", evenly:"justify-evenly"}[d.justify || "start"] || "justify-start");
                }
                cls += " " + ({none:"rounded", md:"rounded-lg", xl:"rounded-2xl"}[d.radius || "none"] || "rounded");
                return cls;
            },

            containerPreviewStyle() {
                if (!this.isSelectedContainerEl()) return "";
                var bg = (this.selEl.data && this.selEl.data.bg_color) || "#f8fafc";
                var gap = ({none:"0px", sm:"2px", md:"4px", lg:"6px", xl:"8px"}[this.containerControlValue("gap")] || "0px");
                var padding = ({none:"0px", sm:"4px", md:"6px", lg:"8px", xl:"10px"}[this.containerControlValue("padding")] || "0px");
                return {
                    background: bg,
                    gap: this.containerIsGrid() ? gap : undefined,
                    gridTemplateColumns: this.containerIsGrid() ? "repeat(" + this.containerGridColumns() + ", minmax(0, 1fr))" : undefined,
                    "--blox-preview-gap": gap,
                    "--blox-preview-padding": padding,
                };
            },

            /** 树里显示的元素名：自定义命名 > 自己的文字 > 类型名 */
            elLabel(el) {
                if (el.name) return String(el.name);
                var d = el.data || {};
                var schema = this.elSchema(el.type);
                if (el.type === "home-block") {
                    var customLabel = String(d.label || "").trim();
                    var defaultLabel = String(((this.elSchema("home-block").defaults || {}).label) || "");
                    if (customLabel && customLabel !== "首页区块" && customLabel !== defaultLabel) return customLabel;
                    return this.homeBlockSourceLabel(el);
                }
                var txt = schema.treeLabelField ? (d[schema.treeLabelField] || "") : "";
                txt = txt || d.text || d.title || d.html || d.number || "";
                txt = String(txt).replace(/<[^>]*>/g, "").trim();
                var name = schema.label || el.type;
                if (this.headerTemplateMode && this.headerElementLabels[el.type]) {
                    name = this.headerElementLabels[el.type];
                }
                return txt ? (name + "：" + (txt.length > 12 ? txt.slice(0, 12) + "…" : txt)) : name;
            },

            panelTitle() {
                if (this.selEl && this.selectedHomeColumn && !this.selectedHomeField) {
                    return this.uiText.settingsOf.replace(":t", this.selectedHomeColumnLabel());
                }
                if (this.selEl) return this.uiText.settingsOf.replace(":t", this.elSchema(this.selEl.type).label || this.uiText.elementWord);
                if (!this.sel) return this.uiText.settingsWord;
                var n = this.selectedSi + 1;
                if (this.selectedSectionField) return this.sectionFieldName(this.selectedSectionField) + this.uiText.ofSection.replace(":n", n);
                if (this.selLayer === "con") return this.uiText.containerWord + this.uiText.ofSection.replace(":n", n);
                if (this.selLayer === "col") return this.uiText.colWord.replace(":n", this.selectedCi + 1) + this.uiText.ofSection.replace(":n", n);
                return this.uiText.settingsOf.replace(":t", this.sectionLabel(this.sel, this.selectedSi));
            },

            sectionLabelText(value, maxLength) {
                if (typeof value !== "string" && typeof value !== "number") return "";
                var decoder = document.createElement("textarea");
                decoder.innerHTML = String(value).replace(/<[^>]*>/g, "");
                var text = decoder.value
                    .replace(/[\u0000-\u001F\u007F-\u009F\u200B-\u200F\u202A-\u202E\u2060-\u206F\uFEFF]/g, " ")
                    .replace(/\s+/g, " ")
                    .trim();
                return Array.from(text).slice(0, Math.max(1, Number(maxLength) || 80)).join("");
            },

            sectionNameText(value, maxLength) {
                if (typeof value !== "string" && typeof value !== "number") return "";
                var decoder = document.createElement("textarea");
                decoder.innerHTML = String(value);
                var text = decoder.value
                    .replace(/[\u0000-\u001F\u007F-\u009F\u200B-\u200F\u202A-\u202E\u2060-\u206F\uFEFF]/g, " ")
                    .replace(/\s+/g, " ")
                    .trim();
                return Array.from(text).slice(0, Math.max(1, Number(maxLength) || 80)).join("");
            },

            sectionElements(section) {
                var result = [];
                function collect(element, depth) {
                    if (!element || typeof element !== "object") return;
                    result.push(element);
                    if (depth >= 3) return;
                    var children = element.data && Array.isArray(element.data.children)
                        ? element.data.children : [];
                    children.forEach(function (child) { collect(child, depth + 1); });
                }
                var columns = section && Array.isArray(section.columns) ? section.columns : [];
                columns.forEach(function (column) {
                    (column && Array.isArray(column.elements) ? column.elements : []).forEach(function (element) {
                        collect(element, 0);
                    });
                });
                return result;
            },

            sectionLabel(section, si) {
                return this.resolveSectionLabel(section, si, true);
            },

            automaticSectionLabel(section, si) {
                return this.resolveSectionLabel(section, si, false);
            },

            resolveSectionLabel(section, si, includeCustomName) {
                var policy = this.sectionLabelPolicy || {};
                var titleMax = Number(policy.titleMax) || 80;
                var labelMax = Number(policy.labelMax) || 120;
                var elements = this.sectionElements(section);
                var decorative = Array.isArray(policy.decorativeTypes) ? policy.decorativeTypes : [];
                var semanticElement = elements.find(function (element) {
                    var type = String((element || {}).type || "").trim();
                    return type && decorative.indexOf(type) < 0;
                }) || null;
                var typeLabel = "";
                if (semanticElement) {
                    typeLabel = this.sectionLabelText(
                        (this.elSchema(semanticElement.type) || {}).label || "",
                        titleMax
                    );
                }

                var settings = section && section.settings && typeof section.settings === "object"
                    ? section.settings : {};
                var titleCandidates = [];
                var title = "";
                if (includeCustomName) {
                    title = this.sectionNameText(section && section.name || "", titleMax);
                }
                titleCandidates.push(settings.title, section && section.library_name);
                for (var candidate of titleCandidates) {
                    if (title) break;
                    title = this.sectionLabelText(candidate || "", titleMax);
                    if (title) break;
                }
                if (!title) {
                    var heading = elements.find(function (element) { return element && element.type === "heading"; });
                    title = this.sectionLabelText(((heading || {}).data || {}).text || "", titleMax);
                }
                if (!title && semanticElement) {
                    var schema = this.elSchema(semanticElement.type) || {};
                    var keys = [schema.treeLabelField].concat(
                        Array.isArray(policy.elementTitleKeys) ? policy.elementTitleKeys : []
                    ).filter(function (key, index, list) { return key && list.indexOf(key) === index; });
                    var data = semanticElement.data && typeof semanticElement.data === "object"
                        ? semanticElement.data : {};
                    for (var key of keys) {
                        title = this.sectionLabelText(data[key] || "", titleMax);
                        if (title) break;
                    }
                }

                // 网页头是一个完整编辑对象，不应因首个 Logo 元素而显示成“站点标识”。
                // 用户填写的自定义区块名仍优先；仅改写自动命名的最后兜底。
                if (!title && this.headerTemplateMode) {
                    return this.sections.length > 1
                        ? this.uiText.headerSectionIndexed.replace(":n", si + 1)
                        : this.uiText.headerSection;
                }
                if (typeLabel && title && typeLabel.toLocaleLowerCase() !== title.toLocaleLowerCase()) {
                    return this.sectionLabelText(typeLabel + " · " + title, labelMax);
                }
                return title || typeLabel || this.uiText.sectionWord.replace(":n", si + 1);
            },

            normalizeSectionName(section) {
                var target = section || this.sel;
                if (!target) return;
                var policy = this.sectionLabelPolicy || {};
                var normalized = this.sectionNameText(target.name || "", Number(policy.titleMax) || 80);
                if (normalized) target.name = normalized;
                else delete target.name;
            },

            clearSectionName(section) {
                var target = section || this.sel;
                if (!target) return;
                delete target.name;
            },

            isSectionFieldSelected(si, field) {
                return this.selectedSi === si && this.selectedSectionField === field
                    && this.selectedCi < 0 && this.selectedEi < 0;
            },

            selectSectionField(si, field, notifyCanvas) {
                this.multiSelClear();
                if (!this.sections[si] || (field !== "title" && field !== "subtitle")) return;
                this.selectSection(si, false);
                this.selectedSectionField = field;
                this.panelTab = "content";
                if (notifyCanvas !== false) this.highlightCanvasSelection(true);
            },

            isElSelected(si, ci, ei) {
                return this.selectedSi === si && this.selectedCi === ci && this.selectedEi === ei
                    && this.selectedSubEi < 0;
            },

            isChildSelected(si, ci, ei, k) {
                // 精确命中直接子级；更深的后代选中时由 isDescendantSelected 负责高亮
                return this.selectedSi === si && this.selectedCi === ci && this.selectedEi === ei
                    && this.selectedSubPath.length === 1 && this.selectedSubPath[0] === k;
            },

            /** 0b：结构树深层行的精确选中判定。 */
            isDescendantSelected(si, ci, ei, subPath) {
                if (this.selectedSi !== si || this.selectedCi !== ci || this.selectedEi !== ei) return false;
                if (this.selectedSubPath.length !== subPath.length) return false;
                for (var i = 0; i < subPath.length; i++) {
                    if (this.selectedSubPath[i] !== subPath[i]) return false;
                }
                return true;
            },

            isColumnSelected(si, ci) {
                return this.selectedSi === si && this.selectedCi === ci && this.selectedEi < 0 && this.selLayer === "col";
            },

            /**
             * 画布选中后把结构面板滚到对应行（结构树三类行都带 data-selected）。
             *
             * 三条约束都来自 TASK-002 第 4 项：
             * - 尊重收起：面板不可见、或该行的祖先被折叠（行高为 0）时不滚动，也不强行展开；
             * - 不循环滚动：行已完整可见就直接返回，只有越界才补差值，且直接改 scrollTop
             *   （不用 smooth 动画，避免滚动事件与监听互相触发）；
             * - 不抢焦点：只滚动，never focus()。
             */
            revealTreeSelection() {
                var self = this;
                this.$nextTick(function () { self.revealTreeRowOnce(); });
                // 选中后结构树还会展开/重排（x-show 子树晚一步出现），只滚一次会滚早；
                // 再补一次并清掉上一次的定时器，保证同一次选择最多两次、不会自激。
                window.clearTimeout(this._revealTimer);
                this._revealTimer = window.setTimeout(function () { self.revealTreeRowOnce(); }, 180);
            },

            /** 单次定位：已完整可见就直接返回（幂等，重复调用不会抖动）。 */
            revealTreeRowOnce() {
                if (typeof this.rightPanelContentVisible === "function" && !this.rightPanelContentVisible()) return;
                var panel = this.$refs.tree;
                if (!panel || !panel.getBoundingClientRect().height) return;
                // 由内到外取"最深选中行"：元素选了就定位到元素行，不要只滚到它所在的区块行
                // （区块行的 selectedSi 命中也会是 1，按 DOM 顺序查会先命中外层）
                var row = panel.querySelector('[data-testid=blox-tree-element][data-selected="1"]')
                    || panel.querySelector('[data-testid=blox-tree-column][data-selected="1"]')
                    || panel.querySelector('[data-testid=blox-tree-container][data-selected="1"]')
                    || panel.querySelector('[data-testid=blox-tree-section][data-selected="1"]');
                if (!row) return;
                var rowRect = row.getBoundingClientRect();
                if (!rowRect.height) return;
                var panelRect = panel.getBoundingClientRect();
                if (rowRect.top >= panelRect.top && rowRect.bottom <= panelRect.bottom) return;
                panel.scrollTop = panel.scrollTop + (rowRect.top < panelRect.top
                    ? rowRect.top - panelRect.top
                    : rowRect.bottom - panelRect.bottom);
            },

            selectedCol() {
                var s = this.sel;
                if (!s || this.selectedCi < 0 || !s.columns[this.selectedCi]) return null;
                return s.columns[this.selectedCi];
            },

            selectedColData() {
                return this.selectedCol() || this._emptyCol;
            },

            <?php // 列宽（span）读写、单列位置（2.1.0）：拆出主入口以守住 500 KB 预算 ?>
            <?php require __DIR__ . '/column-span-methods.php'; ?>

            // 断点可见性 hide_on = ['m','t','d'] 子集；空数组时删除键保持文档干净。
            hideOnOf(target, isCol) {
                if (!target) return [];
                var raw = isCol ? target.hide_on : (target.settings || {}).hide_on;
                return Array.isArray(raw) ? raw : [];
            },

            deviceVisible(target, key, isCol) {
                return this.hideOnOf(target, isCol).indexOf(key) === -1;
            },

            toggleDevice(target, key, isCol) {
                if (!target) return;
                var list = this.hideOnOf(target, isCol).slice();
                var i = list.indexOf(key);
                if (i === -1) list.push(key); else list.splice(i, 1);
                if (isCol) {
                    if (list.length) target.hide_on = list; else delete target.hide_on;
                } else {
                    target.settings = target.settings || {};
                    if (list.length) target.settings.hide_on = list; else delete target.settings.hide_on;
                }
            },

            elementDeviceVisible(key) {
                if (!this.selEl || !this.selEl.data) return true;
                var raw = this.selEl.data._hide_on;
                return !Array.isArray(raw) || raw.indexOf(key) === -1;
            },

            toggleElementDevice(key) {
                if (!this.selEl) return;
                this.selEl.data = this.selEl.data && typeof this.selEl.data === 'object' ? this.selEl.data : {};
                var list = Array.isArray(this.selEl.data._hide_on) ? this.selEl.data._hide_on.slice() : [];
                var index = list.indexOf(key);
                if (index === -1) list.push(key); else list.splice(index, 1);
                if (list.length) this.selEl.data._hide_on = list; else delete this.selEl.data._hide_on;
            },

            conditionTarget() {
                if (this.selEl) {
                    this.selEl.data = this.selEl.data && typeof this.selEl.data === "object" ? this.selEl.data : {};
                    return this.selEl.data;
                }
                if (this.sel && !this.selectedSectionField && this.selLayer === "sec") {
                    this.sel.settings = this.sel.settings && typeof this.sel.settings === "object" ? this.sel.settings : {};
                    return this.sel.settings;
                }
                return null;
            },

            // 显示条件编辑方法由 yikai-builder 作者端模块提供（plugins/yikai-builder/assets/yikay-pro-editor.js）。

            twoColumnLeftSpan() {
                var s = this.sel;
                if (!s || !Array.isArray(s.columns) || s.columns.length !== 2) return 6;
                var left = this.columnSpan(s.columns[0]);
                var right = this.columnSpan(s.columns[1]);
                var total = left + right;
                if (total !== 12) left = Math.round((left / Math.max(1, total)) * 12);
                return Math.min(10, Math.max(2, left));
            },

            setTwoColumnDivider(span) {
                var s = this.sel;
                if (!s || !Array.isArray(s.columns) || s.columns.length !== 2) return;
                var left = Math.min(10, Math.max(2, parseInt(span, 10) || 6));
                this.writeSpan(s.columns[0], left, this.rawSpanT(s.columns[0]));
                this.writeSpan(s.columns[1], 12 - left, this.rawSpanT(s.columns[1]));
                this.highlightCanvasSelection();
            },

            /** v1.29 画布间距拖拽：桥载荷已校验（路径形态 + 0–160 整数），这里再核类型与容器身份。 */
            applyCanvasGapDrag(payload) {
                payload = payload || {};
                var el = this.elementAtPath(String(payload.path || ""));
                if (!el || !this.elSchema(el.type).container) return;
                var value = parseInt(payload.value, 10);
                if (!Number.isInteger(value) || value < 0 || value > 160) return;
                this.selectPath(String(payload.path), false);
                this.runCommand("canvas-gap-drag", function () {
                    if (!el.data || typeof el.data !== "object") el.data = {};
                    el.data.gap_px = value; // 标量=四档同值；面板可再按档位细调
                    delete el.data.row_gap_px;
                    delete el.data.column_gap_px;
                });
                this.highlightCanvasSelection();
            },

            applyCanvasColumnRatio(payload) {
                payload = payload || {};
                if (payload.kind === "home" && typeof payload.path === "string") {
                    this.selectPath(payload.path, false);
                    this.setAboutRatioIndex(payload.index);
                    this.highlightCanvasSelection();
                    return;
                }
                if (payload.kind !== "section") return;
                var si = parseInt(payload.si, 10);
                var section = this.sections[si];
                if (!section || !Array.isArray(section.columns) || section.columns.length < 2) return;
                var spans = Array.isArray(payload.spans) ? payload.spans.map(function (span) {
                    return parseInt(span, 10);
                }) : [];
                if (!spans.length && section.columns.length === 2) {
                    var left = Math.min(10, Math.max(2, parseInt(payload.leftSpan, 10) || 6));
                    spans = [left, 12 - left];
                }
                if (spans.length !== section.columns.length
                    || spans.some(function (span) { return !Number.isInteger(span) || span < 1 || span > 12; })
                    || spans.reduce(function (total, span) { return total + span; }, 0) > 12) return;
                var self2 = this; section.columns.forEach(function (column, index) { self2.writeSpan(column, spans[index], self2.rawSpanT(column)); });
                this.selectContainer(si, false);
                this.highlightCanvasSelection();
            },
            adjustTwoColumnDivider(delta) {
                this.setTwoColumnDivider(this.twoColumnLeftSpan() + (parseInt(delta, 10) || 0));
            },

            twoColumnRatioLabel() {
                var left = this.twoColumnLeftSpan();
                var right = 12 - left;
                function gcd(a, b) { return b ? gcd(b, a % b) : a; }
                var divisor = gcd(left, right);
                return (left / divisor) + ':' + (right / divisor);
            },

            openMobileSettings() {
                this.expandLeftPanel();
                if (window.innerWidth < 1440 && this.selectedSi >= 0) {
                    this.mobilePanel = "settings";
                    this.libOpen = false;
                }
            },
            selectElement(si, ci, ei, notifyCanvas) {
                this.multiSelClear();
                this.bannerPanelGroup = "common";
                this.homeContentGroup = "content";
                this.styleGroup = "general";
                this.selectedSi = si;
                this.selectedCi = ci;
                this.selectedEi = ei;
                this.setSubSelection([]);
                this.selectedRegion = "";
                this.selectedSectionField = "";
                this.selectedHomeField = "";
                this.selectedHomeColumn = "";
                this.resetBoxPanelState();
                this.targetCi = ci;   // 插入新元素时默认跟着当前所在列
                // 选中即进设置（Bricks 动线）；换选中项就重置面板筛选状态
                this.libOpen = false;
                this.openMobileSettings();
                var el = this.sections[si].columns[ci].elements[ei];
                this.panelTab = (el.type === "list-dynamic" || el.type === "home-block" || el.type === "process-steps")
                    ? "content"
                    : (this.elSchema(el.type).container ? "style" : "content");
                this.ctrlQuery = "";
                this.iconPick = "";
                this.iconQuery = "";
                if (notifyCanvas !== false) this.highlightCanvasSelection(true);
            },

            selectHomeField(path, field, notifyCanvas) {
                var el = this.elementAtPath(path);
                if (!this.homeFieldAllowed(el, field)) return;
                this.selectPath(path, false);
                this.selectedHomeField = field;
                if (window.BloxHomeContentPanel.supports(el)) this.homeContentGroup = window.BloxHomeContentPanel.groupFor(field);
                this.selectedHomeColumn = this.homeFieldGroupKey(el, field);
                this.panelTab = "content";
                this.libOpen = false;
                this.openMobileSettings();
                // 结构树点选时把焦点交给设置控件；画布点选时保留 iframe 焦点，
                // 否则双击刚开启的 contenteditable 会立即 blur 并退出。
                if (notifyCanvas !== false) {
                    this.$nextTick(function () {
                        var control = field.indexOf(".") === -1
                            ? document.querySelector('[data-control-key="' + field + '"]')
                            : document.querySelector("[data-home-field-editor]");
                        if (!control) return;
                        control.scrollIntoView({ block: "nearest" });
                        var input = control.querySelector("input,textarea,select,button");
                        if (input) input.focus({ preventScroll: true });
                    });
                }
                if (notifyCanvas !== false) this.highlightCanvasSelection(true);
            },

            // 0b：子级选区统一写入口——selectedSubPath 为准，selectedSubEi 保持首段镜像。
            setSubSelection(subPath) {
                this.selectedSubPath = Array.isArray(subPath) ? subPath.slice() : [];
                this.selectedSubEi = this.selectedSubPath.length ? this.selectedSubPath[0] : -1;
            },

            selectChild(si, ci, ei, k, notifyCanvas) {
                this.selectDescendant(si, ci, ei, [k], notifyCanvas);
            },

            /** 0b：按子级路径选中嵌套容器内任意深度的节点。 */
            selectDescendant(si, ci, ei, subPath, notifyCanvas) {
                this.multiSelClear();
                this.selectElement(si, ci, ei, false);
                this.setSubSelection(subPath);
                this.panelTab = this.isSelectedContainerEl() ? "style" : "content";
                if (notifyCanvas !== false) this.highlightCanvasSelection(true);
                else if (this.selectedSubPath.length === 1 && this.isHomeBannerHost(this.selTopEl)) {
                    this.showBannerSlide(this.selectedSubPath[0]);
                }
            },

            showBannerSlide(index) {
                this.canvasBridge().post({
                    ykBannerSlide: index,
                    ykBannerPath: this.selectedSi + "." + this.selectedCi + "." + this.selectedEi,
                });
            },

            moveChild(si, ci, ei, k, dir) {
                this.moveNodeAt(si, ci, ei, [k], dir);
            },

            /** 0b：subPath 定位节点的直接父节点（subPath 为空返回 null——顶层元素的父级是列） */
            subPathParent(si, ci, ei, subPath) {
                var section = this.sections[si];
                var column = section && section.columns ? section.columns[ci] : null;
                var node = column && column.elements ? column.elements[ei] : null;
                for (var i = 0; node && i < subPath.length - 1; i++) {
                    node = ((node.data || {}).children || [])[subPath[i]] || null;
                }
                return subPath.length && node ? node : null;
            },

            /** 选区是否命中 subPath 所指节点的前缀（含节点本身与其后代） */
            selectionUnderSubPath(si, ci, ei, subPath) {
                if (this.selectedSi !== si || this.selectedCi !== ci || this.selectedEi !== ei) return false;
                if (this.selectedSubPath.length < subPath.length) return false;
                for (var i = 0; i < subPath.length; i++) {
                    if (this.selectedSubPath[i] !== subPath[i]) return false;
                }
                return true;
            },

            /** 0b：同父级内移动 subPath 所指节点，选区路径跟随。 */
            moveNodeAt(si, ci, ei, subPath, dir) {
                var parent = this.subPathParent(si, ci, ei, subPath);
                var kids = parent && parent.data ? (parent.data.children || []) : [];
                var k = subPath[subPath.length - 1];
                var nk = k + dir;
                if (!kids.length || nk < 0 || nk >= kids.length) return;
                var tmp = kids[k]; kids[k] = kids[nk]; kids[nk] = tmp;
                if (this.selectionUnderSubPath(si, ci, ei, subPath)) {
                    var followed = this.selectedSubPath.slice();
                    followed[subPath.length - 1] = nk;
                    this.setSubSelection(followed);
                }
            },

            isNavigationElementSelected() {
                return !!(this.selEl && ["nav", "nav-mega"].indexOf(this.selEl.type) !== -1);
            },

            selectedElementPosition() {
                if (!this.selTopEl) return null;
                if (this.selectedSubPath.length) {
                    var parent = this.subPathParent(this.selectedSi, this.selectedCi, this.selectedEi, this.selectedSubPath);
                    if (!parent) return null;
                    var children = (parent.data && parent.data.children) || [];
                    return {
                        kind: "child",
                        index: this.selectedSubPath[this.selectedSubPath.length - 1],
                        length: children.length,
                    };
                }
                var section = this.sections[this.selectedSi];
                var column = section && section.columns ? section.columns[this.selectedCi] : null;
                return column
                    ? { kind: "element", index: this.selectedEi, length: (column.elements || []).length }
                    : null;
            },

            canMoveSelectedElement(dir) {
                var position = this.selectedElementPosition();
                if (!position) return false;
                var next = position.index + dir;
                return next >= 0 && next < position.length;
            },

            moveSelectedElement(dir) {
                if (!this.canMoveSelectedElement(dir)) return;
                var self = this;
                this.runCommand("move-selected-element", function () {
                    if (self.selectedSubPath.length) {
                        self.moveNodeAt(self.selectedSi, self.selectedCi, self.selectedEi, self.selectedSubPath, dir);
                    } else {
                        self.moveElement(self.selectedSi, self.selectedCi, self.selectedEi, dir);
                    }
                });
                this.highlightCanvasSelection(false);
            },

            switchNavigationType(type) {
                if (["nav", "nav-mega"].indexOf(type) === -1 || !this.isNavigationElementSelected()) return;
                var node = this.selEl;
                if (!node || node.type === type) return;
                var self = this;
                this.runCommand("switch-navigation-type", function () {
                    var previous = node.data || {};
                    var next = JSON.parse(JSON.stringify((self.elSchema(type).defaults || {})));
                    if (Object.prototype.hasOwnProperty.call(previous, "menu_group")) {
                        next.menu_group = previous.menu_group;
                    }
                    ["_conditions", "_global_style", "_global_style_snapshot", "animation", "animation_trigger", "animation_speed", "animation_delay"].forEach(function (key) {
                        if (!Object.prototype.hasOwnProperty.call(previous, key)) return;
                        var value = previous[key];
                        next[key] = value && typeof value === "object"
                            ? JSON.parse(JSON.stringify(value))
                            : value;
                    });
                    if (type === "nav") {
                        next.dropdown = true;
                        next.desktop_only = true;
                    }
                    node.type = type;
                    node.data = next;
                    self.panelTab = "content";
                    self.ctrlQuery = "";
                    self.modifiedOnly = false;
                });
                this.highlightCanvasSelection(false);
            },

            deleteChild(si, ci, ei, k) {
                this.deleteNodeAt(si, ci, ei, [k]);
            },

            /** 0b：删除 subPath 所指节点；选区在其下则回到父级，同层靠后的兄弟索引左移。 */
            deleteNodeAt(si, ci, ei, subPath) {
                var parent = this.subPathParent(si, ci, ei, subPath);
                var kids = parent && parent.data ? (parent.data.children || []) : [];
                var k = subPath[subPath.length - 1];
                if (!kids[k]) return;
                kids.splice(k, 1);
                if (this.selectedSi !== si || this.selectedCi !== ci || this.selectedEi !== ei) return;
                var depth = subPath.length - 1;
                if (this.selectionUnderSubPath(si, ci, ei, subPath)) {
                    this.setSubSelection(subPath.slice(0, depth));              // 回到父容器
                    return;
                }
                var prefixMatches = this.selectedSubPath.length > depth;
                for (var i = 0; prefixMatches && i < depth; i++) {
                    if (this.selectedSubPath[i] !== subPath[i]) prefixMatches = false;
                }
                if (prefixMatches && this.selectedSubPath[depth] > k) {
                    var shifted = this.selectedSubPath.slice();
                    shifted[depth]--;
                    this.setSubSelection(shifted);
                }
            },

            /** 元素设置的可见控件：页签归属（color→样式，其余→内容）+ 搜索 + 只看已修改 */
            headingPanelVisible() {
                return this.selEl && this.selEl.type === "heading" && this.panelTab === "content"
                    && !this.ctrlQuery.trim() && !this.modifiedOnly;
            },

            headingControl(key) {
                return (this.elSchema("heading").controls || []).find(function (control) { return control.key === key; }) || {};
            },

            headingBindingKey(slot) {
                if (slot === "url") return this.isLoopTemplateChild() ? "loop_url_field" : "site_url_field";
                return this.isLoopTemplateChild() ? "loop_field" : "site_field";
            },

            headingBound(slot) {
                var value = this.controlValue(this.headingControl(this.headingBindingKey(slot)));
                return !!value && value !== "none";
            },

            headingBindingLabel(slot) {
                var control = this.headingControl(this.headingBindingKey(slot));
                return this.controlOptions(control)[this.controlValue(control)] || "";
            },

            headingIdDuplicate() {
                var selected = this.selEl;
                var id = String(selected && selected.data.html_id || "").trim().replace(/^#/, "").toLowerCase();
                if (!id) return false;
                var count = 0;
                function visit(elements) {
                    (elements || []).forEach(function (element) {
                        var data = element.data || {};
                        if (element.type === "heading" && String(data.html_id || "").trim().replace(/^#/, "").toLowerCase() === id) count++;
                        if (element.type !== "list-dynamic") visit(data.children);
                    });
                }
                this.sections.forEach(function (section) {
                    if (String(section.settings && section.settings.anchor_id || "").trim().replace(/^#/, "").toLowerCase() === id) count++;
                    (section.columns || []).forEach(function (column) { visit(column.elements); });
                });
                return count > 1;
            },

            /** 当前选中元素的稳定标识（"搜索前分组"只在同一元素内恢复）。 */
            selectionKey() {
                return this.selectedSi + ":" + this.selectedCi + ":" + this.selectedEi;
            },

            /**
             * 搜索词变化：进入搜索时记住当前分组，清除搜索时恢复。
             * 只在同一元素内恢复——切换元素不该继承上一个元素的分组选择（TASK-003 D）。
             */
            onCtrlQueryChanged(value) {
                if (String(value || "").trim() !== "") {
                    if (this._styleGroupBeforeSearch === null) {
                        this._styleGroupBeforeSearch = this.styleGroup;
                        this._styleGroupBeforeSearchKey = this.selectionKey();
                    }
                    return;
                }
                var saved = this._styleGroupBeforeSearch;
                var savedKey = this._styleGroupBeforeSearchKey;
                this._styleGroupBeforeSearch = null;
                this._styleGroupBeforeSearchKey = "";
                if (saved !== null && savedKey === this.selectionKey()) this.styleGroup = saved;
            },

            /**
             * 可见候选控件（TASK-003 R01）：应用**除分组筛选外**的全部可见性判断
             *（隐藏标记、页签归属、循环上下文、条件依赖、动画开关、检索、只看已修改）。
             *
             * 分组计算（styleGroups）与最终渲染（visibleCtrls）都必须取自这里，
             * 否则隐藏控件会造出幽灵分组、导致默认落进空分组；两者不相互递归。
             */
            styleCandidates() {
                if (!this.selEl || this.panelTab === "condition") return [];
                var self = this;
                // 复合元素区域：选中区域时只显示该区域声明的控件（跨内容/样式页签，避免漏掉样式页签的列数/图片比例）
                var regionKeys = this.selectedRegionKeys();
                var controls = (this.elSchema(this.selEl.type).controls || []).filter(function (c) {
                    if (c.editor_hidden) return false;
                    if (regionKeys) {
                        if (regionKeys.indexOf(c.key) === -1) return false;
                        if (c.loop_only && !self.isLoopTemplateChild()) return false;
                        if (c.outside_loop_only && self.isLoopTemplateChild()) return false;
                        return self.controlRequirementMet(c);
                    }
                    if (self.panelTab === 'professional' ? !c.advanced : !!c.advanced) return false;
                    // 页签归属：控件可在 schema 里显式标 tab（如容器的布局控件全在样式页）；
                    // 未标注的按类型推断——color 归样式，其余归内容
                    var tab = window.BloxHomeContentPanel.tabFor(self.selEl, c);
                    if (self.panelTab !== 'professional' && tab !== self.panelTab) return false;
                    if (c.loop_only && !self.isLoopTemplateChild()) return false;
                    if (c.outside_loop_only && self.isLoopTemplateChild()) return false;
                    if (self.loopItemControlHidden(c)) return false;
                    if (!self.controlRequirementMet(c)) return false;
                    if (!self.siteLanguageControlApplies(c)) return false;
                    if ((c.key === "animation_speed" || c.key === "animation_delay")
                        && !self.selEl.data.animation) return false;
                    return true;
                });
                // TASK-003 R02/R03：通用设置不是 schema 控件，但常规分组必须可达（无搜索时始终在列）。
                // 只在样式页签插入占位项：它是分组与匹配用的标记，不是控件，绝不进内容页签。
                if (this.panelTab === "style" && !regionKeys) {
                    controls.unshift(window.BloxStyleGroups.commonMarker(this.styleCommonSearchText));
                }
                return window.BloxStyleGroups.visibleCandidates(controls, {
                    isExcluded: function () { return false; },
                    isModified: function (c) {
                        // 占位项没有 schema 控件可判，用"通用设置是否被改过"回答（只看已修改时决定它是否在列）
                        if (window.BloxStyleGroups.isCommonMarker(c)) return self.commonStyleModified();
                        return self.isCtrlModified(c);
                    },
                    // 关键词命中：控件名/键 + 所在区块名 + 所属分组名（TASK-003 D）
                    query: this.ctrlQuery,
                    modifiedOnly: this.modifiedOnly,
                    groupLabels: this.styleGroupLabels,
                });
            },

            visibleCtrls() {
                if (!this.selEl) return [];
                if (this.ctaQuickTarget() && !this.ctaQuickDetails && this.panelTab === "content" && !this.ctrlQuery.trim() && !this.modifiedOnly) return [];
                if (this.panelTab === "condition" || this.classStyleTarget()) return [];
                // TASK-003 R03：渲染列表一律不含通用占位项——它只服务分组与匹配（styleGroups 另走 styleCandidates）
                var controls = window.BloxStyleGroups.withoutCommonMarker(this.styleCandidates());
                var showAll = !!this.ctrlQuery.trim() || this.modifiedOnly || this.panelTab !== "content";
                controls = window.BloxBannerPanel.controls(this.selEl, controls, this.bannerPanelGroup, showAll);
                controls = window.BloxHomeContentPanel.controls(this.selEl, controls, this.homeContentGroup, showAll);
                // 容器/Div：布局由专用样式块渲染，共享背景组走通用循环（图/遮罩等能力自动到达）
                if (this.isSelectedContainerEl() && this.panelTab === "style") {
                    controls = controls.filter(function (c) { return c.group === "background"; });
                }
                // 样式页签分组（第 2 轮）：styleGroups() 为空即不启用（容器专用块/组数≤1）
                return window.BloxStyleGroups.filter(controls, this.effectiveStyleGroup(), this.panelTab !== "style" || this.styleGroups().length === 0);
            },

            controlSectionStart(ctrl, index) {
                if (!ctrl || !ctrl.section) return false;
                var controls = this.visibleCtrls();
                return index === 0 || !controls[index - 1] || controls[index - 1].section !== ctrl.section;
            },

            controlRequirementMet(ctrl) {
                // r15 声明式显示规则：required（单条件兼容别名）/ visible_when（AND-OR + 8 操作符）
                // 求值器在 yikay-control-rules.js（可单测纯函数）；引用存在性由 SchemaContract 测试兜底
                if (ctrl.source_kind && this.dynamicSourceKind() !== String(ctrl.source_kind)) return false;
                if (!this.selEl) return true;
                var self = this;
                return window.BloxControlRules.visibleWhenMet(ctrl, function (key) {
                    var dependency = (self.elSchema(self.selEl.type).controls || []).find(function (item) { return item.key === key; });
                    return dependency ? self.controlValue(dependency) : (self.selEl.data || {})[key];
                });
            },

            dynamicSourceKind() {
                var node = this.isLoopTemplateHost(this.selTopEl) ? this.selTopEl : this.selEl;
                var data = (node && node.data) || {};
                var source = String(data.query_source || "");
                if (!source && data.source_type) source = "type:" + String(data.source_type);
                return source === "type:product" ? "product" : "content";
            },

            controlOptions(ctrl) {
                // 2.0.3 查询筛选的目标：当前文档里挂了 _query 的容器/Div
                if (ctrl.options_from === "loop_hosts") return this.loopHostOptions(ctrl);
                var groups = ctrl.source_options || null;
                var options = groups
                    ? (groups[this.dynamicSourceKind()] || ctrl.options || {})
                    : (ctrl.options || {});
                if (this.selEl && this.selEl.type === "home-block" && ctrl.key === "block_type") {
                    var current = String((this.selEl.data || {}).block_type || "");
                    if (current && !Object.prototype.hasOwnProperty.call(options, current)) {
                        options = Object.assign({}, options);
                        options[current] = String((this.selEl.data || {}).label || current);
                    }
                }
                return options;
            },

            /** 当前文档中的查询循环宿主（container/div 挂 _query）：id → 可读名称。 */
            loopHostOptions(ctrl) {
                var options = {};
                options[""] = ctrl.none_label || "—";
                var seen = 0;
                var label = function (node) {
                    var name = String((node.data && (node.data._label || node.data.html_id)) || "");
                    if (name) return name;
                    var query = node.data._query || {};
                    return (ctrl.loop_label || "Loop") + " #" + (++seen) + (query.source ? " · " + query.source : (query.ref ? " · " + query.ref : ""));
                };
                var walk = function (nodes) {
                    (Array.isArray(nodes) ? nodes : []).forEach(function (node) {
                        if (!node || typeof node !== "object") return;
                        if ((node.type === "container" || node.type === "div") && node.data && node.data._query && node.id) {
                            options[String(node.id)] = label(node);
                        }
                        if (node.data && Array.isArray(node.data.children)) walk(node.data.children);
                        if (Array.isArray(node.columns)) node.columns.forEach(function (column) { walk(column && column.elements); });
                        if (Array.isArray(node.elements)) walk(node.elements);
                    });
                };
                walk(this.sections || []);
                var current = this.selEl && this.selEl.data ? String(this.selEl.data[ctrl.key] || "") : "";
                if (current && !Object.prototype.hasOwnProperty.call(options, current)) options[current] = current + " ?";
                return options;
            },

            buttonStylePreviewStyle(value) {
                var styles = {
                    primary: 'background:#2563eb;color:#fff;border-color:#2563eb;',
                    dark: 'background:#111827;color:#fff;border-color:#111827;',
                    outline: 'background:#fff;color:#1f2937;border-color:#9ca3af;',
                    soft: 'background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe;',
                    ghost: 'background:transparent;color:#4b5563;border-color:transparent;',
                    link: 'background:transparent;color:#2563eb;border-color:transparent;text-decoration:underline;text-underline-offset:2px;'
                };
                return styles[String(value)] || styles.primary;
            },

            normalizeSourceControls() {
                var host = this.isLoopTemplateHost(this.selTopEl) ? this.selTopEl : this.selEl;
                if (!host || host.type !== "list-dynamic") return;
                var sourceKind = this.dynamicSourceKind();
                var self = this;
                var normalizeNode = function (node) {
                    if (!node || !node.data) return;
                    (self.elSchema(node.type).controls || []).forEach(function (ctrl) {
                        if (!ctrl.source_options) return;
                        var options = ctrl.source_options[sourceKind] || ctrl.options || {};
                        var current = node.data[ctrl.key];
                        if (current === undefined || current === null || current === "") current = ctrl.default ?? "";
                        if (!Object.prototype.hasOwnProperty.call(options, String(current))) {
                            node.data[ctrl.key] = Object.prototype.hasOwnProperty.call(options, String(ctrl.default ?? ""))
                                ? ctrl.default
                                : (Object.keys(options)[0] || "");
                        }
                    });
                };
                normalizeNode(host);
                (((host.data || {}).children) || []).forEach(normalizeNode);
            },
            controlValue(ctrl) {
                if (!this.selEl || !this.selEl.data) return ctrl.default ?? "";
                var value = this.bannerControlValue(ctrl.key, this.selEl.data[ctrl.key]);
                var isLegacyCtaOverlay = this.selEl.type === "home-block"
                    && String(this.selEl.data.block_type || "") === "cta"
                    && (ctrl.key === "bg_overlay_color" || ctrl.key === "bg_overlay_opacity")
                    && (value === undefined || value === null || value === "");
                if (isLegacyCtaOverlay && ctrl.key === "bg_overlay_color") {
                    value = this.selEl.data.bg_color || "#000000";
                }
                if (isLegacyCtaOverlay && ctrl.key === "bg_overlay_opacity"
                    && Object.prototype.hasOwnProperty.call(this.selEl.data, "bg_opacity")) {
                    var legacyOpacity = Math.max(0, Math.min(100, Number(this.selEl.data.bg_opacity) || 0));
                    value = this.selEl.data.bg_color ? legacyOpacity : 100 - legacyOpacity;
                }
                if ((value === undefined || value === null || value === "")
                    && this.selEl.type === "list-dynamic" && ctrl.key === "query_source" && this.selEl.data.source_type) {
                    value = "type:" + String(this.selEl.data.source_type);
                }
                value = value === undefined || value === null || value === "" ? (ctrl.default ?? "") : value;
                if (ctrl.responsive && window.BloxResponsive) {
                    return window.BloxResponsive.valueFor(
                        value,
                        this.previewDevice,
                        this.controlOptions(ctrl),
                        ctrl.default ?? ""
                    );
                }
                return value;
            },

            <?php require __DIR__ . '/control-editing.php'; ?>

            <?php require __DIR__ . '/responsive-methods.php'; ?>
            // 声明式 CSS 控件（E05）：空串=未设置（沿用默认），0 有效；响应式时按当前预览设备写 {d,t,m} 槽位。
            cssLengthSlots(ctrl) {
                var raw = this.selEl && this.selEl.data ? this.selEl.data[ctrl.key] : "";
                if (raw && typeof raw === "object") return { d: raw.d ?? "", t: raw.t ?? "", m: raw.m ?? "", w: raw.w ?? "" };
                return { d: raw === undefined || raw === null ? "" : raw, t: "", m: "", w: "" };
            },

            cssLengthDevice(ctrl) {
                return ctrl.responsive ? this.responsiveDeviceKey() : "d";
            },

            cssLengthValue(ctrl) {
                var value = this.cssLengthSlots(ctrl)[this.cssLengthDevice(ctrl)];
                return value === undefined || value === null ? "" : String(value);
            },

            cssLengthPlaceholder(ctrl) {
                var slots = this.cssLengthSlots(ctrl);
                var device = this.cssLengthDevice(ctrl);
                var inherited = device === "m" ? (slots.t !== "" ? slots.t : slots.d) : (device === "t" || device === "w" ? slots.d : "");
                return inherited !== "" ? String(inherited) : this.uiText.cssUnset;
            },

            setCssLengthValue(ctrl, raw) {
                if (!this.selEl) return;
                var text = String(raw ?? "").trim();
                var value = text === "" || !isFinite(Number(text)) ? "" : Number(text);
                if (!ctrl.responsive) {
                    this.selEl.data[ctrl.key] = value;
                    return;
                }
                var slots = this.cssLengthSlots(ctrl);
                slots[this.cssLengthDevice(ctrl)] = value;
                this.selEl.data[ctrl.key] = slots.d === "" && slots.t === "" && slots.m === "" ? "" : slots;
            },

            inheritControlValue(ctrl) {
                if (!this.selEl || !ctrl.responsive || !window.BloxResponsive) return;
                this.selEl.data[ctrl.key] = window.BloxResponsive.inheritFor(
                    this.selEl.data[ctrl.key],
                    this.previewDevice,
                    this.controlOptions(ctrl),
                    ctrl.default ?? ""
                );
            },

            sectionResponsiveValue(key, fallback) {
                if (!this.sel || !window.BloxResponsive) return fallback;
                var options = key === "padding"
                    ? { none: true, sm: true, md: true, lg: true, xl: true }
                    : { none: true, sm: true, md: true, lg: true, xl: true };
                return window.BloxResponsive.valueFor(
                    this.sel.settings[key], this.previewDevice, options, fallback
                );
            },

            setSectionResponsiveValue(key, value, fallback) {
                if (!this.sel || !window.BloxResponsive) return;
                var options = { none: true, sm: true, md: true, lg: true, xl: true };
                this.sel.settings[key] = window.BloxResponsive.setFor(
                    this.sel.settings[key], this.previewDevice, value, options, fallback
                );
            },

            sectionResponsiveState(key, fallback, device) {
                if (!this.sel) return this.responsiveState(fallback, {}, fallback, device);
                return this.responsiveState(
                    this.sel.settings[key],
                    { none: true, sm: true, md: true, lg: true, xl: true },
                    fallback,
                    device
                );
            },

            inheritSectionResponsiveValue(key, fallback) {
                if (!this.sel || !window.BloxResponsive) return;
                this.sel.settings[key] = window.BloxResponsive.inheritFor(
                    this.sel.settings[key],
                    this.previewDevice,
                    { none: true, sm: true, md: true, lg: true, xl: true },
                    fallback
                );
            },

            accordionEditRevision: 0,
            accordionAnswerKey(index) {
                return (this.selEl ? this.selEl.id : "") + ":" + this.accordionEditRevision + ":" + index;
            },
            accordionAnswer(index) {
                var item = this.accordionItems()[index];
                if (!this.selEl || !item) return { id: null };
                return { id: this.accordionAnswerKey(index), text: item.answer, format: item.answer_format, allowLinks: true };
            },
            setAccordionAnswer(index, text, format) {
                var items = this.accordionItems();
                if (!Number.isInteger(index) || !items[index]) return;
                items[index].answer = String(text ?? "");
                if (format === "html") items[index].answer_format = "html";
                else delete items[index].answer_format;
                this.storeAccordionItems(items);
            },

            accordionItems(el) {
                var node = el || this.selEl;
                if (!node || !["accordion", "tabs"].includes(node.type)) return [];
                var control = (this.elSchema(node.type).controls || []).find(function (item) {
                    return item.key === "items";
                }) || {};
                var data = node.data && typeof node.data === "object" ? node.data : {};
                var value = Object.prototype.hasOwnProperty.call(data, "items") ? data.items : control.default;
                return window.BloxHomeFieldStore.parseAccordionItems(value, control.max || 30);
            },

            storeAccordionItems(items) {
                if (!this.selEl || !["accordion", "tabs"].includes(this.selEl.type)) return;
                var control = (this.elSchema(this.selEl.type).controls || []).find(function (item) {
                    return item.key === "items";
                }) || {};
                this.selEl.data = this.selEl.data && typeof this.selEl.data === "object" ? this.selEl.data : {};
                this.selEl.data.items = window.BloxHomeFieldStore.parseAccordionItems(items, control.max || 30);
            },

            setAccordionItem(index, field, value) {
                if (["question", "answer"].indexOf(String(field)) === -1) return;
                var items = this.accordionItems();
                var position = Number(index);
                if (!Number.isInteger(position) || position < 0 || position >= items.length) return;
                items[position][field] = String(value ?? "");
                this.storeAccordionItems(items);
            },

            addAccordionItem() {
                var items = this.accordionItems();
                var max = 30;
                var control = (this.elSchema(this.selEl ? this.selEl.type : "accordion").controls || []).find(function (item) {
                    return item.key === "items";
                });
                if (control) max = Math.max(1, Math.min(30, Number(control.max) || 30));
                if (items.length >= max) {
                    this.toast(control && control.limit_label || String(this.homeDynamicText.faqLimit || "").replace(":n", String(max)), "error");
                    return;
                }
                this.flushHistory(true);
                items.push({
                    question: control && control.new_title || this.homeDynamicText.faqNewQuestion,
                    answer: control && control.new_body || this.homeDynamicText.faqNewAnswer,
                });
                this.accordionEditRevision++;
                this.storeAccordionItems(items);
                this.highlightCanvasSelection(false);
            },

            deleteAccordionItem(index) {
                var items = this.accordionItems();
                var position = Number(index);
                if (!Number.isInteger(position) || position < 0 || position >= items.length) return;
                this.flushHistory(true);
                items.splice(position, 1);
                this.accordionEditRevision++;
                this.storeAccordionItems(items);
                this.highlightCanvasSelection(false);
            },

            accordionItemCanMove(index, delta) {
                var position = Number(index);
                var target = position + Number(delta);
                var count = this.accordionItems().length;
                return Number.isInteger(position) && Number.isInteger(target)
                    && position >= 0 && position < count && target >= 0 && target < count;
            },

            moveAccordionItem(index, delta) {
                if (!this.accordionItemCanMove(index, delta)) return;
                this.flushHistory(true);
                var position = Number(index);
                this.accordionEditRevision++;
                this.storeAccordionItems(window.BloxHomeFieldStore.moveItem(
                    this.accordionItems(),
                    position,
                    position + Number(delta)
                ));
                this.highlightCanvasSelection(false);
            },

            // 价格方案套餐编辑方法由 yikai-builder 提供（plugins/yikai-builder/assets/yikay-pro-pricing.js）
            ...((window.BloxPricingControl || {}).methods || {}),
            orgNodes(el) {
                var node = el || this.selEl;
                if (!node || node.type !== "org-chart") return [];
                var control = (this.elSchema("org-chart").controls || []).find(function (item) {
                    return item.key === "nodes";
                }) || {};
                var source = Array.isArray((node.data || {}).nodes) ? node.data.nodes : (control.default || []);
                var nodes = [], ids = {};
                source.slice(0, Math.max(1, Number(control.max) || 100)).forEach(function (item, index) {
                    if (!item || typeof item !== "object") return;
                    var id = String(item.id || "").trim();
                    if (!/^[a-zA-Z0-9_-]{1,64}$/.test(id) || ids[id]) id = "org_" + (index + 1);
                    while (ids[id]) id += "_x";
                    ids[id] = true;
                    nodes.push({
                        id: id,
                        parent_id: String(item.parent_id || "").trim(),
                        name: String(item.name || ""),
                        title: String(item.title || ""),
                    });
                });
                var accepted = {};
                nodes.forEach(function (item, index) {
                    if (index === 0) item.parent_id = "";
                    else if (!accepted[item.parent_id] || item.parent_id === item.id) item.parent_id = nodes[0].id;
                    accepted[item.id] = true;
                });
                return nodes;
            },

            storeOrgNodes(nodes) {
                if (!this.selEl || this.selEl.type !== "org-chart") return;
                this.selEl.data = this.selEl.data && typeof this.selEl.data === "object" ? this.selEl.data : {};
                this.selEl.data.nodes = Array.isArray(nodes) ? nodes : [];
            },

            orgNodeDepth(index, nodes) {
                var items = nodes || this.orgNodes();
                var node = items[Number(index)];
                if (!node) return 0;
                var byId = {}, depth = 0, seen = {};
                items.forEach(function (item) { byId[item.id] = item; });
                while (node.parent_id && byId[node.parent_id] && !seen[node.parent_id] && depth < 12) {
                    seen[node.parent_id] = true;
                    node = byId[node.parent_id];
                    depth++;
                }
                return depth;
            },

            orgNodeLevelText(index) {
                var depth = this.orgNodeDepth(index);
                return depth === 0 ? this.orgText.root : this.orgText.level.replace(":n", String(depth + 1));
            },

            setOrgNode(index, field, value) {
                if (["name", "title"].indexOf(String(field)) === -1) return;
                var nodes = this.orgNodes(), position = Number(index);
                if (!Number.isInteger(position) || !nodes[position]) return;
                nodes[position][field] = String(value == null ? "" : value);
                this.storeOrgNodes(nodes);
            },

            orgSubtreeEnd(index, nodes) {
                var items = nodes || this.orgNodes();
                var start = Number(index), depth = this.orgNodeDepth(start, items), end = start + 1;
                while (end < items.length && this.orgNodeDepth(end, items) > depth) end++;
                return end;
            },

            addOrgNode(index, relation) {
                var nodes = this.orgNodes(), position = Number(index);
                var control = (this.elSchema("org-chart").controls || []).find(function (item) {
                    return item.key === "nodes";
                }) || {};
                var max = Math.max(1, Math.min(100, Number(control.max) || 100));
                if (!Number.isInteger(position) || !nodes[position] || nodes.length >= max) {
                    if (nodes.length >= max) this.toast(this.orgText.limit.replace(":n", String(max)), "error");
                    return;
                }
                if (relation === "sibling" && position === 0) return;
                var parentId = relation === "sibling" ? nodes[position].parent_id : nodes[position].id;
                var insertAt = this.orgSubtreeEnd(position, nodes);
                nodes.splice(insertAt, 0, {
                    id: this.uid("org"),
                    parent_id: parentId,
                    name: this.orgText.newName,
                    title: this.orgText.newTitle,
                });
                this.storeOrgNodes(nodes);
                this.highlightCanvasSelection(false);
            },

            deleteOrgNode(index) {
                var nodes = this.orgNodes(), position = Number(index);
                if (!Number.isInteger(position) || position <= 0 || !nodes[position]) return;
                if (!window.confirm(this.orgText.deleteConfirm)) return;
                nodes.splice(position, this.orgSubtreeEnd(position, nodes) - position);
                this.storeOrgNodes(nodes);
                this.highlightCanvasSelection(false);
            },

            orgSiblingPositions(index, nodes) {
                var items = nodes || this.orgNodes(), node = items[Number(index)];
                if (!node) return [];
                return items.map(function (item, position) {
                    return item.parent_id === node.parent_id ? position : -1;
                }).filter(function (position) { return position >= 0; });
            },

            orgNodeCanMove(index, delta) {
                var siblings = this.orgSiblingPositions(index), at = siblings.indexOf(Number(index));
                return at >= 0 && at + Number(delta) >= 0 && at + Number(delta) < siblings.length;
            },

            moveOrgNode(index, delta) {
                var nodes = this.orgNodes(), position = Number(index), direction = Number(delta);
                if (!this.orgNodeCanMove(position, direction)) return;
                var siblings = this.orgSiblingPositions(position, nodes), at = siblings.indexOf(position);
                var end = this.orgSubtreeEnd(position, nodes);
                var block = nodes.splice(position, end - position);
                if (direction < 0) {
                    nodes.splice(siblings[at - 1], 0, ...block);
                } else {
                    var nextStart = siblings[at + 1] - block.length;
                    nodes.splice(this.orgSubtreeEnd(nextStart, nodes), 0, ...block);
                }
                this.storeOrgNodes(nodes);
                this.highlightCanvasSelection(false);
            },

            homeBlockSourceControl() {
                if (!this.selEl || this.selEl.type !== "home-block") return null;
                return (this.elSchema("home-block").controls || []).find(function (ctrl) {
                    return ctrl.key === "block_type";
                }) || null;
            },

            homeBlockSourceLabel(el) {
                var node = el || this.selEl;
                if (!node || node.type !== "home-block") return "";
                var ctrl = this.homeBlockSourceControl();
                var type = String((node.data || {}).block_type || "banner");
                return String(((ctrl && ctrl.options) || {})[type] || type);
            },

            homeBlockSourceIcon() {
                var type = String(((this.selEl || {}).data || {}).block_type || "");
                if (type === "banner") return "photo";
                if (type.indexOf("channel:") === 0) return "layout-grid";
                if (type === "product_categories") return "category";
                return "database";
            },

            homeControlLabel(key) {
                var control = (this.elSchema("home-block").controls || []).find(function (item) {
                    return item.key === key;
                });
                return control ? control.label : key;
            },

            homeAboutLayoutLabel(el) {
                var control = (this.elSchema("home-block").controls || []).find(function (item) {
                    return item.key === "override_layout";
                });
                var layout = String(((el || {}).data || {}).override_layout || "text_left");
                return String(((control || {}).options || {})[layout] || "");
            },

            homeFieldBlueprint(el) {
                var type = String((((el || {}).data) || {}).block_type || "");
                return this.homeEditorBlueprints[type] || {};
            },

            aboutColumnCards() {
                var cards = [
                    { key: "text", label: this.homeDynamicText.aboutTextColumn, icon: "align-left" },
                    { key: "image", label: this.homeDynamicText.aboutImageColumn, icon: "photo" },
                ];
                return String((((this.selEl || {}).data) || {}).override_layout || "text_left") === "image_left"
                    ? cards.reverse()
                    : cards;
            },

            swapAboutColumns() {
                if (!this.selEl || this.selEl.type !== "home-block") return;
                var data = this.selEl.data || {};
                if (String(data.block_type || "") !== "about") return;
                data.override_layout = String(data.override_layout || "text_left") === "image_left"
                    ? "text_left"
                    : "image_left";
                this.toast(this.homeDynamicText.swapColumns);
                this.highlightCanvasSelection();
            },

            aboutRatioValue() {
                var value = String(((((this.selEl || {}).data) || {}).override_ratio) || "1_1");
                return this.aboutRatioOptions.some(function (option) { return option.value === value; })
                    ? value
                    : "1_1";
            },

            setAboutRatio(value) {
                if (!this.selEl || this.selEl.type !== "home-block") return;
                var allowed = this.aboutRatioOptions.some(function (option) { return option.value === value; });
                this.selEl.data.override_ratio = allowed ? value : "1_1";
            },

            aboutRatioIndex() {
                var value = this.aboutRatioValue();
                var index = this.aboutRatioOptions.findIndex(function (option) { return option.value === value; });
                return index >= 0 ? index : 2;
            },

            setAboutRatioIndex(index) {
                var safeIndex = Math.min(this.aboutRatioOptions.length - 1, Math.max(0, parseInt(index, 10) || 0));
                this.setAboutRatio(this.aboutRatioOptions[safeIndex].value);
            },

            adjustAboutRatio(delta) {
                this.setAboutRatioIndex(this.aboutRatioIndex() + (parseInt(delta, 10) || 0));
            },

            aboutRatioPreviewSpans(ratio) {
                var spans = Array.isArray(ratio.spans) ? ratio.spans.slice(0, 2) : [1, 1];
                return String((((this.selEl || {}).data) || {}).override_layout || "text_left") === "image_left"
                    ? spans.reverse()
                    : spans;
            },

            homeColumnProjection(el) {
                if (!el || el.type !== "home-block") return null;
                var projection = this.homeFieldBlueprint(el).projection || null;
                return projection && String(projection.type || "") === "columns" ? projection : null;
            },

            isProjectedHomeColumnsSection(section) {
                if (!section || !Array.isArray(section.columns) || section.columns.length !== 1) return false;
                var elements = Array.isArray(section.columns[0].elements) ? section.columns[0].elements : [];
                return elements.length === 1 && !!this.homeColumnProjection(elements[0]);
            },

            projectedHomeElement(section) {
                return this.isProjectedHomeColumnsSection(section) ? section.columns[0].elements[0] : null;
            },

            selectedHomeColumnLabel() {
                if (!this.selEl || !this.selectedHomeColumn) return "";
                var group = this.homeFieldGroups(this.selEl).find(function (candidate) {
                    return String(candidate.key || "") === this.selectedHomeColumn;
                }, this);
                return group ? String(group.displayLabel || group.label || "") : "";
            },

            homeColumnAllowed(el, column) {
                var key = String(column || "");
                return key !== "" && this.homeFieldGroups(el).some(function (group) {
                    return String(group.key || "") === key;
                });
            },

            homeFieldGroupKey(el, field) {
                var key = String(field || "");
                var group = this.homeFieldGroups(el).find(function (candidate) {
                    return (candidate.fields || []).some(function (item) {
                        return String(item.key || "") === key;
                    });
                });
                return group ? String(group.key || "") : "";
            },

            selectHomeColumn(path, column, notifyCanvas) {
                var el = this.elementAtPath(path);
                if (!this.homeColumnAllowed(el, column)) return;
                this.selectPath(path, false);
                this.selectedHomeColumn = String(column);
                this.selectedHomeField = "";
                if (window.BloxHomeContentPanel.supports(el)) this.homeContentGroup = ["image", "background"].includes(column) ? "media" : "content";
                this.panelTab = "content";
                this.libOpen = false;
                this.openMobileSettings();
                if (notifyCanvas !== false) this.highlightCanvasSelection(true);
            },

            selectProjectedHomeColumn(si, group) {
                this.selectHomeColumn(si + ".0.0", String((group || {}).key || ""), true);
            },

            isProjectedHomeGroupSelected(si, group) {
                return this.isElSelected(si, 0, 0)
                    && this.selectedHomeColumn === String((group || {}).key || "");
            },

            homeGroupSpanLabel(el, group) {
                var projection = this.homeColumnProjection(el);
                if (!projection) return "";
                var ratioKey = String(projection.ratio_key || "");
                var defaultRatio = String(projection.default_ratio || "");
                var data = ((el || {}).data) || {};
                var ratio = String(data[ratioKey] || defaultRatio);
                var ratios = projection.ratios || {};
                var spans = ratios[ratio] || ratios[defaultRatio] || {};
                var span = parseInt(spans[String((group || {}).key || "")], 10);
                return Math.max(1, Math.min(12, span || 12)) + "/12";
            },

            homeFieldGroups(el) {
                var blueprint = this.homeFieldBlueprint(el);
                var groups = [];
                (blueprint.groups || []).forEach(function (source) {
                    if (source.key === "partners" && !el.data.partners_custom) return;
                    if (source.columnRepeaterKey || source.tree === false) return;
                    var repeat = Math.max(1, Math.min(12, Number(source.repeat) || 1));
                    if (source.key === "partners") repeat = Math.min(12, (el.data.partner_items || []).length);
                    for (var index = 0; index < repeat; index++) {
                        var fields = (source.fields || []).map(function (field) {
                            var copy = Object.assign({}, field);
                            copy.key = String(copy.key || "").replace("{index}", String(index));
                            return copy;
                        });
                        groups.push({
                            key: String(source.key || "group") + (repeat > 1 ? "-" + index : ""),
                            label: String(source.label || ""),
                            displayLabel: source.key === "partners" && el.data.partner_items[index].name
                                ? el.data.partner_items[index].name
                                : (repeat > 1 ? String(source.label || "") + " " + (index + 1) : String(source.label || "")),
                            icon: String(source.icon || "box"),
                            numbered: !!source.numbered,
                            repeated: repeat > 1,
                            fields: fields,
                        });
                    }
                });
                var self = this;
                this.customColumnRepeaters(el).forEach(function (repeater) {
                    self.customColumnItems(el, repeater).forEach(function (item, index) {
                        groups.push({
                            key: String(repeater.key || "custom-columns") + "-" + index,
                            label: self.customColumnLabel(item, index),
                            displayLabel: self.customColumnLabel(item, index),
                            icon: String(repeater.icon || "layout-cards"),
                            fields: self.customColumnFields(repeater, item, index),
                            columnRepeaterKey: String(repeater.key || ""),
                            columnItemIndex: index,
                        });
                    });
                });
                this.customFaqRepeaters(el).forEach(function (repeater) {
                    self.customFaqItems(el, repeater).forEach(function (item, index) {
                        var fields = (repeater.fields || []).map(function (field) {
                            return {
                                key: String(repeater.items_key || "") + "." + index + "." + String(field.suffix || ""),
                                icon: String(field.icon || "box"),
                                label: String(field.label || ""),
                                control: String(field.control || "text"),
                                format_key: field.format_suffix ? String(repeater.items_key || "") + "." + index + "." + field.format_suffix : "",
                            };
                        });
                        groups.push({
                            key: String(repeater.key || "custom-faq") + "-" + index,
                            label: String(item.question || self.homeDynamicText.faqNewQuestion),
                            displayLabel: String(item.question || self.homeDynamicText.faqNewQuestion),
                            icon: String(repeater.icon || "help-circle"),
                            fields: fields,
                            faqRepeaterKey: String(repeater.key || ""),
                            faqItemIndex: index,
                        });
                    });
                });
                if (blueprint.reverse_key
                    && String((((el || {}).data) || {})[blueprint.reverse_key] || "") === String(blueprint.reverse_value || "")) {
                    groups.reverse();
                }
                groups.forEach(function (group, index) {
                    if (group.numbered) group.displayLabel = (index + 1) + " · " + group.label;
                    group.fields.forEach(function (field) { field.groupLabel = group.displayLabel; });
                });
                return groups;
            },

            customColumnRepeaters(el) {
                if (!this.isCustomHomeBlock(el)) return [];
                var repeaters = this.homeFieldBlueprint(el).column_repeaters || [];
                return Array.isArray(repeaters) ? repeaters : [];
            },

            customColumnRepeater(el, key) {
                return this.customColumnRepeaters(el).find(function (repeater) {
                    return String(repeater.key || "") === String(key || "");
                }) || null;
            },

            customColumnIsCustomized(el, repeater) {
                this.homeFieldRevision;
                if (!el || !repeater) return false;
                return this.homeFieldStoredValue(el, String(repeater.mode_key || "")).value === "custom";
            },

            customColumnItems(el, repeater) {
                this.homeFieldRevision;
                if (!el || !repeater) return [];
                var seed = this.homeFieldSeedValue(el, String(repeater.items_key || ""));
                var stored = this.homeFieldStoredValue(el, String(repeater.items_key || ""));
                return window.BloxHomeFieldStore.structuralItems(
                    seed,
                    stored.found ? stored.value : [],
                    this.customColumnIsCustomized(el, repeater),
                    repeater.max
                );
            },

            customColumnLabel(item, index) {
                var elements = Array.isArray((item || {}).elements) ? item.elements : [];
                var heading = elements.find(function (element) {
                    return String((element || {}).type || "") === "heading"
                        && String((((element || {}).data || {}).text || "")).trim() !== "";
                });
                if (heading) return String((heading.data || {}).text || "").replace(/<[^>]*>/g, "").trim().slice(0, 40);
                return String(this.homeDynamicText.customColumn || "").replace(":n", String(index + 1));
            },

            customColumnFields(repeater, item, index) {
                var base = String(repeater.items_key || "") + "." + index;
                var fields = [{
                    key: base + ".card_bg",
                    icon: "palette",
                    label: this.homeDynamicText.customCardBackground,
                    control: "color",
                }];
                var self = this;
                (Array.isArray((item || {}).elements) ? item.elements : []).forEach(function (element, elementIndex) {
                    var prefix = base + ".elements." + elementIndex + ".data.";
                    var type = String((element || {}).type || "");
                    if (type === "heading") {
                        fields.push({ key: prefix + "text", icon: "heading", label: self.homeDynamicText.customTitle, control: "text" });
                    } else if (type === "text") {
                        var html = String((((element || {}).data || {}).html || ""));
                        var plain = html.replace(/<[^>]*>/g, " ").replace(/\s+/g, " ").trim();
                        fields.push({
                            key: prefix + "html",
                            icon: "align-left",
                            label: plain ? plain.slice(0, 28) : self.homeDynamicText.customBody,
                            control: "richtext",
                        });
                    } else if (type === "button") {
                        fields.push({ key: prefix + "text", icon: "click", label: self.homeDynamicText.customButtonText, control: "text" });
                        fields.push({ key: prefix + "url", icon: "link", label: self.homeDynamicText.customLinkUrl, control: "url" });
                    }
                });
                return fields;
            },

            materializeCustomColumns(el, repeater) {
                if (!el || !repeater) return [];
                var items = this.customColumnItems(el, repeater);
                var type = String(((el || {}).data || {}).block_type || "");
                var seeds = this.homeFieldSeeds[type] || {};
                window.BloxHomeFieldStore.setValue(el, String(repeater.items_key || ""), items, seeds);
                window.BloxHomeFieldStore.setValue(el, String(repeater.mode_key || ""), "custom", seeds);
                return items;
            },

            storeCustomColumns(repeater, items, selectedIndex) {
                var type = String((this.selEl.data || {}).block_type || "");
                window.BloxHomeFieldStore.setValue(
                    this.selEl,
                    String(repeater.items_key || ""),
                    items,
                    this.homeFieldSeeds[type] || {}
                );
                this.homeFieldRevision++;
                this.selectedHomeField = "";
                this.selectedHomeColumn = items.length
                    ? String(repeater.key || "") + "-" + Math.max(0, Math.min(selectedIndex, items.length - 1))
                    : "";
                this.panelTab = "content";
                this.highlightCanvasSelection(false);
            },

            duplicateCustomColumn(group, repeaterKey) {
                if (!this.selEl) return;
                var repeater = this.customColumnRepeater(this.selEl, repeaterKey || (group || {}).columnRepeaterKey);
                if (!repeater) return;
                var max = Math.max(2, Math.min(12, Number(repeater.max) || 12));
                var current = this.customColumnItems(this.selEl, repeater);
                if (current.length >= max) {
                    this.toast(String(this.homeDynamicText.planLimit || "").replace(":n", String(max)), "error");
                    return;
                }
                var items = this.materializeCustomColumns(this.selEl, repeater);
                var sourceIndex = Number((group || {}).columnItemIndex);
                if (!Number.isInteger(sourceIndex) || sourceIndex < 0 || sourceIndex >= items.length) {
                    sourceIndex = Math.max(0, items.length - 1);
                }
                var copy = JSON.parse(JSON.stringify(items[sourceIndex] || { elements: [] }));
                items.splice(sourceIndex + 1, 0, copy);
                this.storeCustomColumns(repeater, items, sourceIndex + 1);
            },

            deleteCustomColumn(group) {
                if (!this.selEl || !group) return;
                var repeater = this.customColumnRepeater(this.selEl, group.columnRepeaterKey);
                if (!repeater) return;
                var items = this.materializeCustomColumns(this.selEl, repeater);
                if (items.length <= 1) {
                    this.toast(this.homeDynamicText.planMinimum, "error");
                    return;
                }
                var index = Number(group.columnItemIndex);
                if (!Number.isInteger(index) || index < 0 || index >= items.length) return;
                items.splice(index, 1);
                this.storeCustomColumns(repeater, items, Math.min(index, items.length - 1));
            },

            customColumnCanMove(group, delta) {
                if (!this.selEl || !group) return false;
                var repeater = this.customColumnRepeater(this.selEl, group.columnRepeaterKey);
                if (!repeater) return false;
                var index = Number(group.columnItemIndex);
                var target = index + Number(delta);
                var count = this.customColumnItems(this.selEl, repeater).length;
                return Number.isInteger(index) && Number.isInteger(target)
                    && target !== index && index >= 0 && index < count && target >= 0 && target < count;
            },

            moveCustomColumn(group, delta) {
                if (!this.customColumnCanMove(group, delta)) return;
                var repeater = this.customColumnRepeater(this.selEl, group.columnRepeaterKey);
                if (!repeater) return;
                var fromIndex = Number(group.columnItemIndex);
                var toIndex = fromIndex + Number(delta);
                var items = window.BloxHomeFieldStore.moveItem(
                    this.materializeCustomColumns(this.selEl, repeater),
                    fromIndex,
                    toIndex
                );
                this.storeCustomColumns(repeater, items, toIndex);
            },

            restoreCustomColumns(repeaterKey) {
                if (!this.selEl || !confirm(this.homeDynamicText.planRestoreConfirm)) return;
                var repeater = this.customColumnRepeater(this.selEl, repeaterKey);
                if (!repeater) return;
                var changed = window.BloxHomeFieldStore.deleteValue(this.selEl, String(repeater.mode_key || ""));
                changed = window.BloxHomeFieldStore.deleteValue(this.selEl, String(repeater.items_key || "")) || changed;
                if (!changed) return;
                this.homeFieldRevision++;
                this.selectedHomeField = "";
                this.selectedHomeColumn = "";
                this.highlightCanvasSelection(false);
            },

            customFaqRepeaters(el) {
                if (!this.isCustomHomeBlock(el)) return [];
                var repeaters = this.homeFieldBlueprint(el).repeaters || [];
                return Array.isArray(repeaters) ? repeaters.filter(function (repeater) {
                    return String((repeater || {}).key || "").indexOf("custom-faq-") === 0;
                }) : [];
            },

            customFaqRepeater(el, key) {
                return this.customFaqRepeaters(el).find(function (repeater) {
                    return String(repeater.key || "") === String(key || "");
                }) || null;
            },

            customFaqIsCustomized(el, repeater) {
                this.homeFieldRevision;
                if (!el || !repeater) return false;
                return this.homeFieldStoredValue(el, String(repeater.mode_key || "")).value === "custom";
            },

            customFaqItems(el, repeater) {
                this.homeFieldRevision;
                if (!el || !repeater) return [];
                var seed = this.homeFieldSeedValue(el, String(repeater.items_key || ""));
                var stored = this.homeFieldStoredValue(el, String(repeater.items_key || ""));
                return window.BloxHomeFieldStore.faqItems(
                    seed,
                    stored.found ? stored.value : [],
                    this.customFaqIsCustomized(el, repeater),
                    repeater.max
                );
            },

            materializeCustomFaq(el, repeater) {
                if (!el || !repeater) return [];
                var items = this.customFaqItems(el, repeater);
                var type = String(((el || {}).data || {}).block_type || "");
                var seeds = this.homeFieldSeeds[type] || {};
                window.BloxHomeFieldStore.setValue(el, String(repeater.items_key || ""), items, seeds);
                window.BloxHomeFieldStore.setValue(el, String(repeater.mode_key || ""), "custom", seeds);
                return items;
            },

            addCustomFaqItem(repeaterKey) {
                if (!this.selEl) return;
                var repeater = this.customFaqRepeater(this.selEl, repeaterKey);
                if (!repeater) return;
                var max = Math.max(1, Math.min(30, Number(repeater.max) || 30));
                var items = this.customFaqItems(this.selEl, repeater);
                if (items.length >= max) {
                    this.toast(String(this.homeDynamicText.faqLimit || "").replace(":n", String(max)), "error");
                    return;
                }
                items = this.materializeCustomFaq(this.selEl, repeater);
                items.push({
                    question: this.homeDynamicText.faqNewQuestion,
                    answer: this.homeDynamicText.faqNewAnswer,
                });
                var type = String((this.selEl.data || {}).block_type || "");
                window.BloxHomeFieldStore.setValue(
                    this.selEl,
                    String(repeater.items_key || ""),
                    items,
                    this.homeFieldSeeds[type] || {}
                );
                this.homeFieldRevision++;
                var index = items.length - 1;
                this.selectedHomeColumn = String(repeater.key || "") + "-" + index;
                this.selectedHomeField = String(repeater.items_key || "") + "." + index + ".question";
                this.panelTab = "content";
                this.highlightCanvasSelection(true);
            },

            deleteCustomFaqItem(group) {
                if (!this.selEl || !group) return;
                var repeater = this.customFaqRepeater(this.selEl, group.faqRepeaterKey);
                if (!repeater) return;
                var items = this.materializeCustomFaq(this.selEl, repeater);
                var index = Number(group.faqItemIndex);
                if (!Number.isInteger(index) || index < 0 || index >= items.length) return;
                items.splice(index, 1);
                var type = String((this.selEl.data || {}).block_type || "");
                window.BloxHomeFieldStore.setValue(
                    this.selEl,
                    String(repeater.items_key || ""),
                    items,
                    this.homeFieldSeeds[type] || {}
                );
                this.homeFieldRevision++;
                this.selectedHomeField = "";
                this.selectedHomeColumn = items.length
                    ? String(repeater.key || "") + "-" + Math.min(index, items.length - 1)
                    : "";
                this.highlightCanvasSelection(false);
            },

            customFaqCanMove(group, delta) {
                if (!this.selEl || !group) return false;
                var repeater = this.customFaqRepeater(this.selEl, group.faqRepeaterKey);
                if (!repeater) return false;
                var index = Number(group.faqItemIndex);
                var target = index + Number(delta);
                var count = this.customFaqItems(this.selEl, repeater).length;
                return Number.isInteger(index) && Number.isInteger(target)
                    && target !== index && index >= 0 && index < count && target >= 0 && target < count;
            },

            moveCustomFaqItem(group, delta) {
                if (!this.customFaqCanMove(group, delta)) return;
                var repeater = this.customFaqRepeater(this.selEl, group.faqRepeaterKey);
                if (!repeater) return;
                var fromIndex = Number(group.faqItemIndex);
                var toIndex = fromIndex + Number(delta);
                var items = window.BloxHomeFieldStore.moveItem(
                    this.materializeCustomFaq(this.selEl, repeater),
                    fromIndex,
                    toIndex
                );
                var type = String((this.selEl.data || {}).block_type || "");
                window.BloxHomeFieldStore.setValue(
                    this.selEl,
                    String(repeater.items_key || ""),
                    items,
                    this.homeFieldSeeds[type] || {}
                );
                this.homeFieldRevision++;
                this.selectedHomeField = "";
                this.selectedHomeColumn = String(repeater.key || "") + "-" + toIndex;
                this.panelTab = "content";
                this.highlightCanvasSelection(false);
            },

            restoreCustomFaq(repeaterKey) {
                if (!this.selEl || !confirm(this.homeDynamicText.faqRestoreConfirm)) return;
                var repeater = this.customFaqRepeater(this.selEl, repeaterKey);
                if (!repeater) return;
                var changed = window.BloxHomeFieldStore.deleteValue(this.selEl, String(repeater.mode_key || ""));
                changed = window.BloxHomeFieldStore.deleteValue(this.selEl, String(repeater.items_key || "")) || changed;
                if (!changed) return;
                this.homeFieldRevision++;
                this.selectedHomeField = "";
                this.selectedHomeColumn = "";
                this.highlightCanvasSelection(false);
            },

            selectedCustomStructuralField() {
                if (!this.selEl || !this.selectedHomeField) return false;
                var faq = this.customFaqRepeaters(this.selEl).some(function (repeater) {
                    return this.customFaqIsCustomized(this.selEl, repeater)
                        && this.selectedHomeField.indexOf(String(repeater.items_key || "") + ".") === 0;
                }, this);
                return faq || this.customColumnRepeaters(this.selEl).some(function (repeater) {
                    return this.customColumnIsCustomized(this.selEl, repeater)
                        && this.selectedHomeField.indexOf(String(repeater.items_key || "") + ".") === 0;
                }, this);
            },

            isCustomHomeBlock(el) {
                return !!(el && el.type === "home-block"
                    && String(((el || {}).data || {}).block_type || "").indexOf("custom:") === 0);
            },

            customHomeColumnGroups(el) {
                return this.isCustomHomeBlock(el) ? this.homeFieldGroups(el).filter(function (group) {
                    return String(group.key || "").indexOf("custom-") === 0;
                }) : [];
            },

            selectCustomHomeGroup(group) {
                var path = this.selectedPath();
                if (!path || !this.selEl || !group) return;
                this.selectHomeColumn(path, String(group.key || ""), false);
            },

            homeFieldGroupOpen(group) {
                return !group.repeated || (group.fields || []).some(function (field) {
                    return field.key === this.selectedHomeField;
                }, this);
            },

            homeFieldDefinition(el, field) {
                var found = null;
                this.homeFieldGroups(el).some(function (group) {
                    return (group.fields || []).some(function (candidate) {
                        if (candidate.key !== field) return false;
                        found = Object.assign({}, candidate, { groupLabel: group.displayLabel });
                        return true;
                    });
                });
                return found;
            },

            homeFieldAllowed(el, field) {
                return !!(el && el.type === "home-block" && this.homeFieldDefinition(el, String(field || "")));
            },

            selectedHomeFieldDefinition() {
                return this.selectedHomeField && this.selEl
                    ? this.homeFieldDefinition(this.selEl, this.selectedHomeField)
                    : null;
            },

            homeFieldStoredValue(el, field) {
                var cursor = ((el || {}).data) || {};
                var parts = String(field || "").split(".");
                for (var index = 0; index < parts.length; index++) {
                    var part = parts[index];
                    if (cursor === null || typeof cursor !== "object"
                        || !Object.prototype.hasOwnProperty.call(cursor, part)) {
                        return { found: false, value: "" };
                    }
                    cursor = cursor[part];
                }
                return { found: true, value: cursor ?? "" };
            },

            homeFieldSeedValue(el, field) {
                var type = String((((el || {}).data) || {}).block_type || "");
                var cursor = this.homeFieldSeeds[type] || {};
                var parts = String(field || "").split(".");
                for (var index = 0; index < parts.length; index++) {
                    var part = parts[index];
                    if (cursor === null || typeof cursor !== "object"
                        || !Object.prototype.hasOwnProperty.call(cursor, part)) return "";
                    cursor = cursor[part];
                }
                return cursor ?? "";
            },

            homeFieldValue(el, field) {
                var stored = this.homeFieldStoredValue(el, field);
                return stored.found ? stored.value : this.homeFieldSeedValue(el, field);
            },

            selectedHomeFieldValue() {
                this.homeFieldRevision;
                return this.selEl && this.selectedHomeField
                    ? this.homeFieldValue(this.selEl, this.selectedHomeField)
                    : "";
            },

            selectedHomeFieldInherited() {
                this.homeFieldRevision;
                return !!(this.selEl && this.selectedHomeField
                    && !this.homeFieldStoredValue(this.selEl, this.selectedHomeField).found);
            },

            selectedHomeFaqAnswer() {
                this.homeFieldRevision;
                var field = this.selectedHomeFieldDefinition();
                if (!this.selEl || !field || field.control !== "faq_answer") return { id: null };
                var stored = this.homeFieldStoredValue(this.selEl, field.key);
                var format = stored.found
                    ? this.homeFieldStoredValue(this.selEl, field.format_key).value
                    : this.homeFieldValue(this.selEl, field.format_key);
                return { id: this.selEl.id + ":" + field.key, text: this.selectedHomeFieldValue(), format: format, allowLinks: true };
            },

            setSelectedHomeFaqAnswer(text, format) {
                var field = this.selectedHomeFieldDefinition();
                if (!this.selEl || !field || field.control !== "faq_answer") return;
                var type = String((this.selEl.data || {}).block_type || "");
                var seeds = this.homeFieldSeeds[type] || {};
                window.BloxHomeFieldStore.setValue(this.selEl, field.format_key, format === "html" ? "html" : "", seeds);
                this.setHomeFieldValue(this.selEl, field.key, String(text ?? ""));
            },

            setHomeFieldValue(el, field, value) {
                if (!this.homeFieldAllowed(el, field)) return;
                var type = String((((el || {}).data) || {}).block_type || "");
                if (window.BloxHomeFieldStore.setValue(el, field, value, this.homeFieldSeeds[type] || {})) {
                    this.homeFieldRevision++;
                }
            },

            setSelectedHomeFieldValue(value) {
                if (this.selEl && this.selectedHomeField) {
                    this.setHomeFieldValue(this.selEl, this.selectedHomeField, value);
                }
            },

            resetSelectedCustomHomeField() {
                if (!this.selEl || this.selectedHomeField.indexOf("custom_overrides.") !== 0) return;
                var field = this.selectedHomeFieldDefinition();
                if (field && field.control === "faq_answer") {
                    window.BloxHomeFieldStore.deleteValue(this.selEl, field.format_key);
                }
                if (window.BloxHomeFieldStore.deleteValue(this.selEl, this.selectedHomeField)) {
                    this.homeFieldRevision++;
                }
            },

            homeBlockSummary() {
                if (!this.selEl || this.selEl.type !== "home-block") return "";
                var data = this.selEl.data || {};
                var type = String(data.block_type || "");
                var parts = [];
                if (type === "banner" || type.indexOf("channel:") === 0) {
                    parts.push(this.homeDynamicText.limit + "：" + ((Number(data.limit) || 0) > 0 ? Number(data.limit) : this.homeDynamicText.inherit));
                }
                if (type.indexOf("channel:") === 0) {
                    var sortCtrl = (this.elSchema("home-block").controls || []).find(function (ctrl) { return ctrl.key === "sort"; });
                    parts.push(this.homeDynamicText.sort + "：" + String(((sortCtrl && sortCtrl.options) || {})[data.sort || "inherit"] || this.homeDynamicText.inherit));
                    parts.push(this.homeDynamicText.columns + "：" + ((Number(data.per_row) || 0) > 0 ? Number(data.per_row) : this.homeDynamicText.inherit));
                }
                return parts.join(" · ");
            },

            /** 与注册表默认值对比（undefined/null 一律按空串归一，避免「没设过」误报已修改） */
            isCtrlModified(c) {
                var d = (this.selEl && this.selEl.data) || {};
                var defs = this.elSchema(this.selEl.type).defaults || {};
                var norm = function (v) { return (v === undefined || v === null) ? "" : v; };
                return JSON.stringify(norm(d[c.key])) !== JSON.stringify(norm(defs[c.key]));
            },

            /**
             * 序列化时绕开 Alpine 的响应式代理：输出逐字节相同，2000 个元素的文档从约 9 ms 降到 0.5 ms
             * （2.1.3 实测）。只能用在方法里——模板绑定读原始对象就收不到更新了。
             */
            rawState(value) {
                return window.Alpine && typeof window.Alpine.raw === "function" ? window.Alpine.raw(value) : value;
            },

            historyData() {
                return JSON.stringify(this.rawState(this.sections));
            },

            historySettingsData() {
                return JSON.stringify(this.rawState(this.docSettings || {}));
            },

            /**
             * 整份文档 JSON。$sectionsJson 是同一时刻 historyData() 的结果时直接拼接，结果与
             * JSON.stringify 整个对象逐字节相同——每次改动少序列化一遍整份 sections
             * （2.1.3：2000 个元素的页面每次按键约省 7 ms）。只在同一个同步流程里传，不能缓存。
             */
            documentData(sectionsJson) {
                if (typeof sectionsJson === "string" && Array.isArray(this.sections)) {
                    return '{"schema":1,"settings":' + this.historySettingsData() + ',"sections":' + sectionsJson + '}';
                }
                return JSON.stringify({ schema: 1, settings: this.rawState(this.docSettings || {}), sections: this.rawState(this.sections || []) });
            },

            draftSummary() {
                var Summary = window.BloxDraftSummary;
                if (!Summary || typeof Summary.summarize !== "function") {
                    return {
                        changed: false,
                        total: 0,
                        totals: { added: 0, removed: 0, moved: 0, content: 0, style: 0, settings: 0 },
                        items: [],
                    };
                }
                // 只在文档改动、停手约 250ms 后重算一次（queueDraftSummary 递增 draftRevision）。
                // 以前十几处绑定每次按键都把发布版和草稿各序列化一遍，页面越大打字越卡。
                var rev = this.draftRevision;
                if (draftSummaryCache.rev === rev && draftSummaryCache.value) return draftSummaryCache.value;
                var currentDocument = {
                    schema: 1,
                    settings: this.docSettings || {},
                    sections: this.sections || [],
                };
                var key = JSON.stringify(this.publishedDocument || {}) + "\n" + JSON.stringify(currentDocument);
                if (key !== draftSummaryCache.key || !draftSummaryCache.value) {
                    draftSummaryCache.key = key;
                    draftSummaryCache.value = Summary.summarize(this.publishedDocument, currentDocument);
                }
                draftSummaryCache.rev = rev;
                return draftSummaryCache.value;
            },

            queueDraftSummary() {
                var self = this;
                clearTimeout(this._draftSummaryTimer);
                this._draftSummaryTimer = setTimeout(function () { self.draftRevision++; }, 250);
            },

            draftSummaryCountText() {
                return this.draftSummaryText.count.replace(":count", this.draftSummary().total);
            },

            openDraftSummary() {
                this.draftSummaryOpen = true;
                this.mobileActionsOpen = false;
                this.$nextTick(() => {
                    if (this.$refs.draftSummaryPanel) this.$refs.draftSummaryPanel.focus();
                });
            },

            closeDraftSummary() {
                this.draftSummaryOpen = false;
            },

            draftChangeLabel(item) {
                if (item && item.settings) return this.draftSummaryText.settings;
                if (item && item.id) {
                    var si = this.sectionIndexById(item.id, -1);
                    if (si >= 0) return this.sectionLabel(this.sections[si], si);
                }
                var label = String((item && item.label) || "").trim();
                return label || this.draftSummaryText.sectionFallback;
            },

            locateDraftChange(item) {
                if (!item || !item.canLocate || !item.id) return;
                var si = this.sectionIndexById(item.id, -1);
                if (si < 0) return;
                this.closeDraftSummary();
                this.selectSection(si, false);
                this.$nextTick(() => this.highlightCanvasSelection(true));
            },

            acceptPublishedDocument(payload) {
                try {
                    var document = JSON.parse(payload);
                    if (!document || !Array.isArray(document.sections)) return;
                    this.publishedDocument = document;
                    this.queueDraftSummary();
                } catch (_) {
                    return;
                }
            },

            draftRecovery() {
                if (this._draftRecovery) return this._draftRecovery;
                var Recovery = window.BloxDraftRecovery && window.BloxDraftRecovery.DraftRecovery;
                if (typeof Recovery !== "function") return null;
                var self = this;
                this._draftRecovery = new Recovery({
                    storage: window.localStorage,
                    key: this.recoveryKey,
                    delay: 1200,
                    maxBytes: 2000000,
                    // 只在状态首次变化时回调：失败（存储不可用/空间不足/文档超限）
                    // 必须可见，不能一边写不进去一边毫无提示。
                    onStateChange: function (state) { self.onRecoveryStateChange(state); },
                });
                return this._draftRecovery;
            },

            onRecoveryStateChange(state) {
                this.recoveryState = state;
                var message = this.recoveryStateMessage();
                if (message) this.toast(message);
            },

            /** 恢复稿写入异常的用户可读说明；正常状态返回空串（不打扰）。 */
            recoveryStateMessage() {
                switch (this.recoveryState) {
                    case "unavailable": return this.recoveryText.backupUnavailable;
                    case "quota": return this.recoveryText.backupQuota;
                    case "toolarge": return this.recoveryText.backupTooLarge;
                    default: return "";
                }
            },

            recoveryDraftTimeText() {
                if (!this.recoveryDraft || !Number.isFinite(this.recoveryDraft.savedAt)) return "";
                try { return new Date(this.recoveryDraft.savedAt).toLocaleString(); } catch (_) { return ""; }
            },

            initDraftRecovery() {
                var recovery = this.draftRecovery();
                if (!recovery) return;
                var snapshot = recovery.read(this._savedDocumentSnapshot);
                if (!snapshot) return;
                this.recoveryDraft = snapshot;
                this.recoveryOpen = true;
                this.focusDialog(this.$refs.recoveryDialog, "[data-dialog-initial]");
            },

            queueDraftRecovery(documentJson) {
                if (!this._ready) return;
                var recovery = this.draftRecovery();
                if (!recovery) return;
                var data = typeof documentJson === "string" ? documentJson : this.documentData();
                if (data === this._savedDocumentSnapshot) {
                    recovery.clear();
                    return;
                }
                recovery.queue(data, this.baseRevision);
            },

            <?php require __DIR__ . '/condition-methods.php'; ?>
            <?php require __DIR__ . '/publish-check-methods.php'; ?>

            markDocumentSettingsChanged() {
                if (this._ready && !this._historyApplying) this.queueHistory(this.historyData());
                this.dirty = this.documentData() !== this._savedDocumentSnapshot;
                this.queueDraftRecovery();
            },

            restoreRecovery() {
                if (!this.recoveryDraft || typeof this.recoveryDraft.data !== "string") return;
                try {
                    var document = JSON.parse(this.recoveryDraft.data);
                    if (!document || !Array.isArray(document.sections)) throw new Error("invalid recovery document");
                    this.docSettings = document.settings && typeof document.settings === "object" ? document.settings : {};
                    this.conditionReloadFromDocument();
                    this.normalizeHeaderSettings();
                    this.sections = document.sections;
                    this.normalizeIds();
                    if (this.recoveryDraft.baseRevision) this.baseRevision = this.recoveryDraft.baseRevision;
                    this.recoveryOpen = false;
                    this.recoveryDraft = null;
                    this.dirty = this.documentData() !== this._savedDocumentSnapshot;
                    this.queueDraftRecovery();
                    this.releaseDialog(this.$refs.recoveryDialog);
                } catch (_) {
                    this.discardRecovery();
                }
            },

            discardRecovery() {
                var recovery = this.draftRecovery();
                if (recovery) recovery.clear();
                this.recoveryDraft = null;
                this.recoveryOpen = false;
                this.releaseDialog(this.$refs.recoveryDialog);
            },

            showSaveConflict() {
                this.queueDraftRecovery();
                var recovery = this.draftRecovery();
                if (recovery) recovery.flush();
                this.conflictOpen = true;
                this.focusDialog(this.$refs.conflictDialog, "[data-dialog-initial]");
            },

            continueAfterConflict() {
                if (!this.conflictOpen) return;
                var root = this.$refs.conflictDialog;
                this.conflictOpen = false;
                this.releaseDialog(root);
            },

            reloadAfterConflict() {
                var recovery = this.draftRecovery();
                if (recovery) recovery.flush();
                this.dirty = false;
                window.location.reload();
            },

            copyConflictDocument() {
                var self = this;
                var data = this.documentData();
                var copied = function () { self.toast(self.recoveryText.copied); };
                var failed = function () { self.toast(self.recoveryText.copyFailed); };
                if (navigator.clipboard && typeof navigator.clipboard.writeText === "function") {
                    navigator.clipboard.writeText(data).then(copied).catch(failed);
                    return;
                }
                try {
                    var field = document.createElement("textarea");
                    field.value = data;
                    field.style.position = "fixed";
                    field.style.opacity = "0";
                    document.body.appendChild(field);
                    field.select();
                    var ok = document.execCommand("copy");
                    field.remove();
                    if (ok) copied(); else failed();
                } catch (_) { failed(); }
            },

            historyStructure() {
                var nodeShape = function (el) {
                    return {
                        id: String((el && el.id) || ""),
                        type: String((el && el.type) || ""),
                        children: ((((el || {}).data || {}).children) || []).map(nodeShape),
                    };
                };
                return JSON.stringify((this.rawState(this.sections) || []).map(function (section) {
                    return {
                        id: String(section.id || ""),
                        type: String(section.type || ""),
                        columns: (section.columns || []).map(function (column) {
                            return {
                                id: String(column.id || ""),
                                span: (typeof column.span === "object" && column.span ? parseInt(column.span.d || 0, 10) : parseInt(column.span || 0, 10)) || 0,
                                elements: (column.elements || []).map(nodeShape),
                            };
                        }),
                    };
                }));
            },

            historySelection() {
                return {
                    selectedSi: this.selectedSi,
                    selectedCi: this.selectedCi,
                    selectedEi: this.selectedEi,
                    selectedSubEi: this.selectedSubEi,
                    selectedSubPath: this.selectedSubPath.slice(),
                    selectedSectionField: this.selectedSectionField,
                    selectedHomeField: this.selectedHomeField,
                    selectedHomeColumn: this.selectedHomeColumn,
                    selLayer: this.selLayer,
                    targetCi: this.targetCi,
                    panelTab: this.panelTab,
                    libOpen: this.libOpen,
                };
            },

            historyStore() {
                if (this._historyStore) return this._historyStore;
                var self = this;
                this._historyStore = new window.BloxHistoryStore({
                    limit: 51,
                    delay: 700,
                    getData: function () { return self.historyData(); },
                    getSettings: function () { return self.historySettingsData(); },
                    getStructure: function () { return self.historyStructure(); },
                    getSelection: function () { return self.historySelection(); },
                    isApplying: function () { return self._historyApplying; },
                });
                return this._historyStore;
            },

            initHistory() {
                var initial = this.historyStore().init();
                this._savedSnapshot = initial.data;
                this._savedDocumentSnapshot = this.documentData();
            },

            queueHistory(data) {
                this.historyStore().queue(data);
            },

            flushHistory(captureCurrent) {
                this.historyStore().flush(captureCurrent === true);
            },

            canUndo() {
                return this._historyStore ? this._historyStore.canUndo() : false;
            },

            canRedo() {
                return this._historyStore ? this._historyStore.canRedo() : false;
            },

            restoreHistorySelection(selection) {
                selection = selection || {};
                this.selectedSi = parseInt(selection.selectedSi, 10);
                this.selectedCi = parseInt(selection.selectedCi, 10);
                this.selectedEi = parseInt(selection.selectedEi, 10);
                // 0b：优先恢复子级路径；旧快照只有 selectedSubEi 时按单层路径处理
                var restoredSubPath = Array.isArray(selection.selectedSubPath)
                    ? selection.selectedSubPath.map(function (v) { return parseInt(v, 10); })
                        .filter(function (v) { return Number.isInteger(v) && v >= 0; })
                    : (parseInt(selection.selectedSubEi, 10) >= 0 ? [parseInt(selection.selectedSubEi, 10)] : []);
                this.setSubSelection(restoredSubPath);
                this.selectedSectionField = selection.selectedSectionField === "title" || selection.selectedSectionField === "subtitle"
                    ? selection.selectedSectionField : "";
                this.selectedHomeField = typeof selection.selectedHomeField === "string"
                    ? selection.selectedHomeField : "";
                this.selectedHomeColumn = typeof selection.selectedHomeColumn === "string"
                    ? selection.selectedHomeColumn : "";
                this.selLayer = ["sec", "con", "col"].indexOf(selection.selLayer) >= 0 ? selection.selLayer : "sec";
                this.targetCi = Math.max(0, parseInt(selection.targetCi, 10) || 0);
                this.panelTab = ["content", "style", "condition"].indexOf(selection.panelTab) >= 0
                    ? selection.panelTab : "content";
                this.libOpen = !!selection.libOpen;

                var section = this.sections[this.selectedSi];
                if (!section) {
                    this.selectedSi = -1;
                    this.selectedCi = -1;
                    this.selectedEi = -1;
                    this.setSubSelection([]);
                    this.selectedSectionField = "";
                    this.selectedHomeField = "";
                    this.selectedHomeColumn = "";
                    return;
                }
                var column = this.selectedCi >= 0 ? (section.columns || [])[this.selectedCi] : null;
                if (this.selectedCi >= 0 && !column) {
                    this.selectedCi = -1;
                    this.selectedEi = -1;
                    this.setSubSelection([]);
                }
                var element = column && this.selectedEi >= 0 ? (column.elements || [])[this.selectedEi] : null;
                if (this.selectedEi >= 0 && !element) {
                    this.selectedEi = -1;
                    this.setSubSelection([]);
                }
                // 子级路径逐层验证：任何一层失效就截断到最后一个有效层
                if (element && this.selectedSubPath.length) {
                    var node = element;
                    var valid = [];
                    for (var vi = 0; vi < this.selectedSubPath.length; vi++) {
                        var kid = (((node || {}).data || {}).children || [])[this.selectedSubPath[vi]];
                        if (!kid) break;
                        valid.push(this.selectedSubPath[vi]);
                        node = kid;
                    }
                    if (valid.length !== this.selectedSubPath.length) this.setSubSelection(valid);
                } else if (!element && this.selectedSubPath.length) {
                    this.setSubSelection([]);
                }
                if (this.selectedHomeField && !this.homeFieldAllowed(element, this.selectedHomeField)) {
                    this.selectedHomeField = "";
                }
                if (this.selectedHomeField) {
                    this.selectedHomeColumn = this.homeFieldGroupKey(element, this.selectedHomeField);
                } else if (this.selectedHomeColumn && !this.homeColumnAllowed(element, this.selectedHomeColumn)) {
                    this.selectedHomeColumn = "";
                }
                this.targetCi = Math.min(this.targetCi, Math.max(0, (section.columns || []).length - 1));
            },

            /**
             * 薄命令层（r11）：结构命令统一走 runCommand——mutate 中途抛异常时
             * 恢复执行前快照（复用 applyHistorySnapshot 协议，回滚不产生新历史），
             * 半改的树绝不留给 watcher 入历史/预览。多步修改合并为一个历史项由
             * Alpine watcher 每 tick 合并天然保证，命令层不重复做。
             */
            commandRunner() {
                if (this._commandRunner) return this._commandRunner;
                var self = this;
                this._commandRunner = new window.BloxCommandRunner({
                    capture: function () {
                        return self.historyStore().snapshot(self.historyData());
                    },
                    restore: function (snapshot) {
                        self.applyHistorySnapshot(snapshot);
                    },
                    onError: function (name, error) {
                        self.toast(self.homeText.actionFailed);
                        if (window.console && console.error) console.error("[blox command] " + name, error);
                    },
                });
                return this._commandRunner;
            },

            runCommand(name, fn) {
                var self = this;
                return this.commandRunner().execute(name, function () { return fn.call(self); });
            },

            applyHistorySnapshot(snapshot) {
                if (!snapshot) return;
                this._historyApplying = true;
                var currentSelection = this.historySelection();
                this.sections = JSON.parse(snapshot.data);
                this.legacyPageContent = this.pageMode
                    && this.sections.length === 1
                    && String((this.sections[0] || {}).id || "") === "s_legacy";
                if (typeof snapshot.settings === "string") {
                    var settings = JSON.parse(snapshot.settings);
                    this.docSettings = settings && typeof settings === "object" ? settings : {};
                    this.conditionReloadFromDocument();
                    this.normalizeHeaderSettings();
                }
                this.restoreHistorySelection(snapshot.selection);
                // The first history entry can predate any selection. Keep the current
                // section selected when it still exists so Alpine never tears down an
                // open settings panel against a transient null `sel`.
                if (this.selectedSi < 0 && this.sections[currentSelection.selectedSi]) {
                    this.restoreHistorySelection(currentSelection);
                }
                this.dirty = this.documentData() !== this._savedDocumentSnapshot;
                this.closeCtx();
                var self = this;
                this.$nextTick(function () {
                    self._historyApplying = false;
                    self.highlightCanvasSelection(false);
                });
            },

            undo() {
                var snapshot = this.historyStore().undo();
                if (!snapshot) return;
                this.applyHistorySnapshot(snapshot);
                this.toast(this.historyText.undoDone);
            },

            redo() {
                var snapshot = this.historyStore().redo();
                if (!snapshot) return;
                this.applyHistorySnapshot(snapshot);
                this.toast(this.historyText.redoDone);
            },

            moveElement(si, ci, ei, dir) {
                var els = this.sections[si].columns[ci].elements;
                var ni = ei + dir;
                if (ni < 0 || ni >= els.length) return;
                var tmp = els[ei]; els[ei] = els[ni]; els[ni] = tmp;
                if (this.isElSelected(si, ci, ei)) this.selectedEi = ni;
            },

            deleteElement(si, ci, ei) { return this.runCommand("delete-element", function () { return this._deleteElementRaw(si, ci, ei); }); },
            _deleteElementRaw(si, ci, ei) {
                var el = this.sections[si].columns[ci].elements[ei];
                var kids = (el && el.data && el.data.children) ? el.data.children.length : 0;
                if (kids > 0 && !confirm(this.uiText.confirmDeleteContainer.replace(":count", kids))) return;
                this.sections[si].columns[ci].elements.splice(ei, 1);
                // 选中项要跟着修正，否则设置面板会指向已删除或错位的元素
                if (this.selectedSi === si && this.selectedCi === ci) {
                    if (this.selectedEi === ei) {
                        this.selectedCi = -1;
                        this.selectedEi = -1;
                        this.setSubSelection([]);
                        this.selectedHomeField = "";
                        this.selectedHomeColumn = "";
                    } else if (this.selectedEi > ei) {
                        this.selectedEi--;
                    }
                }
            },

            init() {
                var self = this;
                this.syncPaletteInputMode();
                this.restoreLeftPanelWidth();
                this.restoreRightPanelState();
                this.restoreTemplatePanelWidth();
                this.restoreElementLibraryPreferences();
                this.restoreClipboard(); // v1.29 跨页剪贴板：另一页复制的元素在本页可粘贴
                this.restoreTemplateLibraryPreferences();
                this.normalizeHeaderSettings();
                // 先归一化 id 再渲染：老数据（排版编辑器早期格式）可能缺 id 或 id 重复，
                // x-for 的 :key 遇到 undefined/重复会让 Alpine 崩掉、结构树整个不渲染
                this.normalizeIds();
                if (this.applyInitialNodeFocus()) this._pendingInitialFooterScroll = false;
                this.initHistory();
                this.initDraftRecovery();
                this.$nextTick(function() {
                    self.observeCanvasHost();
                    self.refreshPreview();
                    self.initTreeSortable();
                    self._ready = true;
                    if (self.initialPanel === "design") self.openDesignSystem();
                    else if (self.initialPanel === "templates") self.openTemplates();
                });
                window.addEventListener("resize", function () {
                    self.canvasViewportTick++;
                    self.syncPaletteInputMode();
                });
                // Sortable 会截断结构树内的外部 dragover；捕获阶段先识别预制区块落点。
                window.addEventListener("dragenter", function (e) { self.treeSectionDragOver(e); }, true);
                window.addEventListener("dragover", function (e) { self.treeSectionDragOver(e); }, true);
                window.addEventListener("drop", function (e) { self.treeSectionDrop(e); }, true);
                // 未保存离开守卫：dirty 时关闭/刷新标签页要过浏览器确认
                window.addEventListener("beforeunload", function (e) {
                    if (!self._allowEditorLeave && self.hasUnsavedChanges()) { e.preventDefault(); e.returnValue = ""; }
                });
                window.addEventListener("pagehide", function () {
                    self.finishLeftPanelResize();
                    self.finishRightPanelResize();
                    self.finishTemplatePanelResize();
                    if (self._draftRecovery) self._draftRecovery.dispose(self.dirty);
                    if (self._previewClient) self._previewClient.cancel();
                    if (self._canvasBridge) self._canvasBridge.dispose();
                    if (self._historyStore) self._historyStore.dispose();
                });
                window.addEventListener("keydown", function (e) {
                    if (e.key === "Escape" && self.canvasDragActive) { e.preventDefault(); self.finishPaletteDrag(); return; }
                    if (e.key === "Escape" && self.multiSelActive()) { e.preventDefault(); self.multiSelReset(); return; }
                    if (e.key === "Escape" && self.ctx.open) { e.preventDefault(); self.closeCtx(); return; }
                    if (e.key === "Escape" && !e.defaultPrevented) {
                        var escapeTarget = document.activeElement;
                        var editing = escapeTarget && (escapeTarget.matches("input, textarea, select") || escapeTarget.isContentEditable);
                        var dialogOpen = Array.from(document.querySelectorAll('[role="dialog"]')).some(function (dialog) { return dialog.getClientRects().length > 0; });
                        if (!editing && !dialogOpen && self.sel) { e.preventDefault(); self.deselectAll(); }
                        return;
                    }
                    // v1.29：Delete 删除当前选中（编辑焦点/弹层内不劫持）
                    if (e.key === "Delete" && !e.ctrlKey && !e.metaKey && !e.altKey && !e.shiftKey) {
                        var deleteTarget = document.activeElement;
                        var deleteEditing = deleteTarget && (deleteTarget.matches("input, textarea, select") || deleteTarget.isContentEditable);
                        var deleteDialog = Array.from(document.querySelectorAll('[role="dialog"]')).some(function (dialog) { return dialog.getClientRects().length > 0; });
                        if (!deleteEditing && !deleteDialog && self.selectedSi >= 0) {
                            e.preventDefault();
                            self.shortcutDeleteSelection();
                        }
                        return;
                    }
                    if (!(e.ctrlKey || e.metaKey) || e.altKey) return;
                    // 命令面板（2.0.4）：Ctrl+K / ⌘K 在任何焦点下都可打开（抢在浏览器地址栏搜索之前）
                    if (!e.shiftKey && String(e.key || "").toLowerCase() === "k") {
                        e.preventDefault();
                        if (self.cmdOpen) self.closeCommandPalette(); else self.openCommandPalette();
                        return;
                    }
                    var activeEditor = window.hugerte && hugerte.activeEditor;
                    if (activeEditor && typeof activeEditor.hasFocus === "function" && activeEditor.hasFocus()) return;
                    var active = document.activeElement;
                    if (active && (active.tagName === "INPUT" || active.tagName === "TEXTAREA" || active.tagName === "SELECT" || active.isContentEditable)) return;
                    var key = String(e.key || "").toLowerCase();
                    if (key === "z") {
                        e.preventDefault();
                        if (e.shiftKey) self.redo(); else self.undo();
                    } else if (key === "y" && !e.shiftKey) {
                        e.preventDefault();
                        self.redo();
                    } else if (!e.shiftKey && key === "c" && self.hasClipboardSelection()) {
                        e.preventDefault();
                        self.copySelection();
                    } else if (!e.shiftKey && key === "x" && self.hasClipboardSelection()) {
                        e.preventDefault();
                        self.cutSelection();
                    } else if (!e.shiftKey && key === "v" && self.clipboard) {
                        e.preventDefault();
                        self.pasteSelection();
                    } else if (!e.shiftKey && key === "d" && self.selectedSi >= 0) {
                        // v1.29：Ctrl+D 复制当前选中一份（抢在浏览器"添加书签"之前）
                        e.preventDefault();
                        self.shortcutDuplicateSelection();
                    }
                });
                this.$watch("libOpen", function (open) { if (!open) self.quickAddTargetId = ""; });
                // TASK-003 D：搜索词变化时保存/恢复分组选择（清空搜索即还原，且不跨元素串状态）
                this.$watch("ctrlQuery", function (value) { self.onCtrlQueryChanged(value); });
                // 数据变更 → 与保存基线比较、记录历史、重渲染画布并重绑结构树拖拽
                this.$watch("sections", function() {
                    self._insertAt = null; // 定点插入覆盖位一次性生效
                    self.multiSelReset(); // 文档一旦变化，多选集合与锚点一并失效
                    var currentData = self.historyData();
                    if (self._ready) {
                        // 整份文档只序列化这一次：脏标记、草稿恢复共用（大页面每次按键原来要三遍）
                        var documentJson = self.documentData(currentData);
                        self.dirty = documentJson !== self._savedDocumentSnapshot;
                        if (!self._historyApplying) self.queueHistory(currentData);
                        self.queueDraftRecovery(documentJson);
                    }
                    self.schedulePreview();
                    self.scheduleTreeSortable();
                    self.queueDraftSummary();
                });
                this.$watch("docSettings", function () { self.queueDraftSummary(); });
                this.canvasBridge().start();
                this.postDragRules();
            },

            // 画布拖放禁用态所需的嵌套规则表：容器 allowedChildren + 容器/通用子元素标记。
            postDragRules() {
                var rules = { containers: {}, isContainer: {}, generic: {} };
                var meta = this.elementSchemas || {};
                Object.keys(meta).forEach(function (type) {
                    var m = meta[type] || {};
                    if (m.container) rules.containers[type] = Array.isArray(m.allowedChildren) ? m.allowedChildren : [];
                    rules.isContainer[type] = !!m.container;
                    rules.generic[type] = m.genericChild !== false;
                });
                // JSON 脱壳：elementSchemas 是 Alpine Proxy，其数组引用不可 structured-clone
                // （Sortable 需 Alpine.raw() 是同一族问题）。
                this.canvasBridge().post(JSON.parse(JSON.stringify({ ykDragRules: rules })));
            },

            canvasBridge() {
                if (this._canvasBridge) return this._canvasBridge;
                var self = this;
                this._canvasBridge = new window.BloxCanvasBridge({
                    getFrame: function () { return self.$refs.canvas; },
                    onColumnRatio: function (payload) { self.applyCanvasColumnRatio(payload); },
                    onGapDrag: function (payload) { self.applyCanvasGapDrag(payload); },
                    onContext: function (payload) { self.openCtxFromCanvas(payload); },
                    onDrop: function (payload) { self.handleCanvasDrop(payload); },
                    onTemplateDrop: function (payload) { self.handleTemplateDrop(payload); },
                    onInlineEdit: function (payload) { self.applyInlineEdit(payload); },
                    onTableAction: function (payload) { if (typeof self.handleTableCanvasAction === 'function') self.handleTableCanvasAction(payload); },
                    onEditSectionField: function (payload) { self.editSectionField(payload.si, payload.field); },
                    onPickSectionField: function (payload) { self.selectSectionField(payload.si, payload.field, false); },
                    onPickHomeColumn: function (payload) { self.selectHomeColumn(payload.path, payload.column, false); },
                    onPickHomeField: function (payload) { self.selectHomeField(payload.path, payload.field, false); },
                    onPickElement: function (target) { self.canvasPickElement(target); self.openPickedBannerPanel(target); self.revealTreeSelection(); },
                    onEditElement: function (target) { self.selectElementTarget(target, false); self.quickEditSelected(); self.revealTreeSelection(); },
                    onPickColumn: function (si, ci) { self.selectColumn(si, ci, false); self.revealTreeSelection(); },
                    onPickContainer: function (si) { self.selectContainer(si, false); self.revealTreeSelection(); },
                    onPickSection: function (target) { self.canvasPickSection(target); self.revealTreeSelection(); },
                    onMultiIds: function () { },
                    onEscape: function () { if (self.multiSelActive()) self.multiSelClear(); else if (self.sel) self.deselectAll(); },
                    onClear: function () { self.deselectAll(); },
                    onAreaHit: function (id) {
                        self.ctxHit = id;
                        self.scrollInitialFooterIntoView();
                    },
                    onAreaMatch: function (match) { self.ctxMatch = match; },
                    onEditArea: function (payload) {
                        // 主题默认页头/页尾且主题声明了这一区的文案：先在面板里改，不离开编辑器
                        if (payload.source === "theme" && self.openThemeContent(payload.area)) return;
                        self.navigateEditorTo(payload.url);
                    },
                    onEditPageHero: function () { self.openPageHeroSettings(); },
                    // 画布空态双入口：模板库起步 / 空白区块起步
                    onEmptyAction: function (action) {
                        if (action === "templates") {
                            self.openTemplates();
                            return;
                        }
                        self.deselectAll();
                        self.addSection(1);
                    },
                    // Quick-add uses the same validated insertion command as the palette.
                    onQuickAdd: function (payload) { self.openElementLibraryAt(payload); },
                    // 画布空列 / 空容器上的「拆分」（2.1.0）
                    onSplitAt: function (payload) { self.openSplitPicker(payload.target, null, payload.anchor); },
                    onSizeDrag: function (payload) { self.applyCanvasSizeDrag(payload); },
                    // 画布插入轨道（r13）：边界/末尾「+」的定点插入
                    onInsertAt: function (payload) { self.insertAtBoundary(payload); },
                    // 具名拒因（r14）：无效落点松手不再静默，toast 说明原因
                    onDropRejected: function (reason) {
                        if (reason === "restricted-children") { self.toast(self.uiText.dropRestricted); return; }
                        self.toast(self.uiText.noNestedContainer);
                    },
                });
                return this._canvasBridge;
            },

            pageHeroSource() {
                var mode = String(this.pageHero.style_source || "self");
                if (mode === "parent") return String(this.pageHero.parent_preview_source || "builtin");
                if (mode === "global") return String(this.pageHero.global_preview_source || "builtin");
                if (String(this.pageHero.hero_bg || "").trim()) return "custom";
                if (String(this.pageHero.image || "").trim()) return "cover";
                return String(this.pageHero.global_preview_source || "builtin");
            },

            pageHeroSourceLabel() {
                var key = this.pageHeroSource();
                if (key === "parent") {
                    return this.pageHeroText.sourceParent.replace(":name", String(this.pageHero.parent_preview_name || ""));
                }
                if (key === "custom") return this.pageHeroText.sourceCustom;
                if (key === "cover") return this.pageHeroText.sourceCover;
                if (key === "global") return this.pageHeroText.sourceGlobal;
                return this.pageHeroText.sourceBuiltin;
            },

            pageHeroPreviewBackground() {
                var mode = String(this.pageHero.style_source || "self");
                if (mode === "parent") return String(this.pageHero.parent_preview_bg || "");
                if (mode === "global") return String(this.pageHero.global_preview_bg || "");
                return String(this.pageHero.hero_bg || this.pageHero.image || this.pageHero.global_preview_bg || "");
            },

            pageHeroPreviewOptions() {
                var mode = String(this.pageHero.style_source || "self");
                if (mode === "parent") return this.pageHero.parent_preview_options || this.pageHero.style_options;
                if (mode === "global") return this.pageHero.global_preview_options || this.pageHero.style_options;
                return this.pageHero.style_options || {};
            },

            pageHeroPreviewStyle() {
                var options = this.pageHeroPreviewOptions();
                if (options.layout === "compact") return "background-color:#f9fafb;background-image:none;border-bottom:1px solid #e5e7eb;";
                var background = this.pageHeroPreviewBackground();
                var color = String(options.background_color || "");
                var style = "";
                if (color) style += "background-color:" + this.colorFieldPreview(color, "#111827") + ";";
                if (background) style += "background-image:url('" + String(background).replace(/'/g, "%27") + "');";
                style += "background-position:" + Number(options.focal_x ?? 50) + "% " + Number(options.focal_y ?? 50) + "%;";
                return style;
            },

            pageHeroPreviewHeight() {
                var options = this.pageHeroPreviewOptions();
                var height = String(options.height || "standard");
                if (this.pageHeroPreviewDevice === "mobile") {
                    var mobile = String(options.mobile_height || "inherit");
                    if (mobile !== "inherit") height = mobile;
                }
                return height;
            },

            pageHeroPreviewTone() {
                var options = this.pageHeroPreviewOptions();
                if (options.layout === "compact") return "dark";
                var tone = String(options.text_tone || "auto");
                if (tone !== "auto") return tone;
                if (this.pageHeroPreviewBackground()) return "light";
                var color = window.YikaiBloxColorPicker.normalizeHex(options.background_color || "", "");
                if (color) {
                    var red = parseInt(color.slice(1, 3), 16);
                    var green = parseInt(color.slice(3, 5), 16);
                    var blue = parseInt(color.slice(5, 7), 16);
                    return ((red * 299 + green * 587 + blue * 114) / 1000) > 150 ? "dark" : "light";
                }
                return "light";
            },

            applyPageHeroPreset(name) {
                if (String(this.pageHero.style_source || "self") !== "self") return;
                var presets = {
                    standard: { background_color: "", overlay_opacity: 60, height: "standard", mobile_height: "inherit", focal_x: 50, focal_y: 50, alignment: "center", text_tone: "auto" },
                    compact: { background_color: "#111827", overlay_opacity: 55, height: "compact", mobile_height: "inherit", focal_x: 50, focal_y: 50, alignment: "left", text_tone: "light" },
                    statement: { background_color: "#0f172a", overlay_opacity: 45, height: "large", mobile_height: "standard", focal_x: 50, focal_y: 50, alignment: "left", text_tone: "light" },
                    minimal: { background_color: "#f8fafc", overlay_opacity: 0, height: "compact", mobile_height: "inherit", focal_x: 50, focal_y: 50, alignment: "left", text_tone: "dark" },
                };
                if (!presets[name]) return;
                this.pageHero.style_options = Object.assign({}, this.pageHero.style_options, presets[name], { layout: "banner" });
            },

            pageHeroModeHint() {
                var mode = String(this.pageHero.style_source || "self");
                if (mode === "parent") return this.pageHeroText.hintParent;
                if (mode === "global") return this.pageHeroText.hintGlobal;
                return this.pageHeroText.hintSelf;
            },

            pageHeroEffectiveSourceLabel() {
                var mode = String(this.pageHero.style_source || "self");
                if (mode === "parent") {
                    var source = String(this.pageHero.parent_preview_name || "").trim();
                    return source
                        ? this.pageHeroText.effectiveParent.replace(":name", source)
                        : this.pageHeroText.effectiveGlobal;
                }
                return mode === "global" ? this.pageHeroText.effectiveGlobal : this.pageHeroText.effectiveSelf;
            },

            pageHeroInheritancePathLabel() {
                if (String(this.pageHero.style_source || "self") !== "parent") return "";
                var path = Array.isArray(this.pageHero.parent_preview_path) ? this.pageHero.parent_preview_path : [];
                return path.length ? this.pageHeroText.inheritancePath.replace(":path", path.join(" › ")) : "";
            },

            copyPageHeroToSelf() {
                if (String(this.pageHero.style_source || "self") === "self") return;
                this.pageHero.hero_bg = String(this.pageHeroPreviewBackground() || "");
                this.pageHero.style_options = JSON.parse(JSON.stringify(this.pageHeroPreviewOptions() || {}));
                this.pageHero.style_source = "self";
            },

            restorePageHeroInheritance() {
                if (String(this.pageHero.style_source || "self") !== "self") return;
                this.pageHero.style_source = this.pageHero.can_inherit ? "parent" : "global";
            },

            openPageHeroSettings() {
                if (!this.pageHero.available) return;
                this.pageHeroOpen = true;
                this.focusDialog(this.$refs.pageHeroDialog, "[data-dialog-initial]");
            },

            closePageHeroSettings() {
                if (!this.pageHeroOpen || this.pageHeroSaving) return;
                var root = this.$refs.pageHeroDialog;
                this.pageHeroOpen = false;
                this.releaseDialog(root);
            },

            pickPageHeroBackground() {
                var self = this;
                this.openMedia(function (url) { self.pageHero.hero_bg = url; }, { usage: "hero-bg" });
            },

            /** 自动封面提示条「关闭头图」：只改 show_cover，沿用标题区设置的同一保存链路。 */
            disablePageCover() {
                if (!this.pageHero.cover_applicable || this.pageHeroSaving) return;
                this.pageHero.show_cover = false;
                this.savePageHeroSettings();
            },

            savePageHeroSettings() {
                if (!this.pageHero.available || this.pageHeroSaving) return;
                var self = this;
                var body = new URLSearchParams();
                body.set("action", "save_page_hero");
                body.set("id", String(this.pageHero.id || 0));
                body.set("hero_bg", String(this.pageHero.hero_bg || "").trim());
                body.set("show_hero", this.pageHero.show_hero ? "1" : "0");
                body.set("hero_style_source", String(this.pageHero.style_source || "self"));
                body.set("hero_style_options", JSON.stringify(this.pageHero.style_options || {}));
                if (this.pageHero.cover_applicable) body.set("show_cover", this.pageHero.show_cover ? "1" : "0");
                body.set("_token", this.csrf);
                this.pageHeroSaving = true;
                fetch(this.endpoint, { method: "POST", body: body })
                    .then(function (response) { return response.json().catch(function () { return { success: false }; }); })
                    .then(function (result) {
                        var ok = result && result.success !== false
                            && (typeof result.code === "undefined" || Number(result.code) === 0);
                        if (!ok) {
                            self.toast((result && (result.message || result.msg)) || self.pageHeroText.saveFailed);
                            return;
                        }
                        if (result.data) {
                            self.pageHero.hero_bg = String(result.data.hero_bg || "");
                            self.pageHero.show_hero = !!result.data.show_hero;
                            self.pageHero.style_source = String(result.data.style_source || "self");
                            self.pageHero.style_options = result.data.style_options || self.pageHero.style_options;
                            self.pageHero.resolved_options = result.data.resolved_options || self.pageHeroPreviewOptions();
                            self.pageHero.source = String(result.data.source || self.pageHeroSource());
                            self.pageHero.resolved_bg = String(result.data.resolved_bg || "");
                            self.pageHero.source_channel_name = String(result.data.source_channel_name || "");
                            if (typeof result.data.show_cover !== "undefined") self.pageHero.show_cover = !!result.data.show_cover;
                        }
                        self.pageHeroOpen = false;
                        self.releaseDialog(self.$refs.pageHeroDialog);
                        self.refreshPreview();
                        self.toast(self.pageHeroText.saved);
                    })
                    .catch(function () { self.toast(self.pageHeroText.saveFailed); })
                    .finally(function () { self.pageHeroSaving = false; });
            },

            openElementLibraryAt(payload) {
                this.quickAddTargetId = "";
                payload = payload || {};
                if (payload.kind === "column") {
                    var section = this.sections[payload.sec];
                    if (!section || !section.columns || !section.columns[payload.col]) return;
                    this.selectColumn(payload.sec, payload.col, false);
                } else if (payload.kind === "container") {
                    var host = this.elementAtPath(payload.path);
                    if (!host || !this.elSchema(host.type).container) return;
                    this.selectPath(payload.path, false);
                } else {
                    return;
                }
                this.libQuery = "";
                this.quickAddTargetId = this.quickAddContextId();
                this.libOpen = true;
                if (window.innerWidth < 1440) this.mobilePanel = "library";
                var self = this;
                this.$nextTick(function () {
                    if (self.$refs.libSearch) self.$refs.libSearch.focus();
                });
            },

            /**
             * 画布选中区块另存为区块模板（r14）：客户端只发该 section 的 JSON 与名称，
             * 服务端组包走 Importer 安全链并发布——模板库刷新后立即可插回。
             */
            saveSectionAsTemplate(si) {
                var sec = this.sections[si];
                if (!sec) return;
                var name = window.prompt(this.templateText.saveAsPrompt, "");
                if (name === null) return;
                name = name.trim();
                if (name === "") { this.toast(this.templateText.saveAsNameRequired); return; }
                var self = this;
                var body = new URLSearchParams();
                body.set("action", "save_section");
                body.set("name", name);
                body.set("section", JSON.stringify(sec));
                body.set("page_intent", this.templatePageIntent);
                body.set("_token", this.csrf);
                fetch((window.YK_BASE || "") + "/admin/blox_template_api.php", { method: "POST", body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && Number(res.code) === 0) {
                            var item = res.data && res.data.template;
                            if (item) {
                                self.templateItems = window.BloxTemplateLibrary.upsertLocal(self.templateItems, item);
                                self.templateLoaded = true;
                                self.templateScope = "local";
                                self.templateFilter = "section";
                                self.templateQuery = "";
                                self.templateError = "";
                            }
                            self.toast(self.templateText.saveAsDone.replace(":name", name));
                            self.loadTemplates(true); // 后台对账；请求占用时登记为待刷新，不再丢弃
                        } else {
                            self.toast((res && (res.message || res.msg)) || self.homeText.actionFailed);
                        }
                    })
                    .catch(function () { self.toast(self.homeText.actionFailed); });
            },

            /** 面包屑项：从选择状态派生（不存 DOM/索引副本，重排、undo 后天然指向当前树） */
            breadcrumb() {
                var items = [];
                var si = this.selectedSi;
                var sec = si >= 0 ? this.sections[si] : null;
                if (!sec) return items;
                items.push({ act: "sec", label: runtimeText.sectionWord.replace(":n", si + 1) });
                if (this.selLayer === "con") {
                    items.push({ act: "con", label: runtimeText.treeContainer });
                    return items;
                }
                if (this.selectedCi < 0) return items;
                items.push({ act: "col", label: runtimeText.colWord.replace(":n", this.selectedCi + 1) });
                var col = (sec.columns || [])[this.selectedCi];
                var el = col && this.selectedEi >= 0 ? (col.elements || [])[this.selectedEi] : null;
                if (!el) return items;
                items.push({ act: "el", label: this.elLabel(el) });
                // 0b：子级路径逐层进面包屑，中间层可点回
                var node = el;
                for (var d = 0; d < this.selectedSubPath.length; d++) {
                    node = ((node.data || {}).children || [])[this.selectedSubPath[d]];
                    if (!node) break;
                    items.push({ act: "child", depth: d, label: this.elLabel(node) });
                }
                return items;
            },

            crumbGo(c) {
                var si = this.selectedSi, ci = this.selectedCi, ei = this.selectedEi;
                if (c.act === "sec") this.selectSection(si, false);
                else if (c.act === "con") this.selectContainer(si, false);
                else if (c.act === "col") this.selectColumn(si, ci, false);
                else if (c.act === "el") this.selectElement(si, ci, ei, false);
                else if (c.act === "child" && c.depth < this.selectedSubPath.length - 1) {
                    // 中间层子级：截断路径回到该层；最深层是当前项，无动作
                    this.selectDescendant(si, ci, ei, this.selectedSubPath.slice(0, c.depth + 1), false);
                }
            },

            /** 取消全部选择（点画布空白/宿主空白触发）——回到「插入到末尾」的初始语义。 */
            deselectAll() {
                this.expandLeftPanel();
                this.selectedSi = -1;
                this.selectedCi = -1;
                this.selectedEi = -1;
                this.setSubSelection([]);
                this.selectedSectionField = "";
                this.selectedHomeField = "";
                this.selectedHomeColumn = "";
                this.selLayer = "sec";
                this._insertAt = null;
                this.libOpen = false;
                this.mobilePanel = "library";
                this.multiSelClear();
                this.highlightCanvasSelection();
            },

            // ---- 同级多选（R1：选择模型；批量操作条为空壳，操作在后续轮次填充） ----
            // 集合按稳定 id 存储；模块缺失时降级为纯单选（不抛错）。
            <?php require __DIR__ . '/multi-selection-methods.php'; ?>
            handleCanvasDrop(payload) { return this.runCommand("canvas-drop", function () { return this._handleCanvasDropRaw(payload); }); },
            _handleCanvasDropRaw(payload) {
                var lib = this.elementLib.find(function (item) { return item.type === payload.type; });
                this.dragEl = null;
                if (!lib) return;
                if (lib.type === "__section") {
                    this.selectSection(payload.sec, false);
                    this.addSection(1);
                    return;
                }
                var target = payload.target && payload.target.kind
                    ? payload.target
                    : { kind: "column", sec: payload.sec, col: payload.col, position: "end" };
                var targetSi = target.kind === "column"
                    ? parseInt(target.sec, 10)
                    : parseInt(String(target.path || "").split(".")[0], 10);
                if (!isNaN(targetSi)) this.selectSection(targetSi, false);
                this.addElement(lib, target);
            },

            handleTemplateDrop(payload) {
                var item = this.templateItems.find(function (entry) { return entry.key === payload.key; });
                this.finishPaletteDrag();
                if (!item || item.type !== "section" || item.locked) return;
                this.insertTemplateAt(item, payload.index);
            },

            schedulePreview() {
                if (this.tableCanvasEditing) return;
                this.previewClient().schedule();
            },

            previewClient() {
                if (this._previewClient) return this._previewClient;
                var self = this;
                this._previewClient = new window.BloxPreviewClient({
                    endpoint: this.previewEndpoint,
                    csrf: this.csrf,
                    getFrame: function () { return self.$refs.canvas; },
                    getHost: function () { return self.$refs.canvasHost; },
                    getDocument: function () { return JSON.parse(self.documentData()); },
                    getDocumentJson: function () { return self.documentData(); },
                    getParams: function () {
                        if (self.productTemplateMode) return { preview_product: String(self.productPreviewId) };
                        // 文章样本预览：与产品同款通道参数，缺它画布只会显示"没有可预览的已发布文章"
                        if (self.articleTemplateMode) return { preview_article: String(self.articlePreviewId) };
                        return self.headerTemplateMode ? { header_state: self.headerPreviewState } : {};
                    },
                    setLoading: function (loading) { self.previewLoading = loading; },
                    onDiagnostic: function (info) {
                        if (new URLSearchParams(window.location.search).get('preview_debug') === '1' && self.$refs.canvas) {
                            self.$refs.canvas.setAttribute('data-preview-diagnostic', JSON.stringify(info));
                        }
                    },
                    onLoaded: function () {
                        self.previewFailed = false;
                        var shouldScroll = self._pendingInitialFocus;
                        self._pendingInitialFocus = false;
                        if (shouldScroll) {
                            self.highlightCanvasSelection(true);
                        } else {
                            self.scrollInitialFooterIntoView();
                            self.highlightCanvasSelection(false);
                        }
                    },
                    onError: function () { self.previewFailed = true; self.toast(self.uiText.previewFailed); },
                });
                return this._previewClient;
            },

            previewWidth() {
                return this.previewEffectiveWidth() + "px";
            },

            previewCustomWidthActive() {
                return this.previewCustomWidth > 0;
            },

            /** 画布内真实 viewport 宽度：自定义值优先，否则按档位默认。 */
            previewEffectiveWidth() {
                if (this.previewCustomWidthActive()) return this.previewCustomWidth;
                if (this.previewDevice === "desktop") return this.previewDesktopWidth();
                // 宽屏至少 1440（不足时画布按比例缩放）
                if (this.previewDevice === "wide") return Math.max(1440, Math.round(this.previewCanvasAvailable()));
                return ({ tablet: 768, mobile: 390 })[this.previewDevice] || 1280;
            },

            setPreviewCustomWidth(raw) {
                var clamp = window.BloxResponsive && window.BloxResponsive.clampPreviewWidth;
                this.previewCustomWidth = clamp ? clamp(raw) : 0;
            },

            clearPreviewCustomWidth() {
                this.previewCustomWidth = 0;
            },

            observeCanvasHost() {
                var self = this;
                var host = this.$refs.canvasHost;
                if (!host) return;
                var update = function () { self.canvasViewportTick++; };
                update();
                requestAnimationFrame(function () {
                    update();
                    requestAnimationFrame(update);
                });
                if (typeof ResizeObserver === "function") {
                    if (this._canvasResizeObserver) this._canvasResizeObserver.disconnect();
                    this._canvasResizeObserver = new ResizeObserver(update);
                    this._canvasResizeObserver.observe(host);
                    if (this.$refs.canvasViewport) this._canvasResizeObserver.observe(this.$refs.canvasViewport);
                }
            },

            previewCanvasAvailable() {
                this.canvasViewportTick;
                var viewport = this.$refs.canvasViewport;
                var host = viewport || this.$refs.canvasHost;
                var css = viewport ? window.getComputedStyle(viewport) : null;
                var padding = css ? (parseFloat(css.paddingLeft) || 0) + (parseFloat(css.paddingRight) || 0) : 24;
                return Math.max(320, (host ? host.clientWidth : 1280) - padding);
            },

            previewViewportStyle() {
                this.canvasViewportTick;
                return window.innerWidth >= 1440 && this.rightPanelCollapsed && this.previewDevice === 'desktop'
                    ? 'padding-right:0' : '';
            },

            previewDesktopWidth() {
                // 桌面档封顶 1439：更宽即进入宽屏档（全站关闭宽屏档时不封顶）
                return Math.min(this.wideTierEnabled() ? 1439 : 2560, Math.max(1280, Math.round(this.previewCanvasAvailable())));
            },

            previewScale() {
                // 自定义宽度：任何档位都缩放贴合工作区——iframe 实际宽度保持所标数值，
                // 绝不钳成宿主宽度再谎称原宽（R2B 第 4 条）。
                if (this.previewCustomWidthActive()) {
                    return Math.min(1, this.previewCanvasAvailable() / this.previewEffectiveWidth());
                }
                if (this.previewDevice !== "desktop") return 1;
                return Math.min(1, this.previewCanvasAvailable() / this.previewDesktopWidth());
            },

            previewShellStyle() {
                var visualHeight = this.previewCanvasHeight() + "px";
                if (!this.previewCustomWidthActive() && this.previewDevice !== "desktop") {
                    return "width:" + this.previewWidth() + ";height:" + visualHeight + ";max-width:100%;overflow:hidden";
                }
                var width = this.previewEffectiveWidth();
                return "width:" + Math.round(width * this.previewScale()) + "px;height:" + visualHeight
                    + ";max-width:100%;overflow:hidden";
            },

            previewCanvasHeight() {
                this.canvasViewportTick;
                var viewport = this.$refs.canvasViewport;
                if (!viewport) return Math.max(1, window.innerHeight - 80);
                var css = window.getComputedStyle(viewport);
                // Use the space remaining below notices and the selection breadcrumb.
                return Math.max(1, viewport.clientHeight - (parseFloat(css.paddingTop) || 0) - (parseFloat(css.paddingBottom) || 0));
            },

            previewFrameStyle() {
                if (!this.previewCustomWidthActive() && this.previewDevice !== "desktop") {
                    return "width:100%;height:100%;transform:none";
                }
                var scale = this.previewScale();
                var width = this.previewEffectiveWidth();
                var visualHeight = this.previewCanvasHeight();
                return "width:" + width + "px;height:" + Math.floor(visualHeight / scale) + "px;zoom:" + scale
                    + ";transform:none";
            },

            refreshPreview() {
                if (this.tableCanvasEditing) return Promise.resolve(false);
                return this.previewClient().refresh();
            },

            scrollInitialFooterIntoView() {
                if (!this.footerTemplateMode || !this._pendingInitialFooterScroll) return false;
                var frame = this.$refs.canvas;
                if (!frame || !frame.contentWindow || !frame.contentDocument) return false;
                try {
                    var footer = frame.contentDocument.querySelector('[data-yk-area="footer"]');
                    if (!footer) return false;
                    this._pendingInitialFooterScroll = false;
                    var root = frame.contentDocument.documentElement;
                    var previousBehavior = root.style.scrollBehavior;
                    var footerRect = footer.getBoundingClientRect();
                    root.style.scrollBehavior = "auto";
                    frame.contentWindow.scrollTo(0, Math.max(
                        0,
                        (frame.contentWindow.scrollY || 0) + footerRect.bottom - frame.contentWindow.innerHeight
                    ));
                    root.style.scrollBehavior = previousBehavior;
                    return true;
                } catch (error) {
                    return false;
                }
            },

            setHeaderPreviewState(state) {
                if (["normal", "overlay", "stuck"].indexOf(state) === -1) return;
                this.headerPreviewState = state;
                this.refreshPreview();
            },

            headerStateOpacity() {
                var states = this.docSettings && this.docSettings.header_states;
                var current = states && states[this.headerPreviewState] ? states[this.headerPreviewState] : {};
                var color = String(current.background || "").trim().toLowerCase();
                if (color === "transparent") return 0;
                var functional = color.match(/^(?:rgba|hsla)\([^)]*,\s*([0-9.]+)\s*\)$/i);
                if (functional) return Math.max(0, Math.min(100, Math.round(Number(functional[1]) * 100)));
                if (/^#[0-9a-f]{4}$/i.test(color)) return Math.round(parseInt(color.slice(4, 5) + color.slice(4, 5), 16) / 255 * 100);
                if (/^#[0-9a-f]{8}$/i.test(color)) return Math.round(parseInt(color.slice(7, 9), 16) / 255 * 100);
                return 100;
            },

            setHeaderStateOpacity(value) {
                this.normalizeHeaderSettings();
                var opacity = Math.max(0, Math.min(100, Number(value) || 0));
                var alpha = Math.round(opacity) / 100;
                var state = this.docSettings.header_states[this.headerPreviewState];
                var color = String(state.background || "").trim();
                var rgb = null;
                var hex = color.match(/^#([0-9a-f]{3}|[0-9a-f]{4}|[0-9a-f]{6}|[0-9a-f]{8})$/i);
                if (hex) {
                    var digits = hex[1];
                    if (digits.length === 3 || digits.length === 4) {
                        rgb = [0, 1, 2].map(function (index) {
                            return parseInt(digits[index] + digits[index], 16);
                        });
                    } else {
                        rgb = [0, 2, 4].map(function (index) {
                            return parseInt(digits.slice(index, index + 2), 16);
                        });
                    }
                }
                var rgbFunction = color.match(/^rgba?\(\s*([0-9.]+)\s*,\s*([0-9.]+)\s*,\s*([0-9.]+)/i);
                if (!rgb && rgbFunction) {
                    rgb = [1, 2, 3].map(function (index) {
                        return Math.max(0, Math.min(255, Math.round(Number(rgbFunction[index]))));
                    });
                }
                var hslFunction = color.match(/^hsla?\(\s*([0-9.+-]+)\s*,\s*([0-9.+-]+%)\s*,\s*([0-9.+-]+%)/i);
                if (hslFunction) {
                    state.background = "hsla(" + hslFunction[1] + "," + hslFunction[2] + "," + hslFunction[3] + "," + alpha + ")";
                } else {
                    rgb = rgb || [255, 255, 255];
                    state.background = "rgba(" + rgb[0] + "," + rgb[1] + "," + rgb[2] + "," + alpha + ")";
                }
                this.markDocumentSettingsChanged();
                this.refreshPreview();
            },

            normalizeHeaderSettings() {
                if (!this.headerTemplateMode) return;
                var defaults = {
                    normal: { background: "", text: "", border: "", shadow: "none" },
                    overlay: { background: "transparent", text: "#ffffff", border: "rgba(255,255,255,.18)", shadow: "none" },
                    stuck: { background: "#ffffff", text: "#111827", border: "#e5e7eb", shadow: "sm" },
                };
                this.docSettings = this.docSettings && typeof this.docSettings === "object" ? this.docSettings : {};
                this.docSettings.sticky_compact = this.docSettings.sticky_compact === true;
                this.docSettings.sticky_behavior = ["always", "scroll-up"].indexOf(this.docSettings.sticky_behavior) >= 0
                    ? this.docSettings.sticky_behavior : "always";
                var stickyDevices = Array.isArray(this.docSettings.sticky_devices)
                    ? this.docSettings.sticky_devices.filter(function (device) {
                        return device === "desktop" || device === "tablet" || device === "mobile";
                    }) : ["desktop", "tablet", "mobile"];
                this.docSettings.sticky_devices = stickyDevices.length > 0
                    ? Array.from(new Set(stickyDevices)) : ["desktop", "tablet", "mobile"];
                if (!Object.prototype.hasOwnProperty.call(this.docSettings, "header_overlay_enabled")) {
                    this.docSettings.header_overlay_enabled = true;
                }
                var states = this.docSettings.header_states && typeof this.docSettings.header_states === "object"
                    ? this.docSettings.header_states : {};
                Object.keys(defaults).forEach(function (state) {
                    states[state] = Object.assign({}, defaults[state], states[state] || {});
                });
                this.docSettings.header_states = states;
            },

            stickyDeviceEnabled(device) {
                return Array.isArray(this.docSettings.sticky_devices)
                    && this.docSettings.sticky_devices.indexOf(device) >= 0;
            },

            toggleStickyDevice(device, enabled) {
                if (["desktop", "tablet", "mobile"].indexOf(device) === -1) return;
                var devices = Array.isArray(this.docSettings.sticky_devices)
                    ? this.docSettings.sticky_devices.slice() : ["desktop", "tablet", "mobile"];
                var index = devices.indexOf(device);
                if (enabled && index === -1) devices.push(device);
                if (!enabled && index >= 0 && devices.length > 1) devices.splice(index, 1);
                this.docSettings.sticky_devices = ["desktop", "tablet", "mobile"].filter(function (item) {
                    return devices.indexOf(item) >= 0;
                });
                this.markDocumentSettingsChanged();
                this.refreshPreview();
            },

            addContactCard() {
                if (this.contactCards.length >= 4) {
                    this.toast(this.contactCardsText.limit);
                    return;
                }
                this.contactCards.push({ _key: this.uid("contact-card"), icon: "phone", label: "", value: "" });
                this.contactCardsChanged = true;
            },

            contactCardIcon(value) {
                var option = this.contactCardIconOptions.find(function (item) { return item.value === value; });
                return option ? option.icon : "ban";
            },

            removeContactCard(index) {
                if (!this.contactCards[index]) return;
                this.contactCards.splice(index, 1);
                this.contactCardsChanged = true;
            },

            moveContactCard(index, offset) {
                var next = index + offset;
                if (!this.contactCards[index] || next < 0 || next >= this.contactCards.length) return;
                var card = this.contactCards.splice(index, 1)[0];
                this.contactCards.splice(next, 0, card);
                this.contactCardsChanged = true;
            },

            pickContactCardImage(card) {
                var self = this;
                this.openMedia(function (url) {
                    card.value = url;
                    self.contactCardsChanged = true;
                });
            },

            saveContactCards() {
                if (this.contactCardsSaving || !this.contactCardsChanged) return;
                var incomplete = this.contactCards.some(function (card) {
                    return !String(card.label || "").trim() || !String(card.value || "").trim();
                });
                if (incomplete) {
                    this.toast(this.contactCardsText.incomplete);
                    return;
                }
                var body = new URLSearchParams();
                body.set("action", "save_cards");
                body.set("cards", JSON.stringify(this.contactCards));
                body.set("_token", this.csrf);
                var self = this;
                this.contactCardsSaving = true;
                fetch(this.contactEndpoint, { method: "POST", body: body })
                    .then(function (response) { return response.json(); })
                    .then(function (result) {
                        if (!result || Number(result.code) !== 0) {
                            throw new Error((result && result.msg) || self.contactCardsText.failed);
                        }
                        self.contactCards = (Array.isArray(result.data.cards) ? result.data.cards : []).map(function (card) {
                            card._key = self.uid("contact-card");
                            return card;
                        });
                        self.contactCardsChanged = false;
                        self.refreshPreview();
                        self.toast(self.contactCardsText.saved);
                    })
                    .catch(function (error) { self.toast(error.message || self.contactCardsText.failed); })
                    .finally(function () { self.contactCardsSaving = false; });
            },

            <?php require __DIR__ . '/site-data-methods.php'; ?>
            <?php require __DIR__ . '/theme-content-methods.php'; ?>
            addContactFormField() {
                if (!this.contactFormVisual || !this.contactFormCanEdit) return;
                if (this.contactForm.fields.length >= 12) {
                    this.toast(this.contactFormText.limit);
                    return;
                }
                var number = this.contactForm.fields.length + 1;
                var keys = this.contactForm.fields.map(function (field) { return String(field.key || "").toLowerCase(); });
                while (keys.indexOf("field_" + number) !== -1) number++;
                this.contactForm.fields.push({
                    _key: this.uid("contact-form-field"),
                    key: "field_" + number,
                    label: this.contactFormText.newField,
                    type: "text",
                    placeholder: "",
                    required: false,
                    enabled: true
                });
                this.contactFormChanged = true;
            },

            removeContactFormField(index) {
                if (!this.contactForm.fields[index]) return;
                this.contactForm.fields.splice(index, 1);
                this.contactFormChanged = true;
            },

            moveContactFormField(index, offset) {
                var next = index + offset;
                if (!this.contactForm.fields[index] || next < 0 || next >= this.contactForm.fields.length) return;
                var field = this.contactForm.fields.splice(index, 1)[0];
                this.contactForm.fields.splice(next, 0, field);
                this.contactFormChanged = true;
            },

            saveContactForm() {
                if (!this.contactFormVisual || !this.contactFormCanEdit || this.contactFormSaving || !this.contactFormChanged) return;
                var title = String(this.contactForm.title || "").trim();
                var successMessage = String(this.contactForm.success_message || "").trim();
                var keys = {};
                var invalid = !title || !successMessage || this.contactForm.fields.length === 0
                    || this.contactForm.fields.length > 12
                    || this.contactForm.fields.some(function (field) {
                        var key = String(field.key || "").trim().toLowerCase();
                        var bad = !/^[a-z][a-z0-9_-]*$/.test(key) || !!keys[key]
                            || !String(field.label || "").trim();
                        keys[key] = true;
                        return bad;
                    });
                if (invalid) {
                    this.toast(this.contactFormText.invalid);
                    return;
                }
                if (!this.contactForm.fields.some(function (field) { return !!field.enabled; })) {
                    this.toast(this.contactFormText.needEnabled);
                    return;
                }

                var body = new URLSearchParams();
                body.set("action", "save_form");
                body.set("title", title);
                body.set("description", String(this.contactForm.description || ""));
                body.set("success_message", successMessage);
                body.set("captcha", this.contactForm.captcha ? "1" : "0");
                body.set("fields", JSON.stringify(this.contactForm.fields));
                body.set("_token", this.csrf);
                var self = this;
                this.contactFormSaving = true;
                fetch(this.contactEndpoint, { method: "POST", body: body })
                    .then(function (response) { return response.json(); })
                    .then(function (result) {
                        if (!result || Number(result.code) !== 0 || !result.data || !result.data.form) {
                            throw new Error((result && result.msg) || self.contactFormText.failed);
                        }
                        var saved = result.data.form;
                        saved.fields = (Array.isArray(saved.fields) ? saved.fields : []).map(function (field) {
                            field._key = self.uid("contact-form-field");
                            return field;
                        });
                        self.contactForm = saved;
                        self.contactFormChanged = false;
                        self.refreshPreview();
                        self.toast(self.contactFormText.saved);
                    })
                    .catch(function (error) { self.toast(error.message || self.contactFormText.failed); })
                    .finally(function () { self.contactFormSaving = false; });
            },

            replayElementAnimation() {
                if (!this.selEl || (!this.selEl.data.animation && !BloxControlRules.checkboxValue(this.selEl.data.animation_stagger)) || this.previewLoading) return;
                this.canvasBridge().post({ ykReplayAnimation: {
                    id: this.selectedElementId(), path: this.selectedPath()
                } });
            },

            selectedPath() {
                if (this.selectedSi < 0 || this.selectedCi < 0 || this.selectedEi < 0) return "";
                var path = [this.selectedSi, this.selectedCi, this.selectedEi];
                return path.concat(this.selectedSubPath).join(".");
            },

            selectedSectionId() {
                var section = this.selectedSi >= 0 ? this.sections[this.selectedSi] : null;
                return section && section.id ? String(section.id) : "";
            },

            selectedElementId() {
                var element = this.elementAtPath(this.selectedPath());
                return element && element.id ? String(element.id) : "";
            },

            sectionIndexById(id, legacyIndex) {
                var sectionId = typeof id === "string" ? id : "";
                if (sectionId) {
                    return this.sections.findIndex(function (section) {
                        return String((section || {}).id || "") === sectionId;
                    });
                }
                return Number.isInteger(legacyIndex) && this.sections[legacyIndex] ? legacyIndex : -1;
            },

            selectSectionTarget(target, notifyCanvas) {
                target = target && typeof target === "object" ? target : {};
                var si = this.sectionIndexById(target.id, target.si);
                if (si >= 0) this.selectSection(si, notifyCanvas);
            },

            elementPathById(id) {
                var elementId = typeof id === "string" ? id : "";
                if (!elementId) return "";
                // 0b：递归下钻 children，任意深度的稳定 id 都能回到完整路径
                var findIn = function (node, prefix) {
                    if (String((node || {}).id || "") === elementId) return prefix.join(".");
                    var children = (((node || {}).data || {}).children) || [];
                    for (var k = 0; k < children.length; k++) {
                        var found = findIn(children[k], prefix.concat(k));
                        if (found) return found;
                    }
                    return "";
                };
                for (var si = 0; si < this.sections.length; si++) {
                    var columns = (this.sections[si] || {}).columns || [];
                    for (var ci = 0; ci < columns.length; ci++) {
                        var elements = (columns[ci] || {}).elements || [];
                        for (var ei = 0; ei < elements.length; ei++) {
                            var found = findIn(elements[ei], [si, ci, ei]);
                            if (found) return found;
                        }
                    }
                }
                return "";
            },

            selectElementTarget(target, notifyCanvas) {
                target = target && typeof target === "object" ? target : {};
                var path = typeof target.id === "string" && target.id
                    ? this.elementPathById(target.id)
                    : (typeof target.path === "string" ? target.path : "");
                if (path) this.selectPath(path, notifyCanvas);
            },

            applyInitialNodeFocus() {
                var elementPath = this.elementPathById(this.initialFocusElementId);
                if (elementPath) {
                    this.selectPath(elementPath, false);
                    this._pendingInitialFocus = true;
                    return true;
                }
                var si = this.sectionIndexById(this.initialFocusSectionId, -1);
                if (si < 0) return false;
                this.selectSection(si, false);
                this._pendingInitialFocus = true;
                return true;
            },

            selectPath(path, notifyCanvas) {
                var parts = String(path).split(".").map(function (v) { return parseInt(v, 10); });
                if (parts.length < 3 || parts.some(function (v) { return isNaN(v); })) return;
                if (!this.elementAtPath(String(path))) return;
                if (parts.length >= 4) {
                    // 0b：第 4 段起是子级路径，任意深度统一走 selectDescendant
                    this.selectDescendant(parts[0], parts[1], parts[2], parts.slice(3), notifyCanvas);
                } else {
                    this.selectElement(parts[0], parts[1], parts[2], notifyCanvas);
                }
            },

            highlightCanvasSelection(scrollToSelection) {
                var frame = this.$refs.canvas;
                if (!frame || !frame.contentWindow) return;
                var path = this.selectedPath();
                var message = { ykScroll: scrollToSelection === true };
                // 复合元素区域选择：画布滚动/闪烁到对应区域（与结构树区域节点对应）
                var regionElementId = this.selectedElementId();
                if (this.selectedRegion && regionElementId) {
                    message.ykHighlightRegion = { id: regionElementId, region: this.selectedRegion };
                }
                if (this.selectedHomeField && path
                    && this.homeFieldAllowed(this.selTopEl, this.selectedHomeField)) {
                    message.ykHighlightHomeField = { path: path, field: this.selectedHomeField };
                } else if (this.selectedHomeColumn && path
                    && this.homeColumnAllowed(this.selTopEl, this.selectedHomeColumn)) {
                    message.ykHighlightHomeColumn = { path: path, column: this.selectedHomeColumn };
                } else if (this.selectedSubEi >= 0 && this.isHomeBannerHost(this.selTopEl)) {
                    message.ykBannerSlide = this.selectedSubEi;
                    message.ykBannerPath = this.selectedSi + "." + this.selectedCi + "." + this.selectedEi;
                    message.ykHighlightEl = this.selectedSi + "." + this.selectedCi + "." + this.selectedEi;
                } else if (this.selectedSectionField && this.selectedSi >= 0) {
                    message.ykHighlightSectionField = { si: this.selectedSi, field: this.selectedSectionField };
                } else if (path && this.selectedElementId()) message.ykHighlightElementId = this.selectedElementId();
                else if (path) message.ykHighlightEl = path;
                else if (this.selectedSi >= 0 && this.selLayer === "col" && this.selectedCi >= 0) message.ykHighlightCol = this.selectedSi + "." + this.selectedCi;
                else if (this.selectedSi >= 0 && this.selLayer === "con") message.ykHighlightCon = this.selectedSi;
                else if (this.selectedSi >= 0 && this.selectedSectionId()) message.ykHighlightSectionId = this.selectedSectionId();
                else if (this.selectedSi >= 0) message.ykHighlight = this.selectedSi;
                else return;
                this.canvasBridge().post(message);
                this.syncMultiSelectionToCanvas();
            },

            /** Right-tree layer changes keep the user's content/style working context. */
            selectSectionFromTree(si, notifyCanvas) {
                var nextPanelTab = this.panelTab === "style" ? "style" : "content";
                this.selectSection(si, notifyCanvas);
                this.panelTab = nextPanelTab;
            },

            selectSection(si, notifyCanvas) {
                this.multiSelClear();
                this.selectedSi = si;
                this.targetCi = 0;
                this.selectedCi = -1;
                this.selectedEi = -1;
                this.setSubSelection([]);
                this.selectedSectionField = "";
                this.selectedHomeField = "";
                this.selectedHomeColumn = "";
                this.resetBoxPanelState();
                this.selLayer = "sec";
                this.libOpen = false;
                this.openMobileSettings();
                this.panelTab = "content";
                this.ctrlQuery = "";
                if (notifyCanvas !== false) this.highlightCanvasSelection(true);
            },

            /** Select the section's inner container layer separately from the section background layer. */
            selectContainer(si, notifyCanvas) {
                this.multiSelClear();
                this.selectedSi = si;
                this.targetCi = 0;
                this.selectedCi = -1;
                this.selectedEi = -1;
                this.setSubSelection([]);
                this.selectedSectionField = "";
                this.selectedHomeField = "";
                this.selectedHomeColumn = "";
                this.resetBoxPanelState();
                this.selLayer = "con";
                this.libOpen = false;
                this.openMobileSettings();
                this.panelTab = "style";
                this.ctrlQuery = "";
                if (notifyCanvas !== false) this.highlightCanvasSelection(true);
            },

            selectColumn(si, ci, notifyCanvas) {
                this.multiSelClear();
                if (!this.sections[si] || !this.sections[si].columns[ci]) return;
                this.selectedSi = si;
                this.targetCi = ci;
                this.selectedCi = ci;
                this.selectedEi = -1;
                this.setSubSelection([]);
                this.selectedSectionField = "";
                this.selectedHomeField = "";
                this.selectedHomeColumn = "";
                this.selLayer = "col";
                this.libOpen = false;
                this.openMobileSettings();
                this.panelTab = "style";
                this.ctrlQuery = "";
                // 列可能覆盖整屏或更高；scrollIntoView 会把画布强制拉到列顶部。
                // 选择列只更新高亮和设置面板，不改变用户当前查看位置。
                if (notifyCanvas !== false) this.highlightCanvasSelection(false);
            },

            isContainerSelected(si) {
                return this.selectedSi === si && this.selectedCi < 0 && this.selLayer === "con";
            },

            elCount(section) {
                // 容器的子元素也计数：树和「本区块含 N 个元素」都按可见节点算
                return (section.columns || []).reduce(function(n, c) {
                    return n + (c.elements || []).reduce(function(m, e) {
                        return m + 1 + (((e.data || {}).children) || []).length;
                    }, 0);
                }, 0);
            },

            uid(p) { return p + "_" + Math.random().toString(36).substr(2, 9); },

            /** 补齐/去重所有层级的 id（区块/列/元素/容器子元素）。只在本地补，保存时自然带上 */
            normalizeIds() {
                var self = this;
                var seen = {};
                var fix = function (obj, prefix) {
                    if (!obj.id || seen[obj.id]) obj.id = self.uid(prefix);
                    seen[obj.id] = true;
                };
                (this.sections || []).forEach(function (s) {
                    fix(s, "s");
                    (s.columns || []).forEach(function (c) {
                        fix(c, "c");
                        (c.elements || []).forEach(function (e) {
                            fix(e, "e");
                            (((e.data || {}).children) || []).forEach(function (k) { fix(k, "e"); });
                        });
                    });
                });
            },

            elementAtPath(path) {
                var parts = String(path || "").split(".").map(function (v) { return parseInt(v, 10); });
                // 0b：3..10 段（与画布桥、服务端 MAX_ELEMENT_DEPTH 对齐），第 4 段起沿 children 下钻
                if (parts.length < 3 || parts.length > 10 || parts.some(function (v) { return isNaN(v); })) return null;
                var section = this.sections[parts[0]];
                var column = section && section.columns ? section.columns[parts[1]] : null;
                var element = column && column.elements ? column.elements[parts[2]] : null;
                for (var i = 3; element && i < parts.length; i++) {
                    element = ((element.data || {}).children || [])[parts[i]] || null;
                }
                return element || null;
            },

            plainTextHtml(value) {
                var text = String(value || "").replace(/\r/g, "").trim();
                if (!text) return "";
                var escapeHtml = function (part) {
                    return part.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
                        .replace(/"/g, "&quot;").replace(/'/g, "&#039;");
                };
                return text.split(/\n{2,}/).map(function (paragraph) {
                    return "<p>" + paragraph.split("\n").map(escapeHtml).join("<br>") + "</p>";
                }).join("");
            },

            applyInlineEdit(data) {
                if (!data || typeof data.value !== "string") return;
                if (data.kind === 'tableCell') {
                    if (typeof this.applyTableCanvasCell === 'function') this.applyTableCanvasCell(data);
                    return;
                }
                if (data.kind === "sectionField") {
                    var si = parseInt(data.si, 10);
                    if (!this.sections[si] || (data.field !== "title" && data.field !== "subtitle")) return;
                    this.selectSectionField(si, data.field, false);
                    this.sections[si].settings = this.sections[si].settings || {};
                    this.sections[si].settings[data.field] = data.value;
                    return;
                }
                if (data.kind === "homeField" && typeof data.path === "string") {
                    var homeBlock = this.elementAtPath(data.path);
                    if (!this.homeFieldAllowed(homeBlock, data.field)) return;
                    this.selectHomeField(data.path, data.field, false);
                    this.setHomeFieldValue(homeBlock, data.field, data.value);
                    return;
                }
                if (data.kind !== "element" || typeof data.path !== "string") return;
                var el = this.elementAtPath(data.path);
                if (!el) return;
                el.data = el.data || {};
                if ((el.type === "heading" || el.type === "button") && data.field === "text" && data.format === "text") {
                    this.selectPath(data.path, false);
                    el.data.text = data.value;
                    return;
                }
                if (el.type === "text" && data.field === "html" && data.format === "plain") {
                    this.selectPath(data.path, false);
                    el.data.html = this.plainTextHtml(data.value);
                }
            },

            sectionFieldName(field) {
                return field === "subtitle" ? this.ctxText.subtitle : this.ctxText.sectionTitle;
            },

            sectionFieldLabel(field) {
                return this.ctxText.editPrefix + this.sectionFieldName(field);
            },

            setSectionField(si, field, value) {
                var s = this.sections[si];
                if (!s || (field !== "title" && field !== "subtitle")) return;
                s.settings = s.settings || {};
                s.settings[field] = value;
                this.selectSectionField(si, field);
            },

            editSectionField(si, field) {
                var s = this.sections[si];
                if (!s || (field !== "title" && field !== "subtitle")) return;
                s.settings = s.settings || {};
                this.selectSectionField(si, field);
                var current = s.settings[field] || "";
                var next = prompt(this.sectionFieldLabel(field), current);
                if (next === null) return;
                s.settings[field] = next;
            },

            quickEditSelected() {
                var el = this.selEl;
                if (!el) return;
                el.data = el.data || {};
                if (el.type === 'table') {
                    if (this.professionalFeatures.table.allowed && typeof this.openTableExpanded === 'function') this.openTableExpanded();
                    return;
                }
                if (el.type === "text") {
                    this.openRte(function () { return el.data.html || ""; }, function (v) { el.data.html = v; }, true);
                    return;
                }
                if (el.type === "heading") {
                    this.quickPrompt(el, "text", this.ctxText.editTitle);
                    return;
                }
                if (el.type === "button") {
                    this.quickPrompt(el, "text", this.ctxText.editButtonText);
                    return;
                }
                this.panelTab = "content";
                this.libOpen = false;
                this.toast(this.ctxText.elementSelected);
            },

            quickPrompt(el, key, title) {
                var current = (el.data && el.data[key]) || "";
                var next = prompt(title || this.ctxText.editText, current);
                if (next === null) return;
                el.data[key] = next;
            },

            <?php require __DIR__ . '/clipboard-methods.php'; ?>
            <?php require __DIR__ . '/command-palette-methods.php'; ?>
            <?php require __DIR__ . '/advanced-code-methods.php'; ?>
            closeCtx() {
                this.ctx.open = false;
            },

            selectCtxTarget(kind, t, notifyCanvas) {
                t = t || {};
                if (kind === "canvas") return;
                if (kind === "sectionField") { this.selectSectionField(t.si, t.field, notifyCanvas); return; }
                if (kind === "child") {
                    // 0b：优先用完整路径（嵌套容器的深层子级）；无 path 时按单层 cei
                    var deep = typeof t.path === "string" ? t.path.split(".").map(function (v) { return parseInt(v, 10); }) : null;
                    var subPath = Array.isArray(t.subPath) ? t.subPath
                        : (deep && deep.length > 4 ? deep.slice(3) : [t.cei]);
                    this.selectDescendant(t.si, t.ci, t.ei, subPath, notifyCanvas);
                }
                else if (kind === "element") this.selectElement(t.si, t.ci, t.ei, notifyCanvas);
                else if (kind === "column") this.selectColumn(t.si, t.ci, notifyCanvas);
                else if (kind === "container") this.selectContainer(t.si, notifyCanvas);
                else if (kind === "section") this.selectSection(t.si, notifyCanvas);
            },

            openCtx(evt, kind, target) {
                if (evt && evt.preventDefault) evt.preventDefault();
                this.selectCtxTarget(kind, target, true);
                this.showCtx(kind, target, evt ? evt.clientX : 0, evt ? evt.clientY : 0);
            },

            openCtxFromCanvas(d) {
                var frame = this.$refs.canvas;
                var r = frame ? frame.getBoundingClientRect() : { left: 0, top: 0 };
                var target = d.target || {};
                this.selectCtxTarget(d.kind, target, false);
                this.showCtx(d.kind, target, r.left + (d.x || 0), r.top + (d.y || 0));
            },

            showCtx(kind, target, x, y) {
                var w = 190, h = 260;
                this.ctx.kind = kind;
                this.ctx.target = Object.assign({}, target || {});
                this.ctx.x = Math.max(8, Math.min(window.innerWidth - w - 8, Math.round(x || 0)));
                this.ctx.y = Math.max(8, Math.min(window.innerHeight - h - 8, Math.round(y || 0)));
                this.ctx.open = true;
            },

            ctxItems() {
                var k = this.ctx.kind;
                var t = this.ctx.target || {};
                var items = [];
                if (k !== "canvas") items.push({ key: "settings", label: this.ctxText.settings, icon: "adjustments" });
                if (k === "canvas") {
                    items.push({ key: "addSectionEnd", label: this.ctxText.addSection, icon: "layout-board-split" });
                    items.push({ key: "addLayout2", label: this.ctxText.addLayout2, icon: "layout-columns" });
                    items.push({ key: "addLayout3", label: this.ctxText.addLayout3, icon: "columns-3" });
                    items.push({ key: "addElement", label: this.ctxText.addElement, icon: "plus" });
                    if (this.clipboard) items.push({ key: "paste", label: this.clipboardText.paste, icon: "clipboard", disabled: !this.canPasteTo("canvas", {}) });
                    return items;
                }
                if (k === "sectionField") {
                    items.push({ key: "editSectionField", label: this.ctxText.editText, icon: "pencil" });
                    items.push({ key: "clearSectionField", label: this.ctxText.clearText, icon: "eraser", danger: true });
                    return items;
                }
                if (k === "section") {
                    items.push({ key: "addSectionAfter", label: this.ctxText.addSectionAfter, icon: "layout-board-split" });
                    items.push({ key: "addElement", label: this.ctxText.addElement, icon: "plus" });
                    if (this.clipboard) items.push({ key: "paste", label: this.clipboardText.paste, icon: "clipboard", disabled: !this.canPasteTo("section", t) });
                    items.push({ sep: true, key: "s1" });
                    items.push({ key: "moveUp", label: this.ctxText.moveUp, icon: "arrow-up", disabled: t.si <= 0 });
                    items.push({ key: "moveDown", label: this.ctxText.moveDown, icon: "arrow-down", disabled: t.si >= this.sections.length - 1 });
                    items.push({ key: "duplicate", label: this.ctxText.duplicateSection, icon: "copy" });
                    items.push({ key: "saveAsTemplate", label: this.ctxText.saveAsTemplate, icon: "template" });
                    items.push({ sep: true, key: "s2" });
                    items.push({ key: "delete", label: this.ctxText.deleteSection, icon: "trash", danger: true });
                    return items;
                }
                if (k === "container" || k === "column") {
                    items.push({ key: "addElement", label: this.ctxText.addElement, icon: "plus" });
                    if (k === "column") items.push({ key: "split", label: this.layoutSplitText.split, icon: "layout-columns" });
                    if (this.clipboard) items.push({ key: "paste", label: this.clipboardText.paste, icon: "clipboard", disabled: !this.canPasteTo(k, t) });
                    items.push({ key: "addSectionAfter", label: this.ctxText.addSectionAfter, icon: "layout-board-split" });
                    if (this.ctxSectionIsEmpty(t)) {
                        items.push({ sep: true, key: "empty-section-separator" });
                        items.push({ key: "deleteSection", label: this.ctxText.deleteEmptySection, icon: "trash", danger: true });
                    }
                    return items;
                }
                if (k === "element" || k === "child") {
                    var el = k === "element"
                        ? this.sections[t.si]?.columns[t.ci]?.elements[t.ei]
                        : this.elementAtPath([t.si, t.ci, t.ei].concat(this.ctxChildSubPath(t)).join("."));
                    var isContainer = !!(el && this.elSchema(el.type).container && k === "element"
                        && (!this.isHomeBlockHost(el) || this.isHomeBannerHost(el)));
                    items.push({ key: "addChild", label: this.ctxText.addChild, icon: "corner-down-right", disabled: !isContainer });
                    if (this.ctxSplitTarget()) items.push({ key: "split", label: this.layoutSplitText.split, icon: "layout-columns" });
                    items.push({ key: "copy", label: this.clipboardText.copy, icon: "copy" });
                    items.push({ key: "cut", label: this.clipboardText.cut, icon: "scissors" });
                    if (this.clipboard) items.push({ key: "paste", label: this.clipboardText.paste, icon: "clipboard", disabled: !this.canPasteTo(k, t) });
                    items.push({ sep: true, key: "e1" });
                    items.push({ key: "moveUp", label: this.ctxText.moveUp, icon: "arrow-up", disabled: !this.ctxCanMove(-1) });
                    items.push({ key: "moveDown", label: this.ctxText.moveDown, icon: "arrow-down", disabled: !this.ctxCanMove(1) });
                    items.push({ key: "duplicate", label: this.ctxText.duplicateItem, icon: "copy" });
                    items.push({ sep: true, key: "e2" });
                    items.push({ key: "delete", label: this.ctxText.deleteItem, icon: "trash", danger: true });
                }
                return items;
            },

            runCtx(action) {
                if (action === "saveAsTemplate") {
                    this.closeCtx();
                    this.saveSectionAsTemplate((this.ctx.target || {}).si);
                    return;
                }
                var k = this.ctx.kind;
                var t = this.ctx.target || {};
                this.closeCtx();
                if (action === "settings") { this.selectCtxTarget(k, t, true); return; }
                if (action === "copy") { this.selectCtxTarget(k, t, false); this.copySelection(); return; }
                if (action === "cut") { this.selectCtxTarget(k, t, false); this.cutSelection(); return; }
                if (action === "paste") { this.pasteClipboard(k, t); return; }
                if (action === "editSectionField") { this.editSectionField(t.si, t.field); return; }
                if (action === "clearSectionField") { this.setSectionField(t.si, t.field, ""); return; }
                if (action === "addSectionEnd") { this.selectedSi = -1; this.addSection(1); return; }
                if (action === "addLayout2") { this.selectedSi = -1; this.addLayout([6, 6]); return; }
                if (action === "addLayout3") { this.selectedSi = -1; this.addLayout([4, 4, 4]); return; }
                if (action === "addElement") {
                    if (k === "column") this.selectColumn(t.si, t.ci);
                    else if (k === "container") this.selectContainer(t.si);
                    else if (k === "section") this.selectSection(t.si);
                    this.libOpen = true;
                    return;
                }
                if (action === "addChild") { this.selectElement(t.si, t.ci, t.ei); this.libOpen = true; return; }
                if (action === "split") { this.openSplitFromCtx(); return; }
                if (action === "addSectionAfter") { this.selectSection(t.si); this.addSection(1); return; }
                if (action === "deleteSection") { this.deleteSection(t.si); return; }
                if (action === "moveUp") { this.ctxMove(-1); return; }
                if (action === "moveDown") { this.ctxMove(1); return; }
                if (action === "duplicate") { this.ctxDuplicate(); return; }
                if (action === "delete") { this.ctxDelete(); return; }
            },

            ctxSectionIsEmpty(target) {
                target = target || {};
                var section = this.sections[target.si];
                if (!section || this.elCount(section) !== 0) return false;
                var settings = section.settings || {};
                return String(settings.title || "").trim() === ""
                    && String(settings.subtitle || "").trim() === "";
            },

            /** 0b：child 类 ctx 目标的子级路径（深层行带 subPath/path，单层退回 [cei]）。 */
            ctxChildSubPath(t) {
                if (Array.isArray(t.subPath) && t.subPath.length) return t.subPath.slice();
                if (typeof t.path === "string") {
                    var parts = t.path.split(".").map(function (v) { return parseInt(v, 10); });
                    if (parts.length > 4 && !parts.some(function (v) { return isNaN(v); })) return parts.slice(3);
                }
                return [parseInt(t.cei, 10)];
            },

            ctxCanMove(dir) {
                var k = this.ctx.kind, t = this.ctx.target || {};
                if (k === "section") return t.si + dir >= 0 && t.si + dir < this.sections.length;
                if (k === "element") {
                    var els = this.sections[t.si]?.columns[t.ci]?.elements || [];
                    return t.ei + dir >= 0 && t.ei + dir < els.length;
                }
                if (k === "child") {
                    var subPath = this.ctxChildSubPath(t);
                    var parent = this.subPathParent(t.si, t.ci, t.ei, subPath);
                    var kids = parent && parent.data ? (parent.data.children || []) : [];
                    var index = subPath[subPath.length - 1];
                    return index + dir >= 0 && index + dir < kids.length;
                }
                return false;
            },

            ctxMove(dir) {
                var k = this.ctx.kind, t = this.ctx.target || {};
                if (k === "section") this.moveSection(t.si, dir);
                else if (k === "element") this.moveElement(t.si, t.ci, t.ei, dir);
                else if (k === "child") this.moveNodeAt(t.si, t.ci, t.ei, this.ctxChildSubPath(t), dir);
                this.highlightCanvasSelection();
            },

            deepCloneNode(node, prefix) {
                var copy = JSON.parse(JSON.stringify(node));
                var self = this;
                function fix(n) {
                    n.id = self.uid(prefix || "e");
                    (((n.data || {}).children) || []).forEach(fix);
                }
                fix(copy);
                return copy;
            },

            ctxDuplicate() {
                var k = this.ctx.kind, t = this.ctx.target || {};
                if (k === "section") { this.duplicateSection(t.si); return; }
                if (k === "element") {
                    var els = this.sections[t.si]?.columns[t.ci]?.elements;
                    if (!els || !els[t.ei]) return;
                    els.splice(t.ei + 1, 0, this.deepCloneNode(els[t.ei], "e"));
                    this.selectElement(t.si, t.ci, t.ei + 1);
                    return;
                }
                if (k === "child") {
                    var subPath = this.ctxChildSubPath(t);
                    var parent = this.subPathParent(t.si, t.ci, t.ei, subPath);
                    var kids = parent && parent.data ? (parent.data.children || []) : [];
                    var index = subPath[subPath.length - 1];
                    if (!kids[index]) return;
                    kids.splice(index + 1, 0, this.deepCloneNode(kids[index], "e"));
                    this.selectDescendant(t.si, t.ci, t.ei, subPath.slice(0, -1).concat(index + 1));
                }
            },

            ctxDelete() {
                var k = this.ctx.kind, t = this.ctx.target || {};
                if (k === "section") this.deleteSection(t.si);
                else if (k === "element") this.deleteElement(t.si, t.ci, t.ei);
                else if (k === "child") this.deleteNodeAt(t.si, t.ci, t.ei, this.ctxChildSubPath(t));
                this.highlightCanvasSelection();
            },

            <?php require __DIR__ . '/tree-sortable-methods.php'; ?>

            sortSections(oldIndex, newIndex) {
                if (oldIndex === newIndex || oldIndex < 0 || newIndex < 0) return;
                var selectedId = this.sel ? this.sel.id : "";
                var s = this.sections.splice(oldIndex, 1)[0];
                this.sections.splice(newIndex, 0, s);
                if (selectedId) this.selectedSi = this.sections.findIndex(function (x) { return x.id === selectedId; });
            },

            sortElements(si, ci, oldIndex, newIndex) {
                var col = this.sections[si] && this.sections[si].columns[ci];
                if (!col || oldIndex === newIndex || oldIndex < 0 || newIndex < 0) return;
                var selectedId = this.selTopEl ? this.selTopEl.id : "";
                var item = col.elements.splice(oldIndex, 1)[0];
                col.elements.splice(newIndex, 0, item);
                if (selectedId) this.selectedEi = col.elements.findIndex(function (x) { return x.id === selectedId; });
            },

            moveElementsBetweenColumns(fromSi, fromCi, toSi, toCi, oldIndex, newIndex) {
                if (fromSi === toSi && fromCi === toCi) {
                    this.sortElements(fromSi, fromCi, oldIndex, newIndex);
                    return;
                }
                var fromSection = this.sections[fromSi];
                var toSection = this.sections[toSi];
                var fromColumn = fromSection && fromSection.columns ? fromSection.columns[fromCi] : null;
                var toColumn = toSection && toSection.columns ? toSection.columns[toCi] : null;
                if (!fromColumn || !toColumn || oldIndex < 0 || oldIndex >= fromColumn.elements.length) return;
                var selectedId = this.selTopEl ? this.selTopEl.id : "";
                var item = fromColumn.elements.splice(oldIndex, 1)[0];
                if (!item) return;
                var index = Math.min(toColumn.elements.length, Math.max(0, newIndex));
                toColumn.elements.splice(index, 0, item);
                if (!selectedId) return;
                for (var si = 0; si < this.sections.length; si++) {
                    var columns = this.sections[si].columns || [];
                    for (var ci = 0; ci < columns.length; ci++) {
                        var elements = columns[ci].elements || [];
                        for (var ei = 0; ei < elements.length; ei++) {
                            if (elements[ei].id === selectedId) {
                                this.selectedSi = si;
                                this.selectedCi = ci;
                                this.selectedEi = ei;
                                return;
                            }
                        }
                    }
                }
            },
            sortChildren(si, ci, ei, oldIndex, newIndex) {
                this.sortChildrenAt(si, ci, ei, [], oldIndex, newIndex);
            },

            /** 0b：ownerSubPath 指向持有 children 列表的节点（[] = 顶层元素本身）。 */
            sortChildrenAt(si, ci, ei, ownerSubPath, oldIndex, newIndex) {
                var owner = this.sections[si] && this.sections[si].columns[ci] && this.sections[si].columns[ci].elements[ei];
                for (var i = 0; owner && i < ownerSubPath.length; i++) {
                    owner = ((owner.data || {}).children || [])[ownerSubPath[i]] || null;
                }
                var kids = owner && owner.data ? (owner.data.children || []) : [];
                if (!kids.length || oldIndex === newIndex || oldIndex < 0 || newIndex < 0) return;
                var depth = ownerSubPath.length;
                var movedSubPath = ownerSubPath.concat(oldIndex);
                var followSelection = this.selectionUnderSubPath(si, ci, ei, movedSubPath);
                var siblingAffected = !followSelection
                    && this.selectedSi === si && this.selectedCi === ci && this.selectedEi === ei
                    && this.selectedSubPath.length > depth
                    && this.selectionUnderSubPath(si, ci, ei, ownerSubPath.concat(this.selectedSubPath[depth]));
                var item = kids.splice(oldIndex, 1)[0];
                kids.splice(newIndex, 0, item);
                if (followSelection) {
                    var followed = this.selectedSubPath.slice();
                    followed[depth] = newIndex;
                    this.setSubSelection(followed);
                } else if (siblingAffected) {
                    var sibling = this.selectedSubPath[depth];
                    var shifted = this.selectedSubPath.slice();
                    if (oldIndex < sibling && newIndex >= sibling) shifted[depth] = sibling - 1;
                    else if (oldIndex > sibling && newIndex <= sibling) shifted[depth] = sibling + 1;
                    if (shifted[depth] !== sibling) this.setSubSelection(shifted);
                }
            },

            moveSection(si, dir) {
                var ni = si + dir;
                if (ni < 0 || ni >= this.sections.length) return;
                var s = this.sections.splice(si, 1)[0];
                this.sections.splice(ni, 0, s);
                this.selectedSi = ni;
            },

            duplicateSection(si) {
                var copy = JSON.parse(JSON.stringify(this.sections[si]));
                copy.id = this.uid("s");
                (copy.columns || []).forEach(function(c) { c.id = "c_" + Math.random().toString(36).substr(2, 9); });
                this.sections.splice(si + 1, 0, copy);
                this.selectedSi = si + 1;
            },

            deleteSection(si) { return this.runCommand("delete-section", function () { return this._deleteSectionRaw(si); }); },
            _deleteSectionRaw(si) {
                if (!confirm(this.uiText.confirmDeleteSection)) return;
                this.sections.splice(si, 1);
                if (this.selectedSi === si) this.selectedSi = -1;
                else if (this.selectedSi > si) this.selectedSi--;
            },

            /**
             * 插入区块。
             * 位置：有选中项 → 插到它**之后**；没有 → 追加到末尾。
             * 这比「永远追加」符合直觉——在中间调整版面时不必插完再一路上移。
             * settings 与通用 BlockRenderer 渲染等价：默认值不写入时也按同样规则处理，
             * title/subtitle/bg_opacity/col_card，这里写成显式默认值，渲染器对
             * 「缺键」与这些值的处理相同（空标题不输出、bg_opacity 默认 100、
             * col_card 空即不启用）。两个编辑器共用同一份 blocks_data，
             * 默认值若真不一致，来回切换会造成版面漂移。
             */
            addLayout(spans) {
                if (!Array.isArray(spans) || spans.length < 1) return;
                this.addSection(spans);
            },

            normalizedLayoutSpans(spans) {
                if (!Array.isArray(spans) || spans.length < 1) return [];
                return spans.map(function (n) {
                    n = parseInt(n, 10);
                    return Math.min(12, Math.max(1, isNaN(n) ? 12 : n));
                });
            },

            layoutPresetActive(spans) {
                var s = this.sel;
                if (!s || !Array.isArray(s.columns)) return false;
                var normalized = this.normalizedLayoutSpans(spans);
                if (normalized.length !== s.columns.length) return false;
                for (var i = 0; i < normalized.length; i++) {
                    var current = this.rawSpanD(s.columns[i]);
                    if (!current) current = Math.floor(12 / Math.max(1, s.columns.length));
                    if (current !== normalized[i]) return false;
                }
                return true;
            },

            swapSelectedColumns() {
                var s = this.sel;
                if (!s || !Array.isArray(s.columns) || s.columns.length !== 2) return;
                s.columns = [s.columns[1], s.columns[0]];
                if (this.targetCi === 0 || this.targetCi === 1) this.targetCi = 1 - this.targetCi;
                if (this.selectedCi === 0 || this.selectedCi === 1) this.selectedCi = 1 - this.selectedCi;
                this.toast(this.homeDynamicText.swapColumns);
                this.highlightCanvasSelection();
            },

            applyLayoutToSelected(spans) { return this.runCommand("apply-layout", function () { return this._applyLayoutToSelectedRaw(spans); }); },
            _applyLayoutToSelectedRaw(spans) {
                var s = this.sel;
                if (!s) return;
                var normalized = this.normalizedLayoutSpans(spans);
                if (!normalized.length) return;
                var oldColumns = Array.isArray(s.columns) ? s.columns : [];
                var cols = [];
                for (var i = 0; i < normalized.length; i++) {
                    var old = oldColumns[i] || null;
                    // 留住原列的背景、显示设备等设置（2.1.0 前改布局会把它们一并清掉）
                    cols.push(Object.assign({}, old || {}, {
                        id: old && old.id ? old.id : this.uid("c"),
                        span: normalized[i],
                        elements: old && Array.isArray(old.elements) ? old.elements : [],
                    }));
                }
                for (var j = normalized.length; j < oldColumns.length; j++) {
                    if (oldColumns[j] && Array.isArray(oldColumns[j].elements)) {
                        cols[cols.length - 1].elements = cols[cols.length - 1].elements.concat(oldColumns[j].elements);
                    }
                }
                s.columns = cols;
                this.targetCi = Math.min(this.targetCi, cols.length - 1);
                this.selectedCi = -1;
                this.selectedEi = -1;
                this.selectedChildEi = -1;
                this.selLayer = "con";
                this.toast(this.uiText.layoutApplied);
                this.highlightCanvasSelection();
            },

            addSection(cols, silent) { return this.runCommand("add-section", function () { return this._addSectionRaw(cols, silent); }); },
            _addSectionRaw(cols, silent) {
                var spans = Array.isArray(cols) ? cols.map(function (n) {
                    n = parseInt(n, 10);
                    return Math.min(12, Math.max(1, isNaN(n) ? 12 : n));
                }) : null;
                var count = spans ? spans.length : Math.max(1, Math.min(6, parseInt(cols, 10) || 1));
                var c = [];
                for (var i = 0; i < count; i++) {
                    var col = { id: this.uid("c"), elements: [] };
                    if (spans) col.span = spans[i];
                    c.push(col);
                }
                var sec = {
                    id: this.uid("s"), type: "section",
                    settings: {
                        title: "", subtitle: "",
                        bg_color: "", bg_image: "", bg_video: "", bg_video_mobile_mode: "poster", bg_gradient: "", bg_opacity: 100, text_tone: "auto",
                        // 新区块默认宽容器（1280px）——用户定的现代默认；旧区块存的值不受影响
                        padding: "md", max_width: "wide", max_width_px: 1280,
                        container_bg: "", container_bg_image: "", container_bg_overlay_color: "", container_bg_overlay_opacity: 0,
                        container_padding: "", container_radius: "", container_gutter: "default",
                        align_items: "stretch", justify_items: "stretch",
                        gap: "lg", col_card: false, tablet_stack: false,
                    },
                    columns: c,
                };
                var at = this.insertIndex();
                this.sections.splice(at, 0, sec);
                this.selectedSi = at;
                this.selectedCi = -1;
                this.selectedEi = -1;
                this.selectedChildEi = -1;
                this.selLayer = "sec";
                                if (!silent) this.toast(spans ? this.uiText.layoutInserted : this.uiText.nColInserted.replace(":n", count));
            },

            /** 下一个区块插入到的下标（选中项之后，否则末尾） */
            insertIndex() {
                if (this._insertAt !== null) {
                    return Math.max(0, Math.min(this._insertAt, this.sections.length));
                }
                return (this.selectedSi >= 0 && this.selectedSi < this.sections.length)
                    ? this.selectedSi + 1
                    : this.sections.length;
            },

            /** 插入位置的人话描述，显示在「添加区块」上方 */
            insertHint() {
                var at = this.insertIndex();
                return at >= this.sections.length ? this.uiText.insertAtEnd : this.uiText.insertAfterSection.replace(":n", at);
            },

            // ── 元素库 ──────────────────────────────────────

            leftPanelMaximum() {
                return Math.min(
                    this.leftPanelMax,
                    Math.max(this.leftPanelMin, window.innerWidth - 984)
                );
            },

            clampLeftPanelWidth(value) {
                var width = Number(value);
                if (!Number.isFinite(width)) width = 288;
                return Math.round(Math.max(this.leftPanelMin, Math.min(this.leftPanelMaximum(), width)));
            },

            leftPanelStyle() {
                this.canvasViewportTick;
                return window.innerWidth >= 1440 ? "width:" + (this.leftPanelCollapsed ? 40 : this.leftPanelWidth) + "px" : "";
            },

            leftPanelContentVisible() {
                this.canvasViewportTick;
                return window.innerWidth < 1440 || !this.leftPanelCollapsed;
            },

            expandLeftPanel() {
                if (!this.leftPanelCollapsed) return;
                this.leftPanelCollapsed = false;
                this.canvasViewportTick++;
                this.persistLeftPanelWidth();
            },

            toggleLeftPanel() {
                this.finishLeftPanelResize();
                this.leftPanelCollapsed = !this.leftPanelCollapsed;
                this.canvasViewportTick++;
                this.persistLeftPanelWidth();
                this.$nextTick(function () {
                    var buttons = document.querySelectorAll('[data-testid="blox-left-panel-toggle"]');
                    var visible = Array.from(buttons).find(function (button) { return button.getClientRects().length > 0; });
                    if (visible) visible.focus({ preventScroll: true });
                });
            },

            restoreLeftPanelWidth() {
                var stored = this.readWorkspacePref("left-panel-width", this.leftPanelStorageKey);
                if (stored !== null) this.leftPanelWidth = this.clampLeftPanelWidth(stored);
                this.leftPanelCollapsed = this.readWorkspacePref("left-panel-collapsed", this.leftPanelCollapsedStorageKey) === "1";
            },

            persistLeftPanelWidth() {
                this.writeWorkspacePref("left-panel-width", this.leftPanelWidth, this.leftPanelStorageKey);
                this.writeWorkspacePref("left-panel-collapsed", this.leftPanelCollapsed ? "1" : "0", this.leftPanelCollapsedStorageKey);
            },

            setLeftPanelWidth(value, persist) {
                this.leftPanelWidth = this.clampLeftPanelWidth(value);
                this.canvasViewportTick++;
                if (persist !== false) this.persistLeftPanelWidth();
            },

            startLeftPanelResize(event) {
                if (window.innerWidth < 1440 || !event || event.button !== 0) return;
                event.preventDefault();
                this.leftPanelResizing = true;
                this._leftPanelPointerId = event.pointerId;
                this._leftPanelResizeStartX = event.clientX;
                this._leftPanelResizeStartWidth = this.leftPanelWidth;
                document.body.classList.add("blox-panel-resizing");
                if (event.currentTarget && typeof event.currentTarget.setPointerCapture === "function") {
                    event.currentTarget.setPointerCapture(event.pointerId);
                }
            },

            resizeLeftPanel(event) {
                if (!this.leftPanelResizing || !event) return;
                if (this._leftPanelPointerId !== null && event.pointerId !== this._leftPanelPointerId) return;
                this.setLeftPanelWidth(
                    this._leftPanelResizeStartWidth + event.clientX - this._leftPanelResizeStartX,
                    false
                );
            },

            finishLeftPanelResize(event) {
                if (!this.leftPanelResizing) return;
                if (event && this._leftPanelPointerId !== null && event.pointerId !== this._leftPanelPointerId) return;
                this.leftPanelResizing = false;
                this._leftPanelPointerId = null;
                document.body.classList.remove("blox-panel-resizing");
                this.persistLeftPanelWidth();
            },

            resizeLeftPanelBy(delta) {
                this.setLeftPanelWidth(this.leftPanelWidth + Number(delta || 0));
            },

            resetLeftPanelWidth() {
                this.setLeftPanelWidth(288);
            },

            rightPanelMaximum() {
                return Math.min(
                    this.rightPanelMax,
                    Math.max(this.rightPanelMin, window.innerWidth - this.leftPanelWidth - 768)
                );
            },

            clampRightPanelWidth(value) {
                var width = Number(value);
                if (!Number.isFinite(width)) width = 256;
                return Math.round(Math.max(this.rightPanelMin, Math.min(this.rightPanelMaximum(), width)));
            },

            rightPanelStyle() {
                this.canvasViewportTick;
                if (window.innerWidth < 1440) return "";
                return this.rightPanelCollapsed ? "display:none" : "width:" + this.rightPanelWidth + "px";
            },

            rightPanelContentVisible() {
                this.canvasViewportTick;
                return window.innerWidth < 1440 || !this.rightPanelCollapsed;
            },

            restoreRightPanelState() {
                var storedWidth = this.readWorkspacePref("right-panel-width", this.rightPanelStorageKey);
                if (storedWidth !== null) this.rightPanelWidth = this.clampRightPanelWidth(storedWidth);
                this.rightPanelCollapsed = this.readWorkspacePref("right-panel-collapsed", this.rightPanelCollapsedStorageKey) === "1";
            },

            persistRightPanelState() {
                this.writeWorkspacePref("right-panel-width", this.rightPanelWidth, this.rightPanelStorageKey);
                this.writeWorkspacePref("right-panel-collapsed", this.rightPanelCollapsed ? "1" : "0", this.rightPanelCollapsedStorageKey);
            },

            setRightPanelWidth(value, persist) {
                this.rightPanelWidth = this.clampRightPanelWidth(value);
                this.canvasViewportTick++;
                if (persist !== false) this.persistRightPanelState();
            },

            startRightPanelResize(event) {
                if (window.innerWidth < 1440 || this.rightPanelCollapsed || !event || event.button !== 0) return;
                event.preventDefault();
                this.rightPanelResizing = true;
                this._rightPanelPointerId = event.pointerId;
                this._rightPanelResizeStartX = event.clientX;
                this._rightPanelResizeStartWidth = this.rightPanelWidth;
                document.body.classList.add("blox-panel-resizing");
                if (event.currentTarget && typeof event.currentTarget.setPointerCapture === "function") {
                    event.currentTarget.setPointerCapture(event.pointerId);
                }
            },

            resizeRightPanel(event) {
                if (!this.rightPanelResizing || !event) return;
                if (this._rightPanelPointerId !== null && event.pointerId !== this._rightPanelPointerId) return;
                this.setRightPanelWidth(
                    this._rightPanelResizeStartWidth - event.clientX + this._rightPanelResizeStartX,
                    false
                );
            },

            finishRightPanelResize(event) {
                if (!this.rightPanelResizing) return;
                if (event && this._rightPanelPointerId !== null && event.pointerId !== this._rightPanelPointerId) return;
                this.rightPanelResizing = false;
                this._rightPanelPointerId = null;
                document.body.classList.remove("blox-panel-resizing");
                this.persistRightPanelState();
            },

            resizeRightPanelBy(delta) {
                this.setRightPanelWidth(this.rightPanelWidth + Number(delta || 0));
            },

            resetRightPanelWidth() {
                this.setRightPanelWidth(256);
            },

            /** 结构面板此刻是否展开（窄屏看抽屉，宽屏看右栏折叠态）——按钮文案/aria 与开关动作都用它。 */
            structurePanelExpanded() {
                // 触碰 canvasViewportTick：window.innerWidth 不是响应式依赖，跨断点缩放后
                // 不这样登记一下，按钮的 title/aria-expanded 会停留在旧值（沿用 rightPanelContentVisible() 的既有做法）
                this.canvasViewportTick;
                return this.isNarrowWorkspace() ? this.mobilePanel === 'structure' : !this.rightPanelCollapsed;
            },

            /** <1440：结构面板是抽屉（显隐由 mobilePanel 决定，rightPanelCollapsed 无意义）。 */
            isNarrowWorkspace() {
                return window.innerWidth < 1440;
            },

            toggleRightPanel() {
                this.finishRightPanelResize();
                // TASK-003 C：窄屏下原来只翻转 rightPanelCollapsed，而抽屉显隐看 mobilePanel，
                // 于是"收起"是个空操作（面板宽高为 0 也不变）。窄屏改操作抽屉，宽屏维持折叠态。
                if (this.isNarrowWorkspace()) {
                    this.mobilePanel = this.mobilePanel === 'structure' ? '' : 'structure';
                    this.canvasViewportTick++;
                    return;
                }
                this.rightPanelCollapsed = !this.rightPanelCollapsed;
                this.canvasViewportTick++;
                this.persistRightPanelState();
            },

            openElementLibrary() {
                this.expandLeftPanel();
                this.libOpen = true;
                if (window.innerWidth < 1440) this.mobilePanel = "library";
                var self = this;
                this.$nextTick(function () {
                    if (self.$refs.libSearch) self.$refs.libSearch.focus();
                });
            },

            <?php require __DIR__ . '/element-library-methods.php'; ?>

            canvasPaletteDragMessage(event, phase) {
                var frame = this.$refs.canvas;
                var paletteType = this.dragEl ? this.dragEl.type : "";
                var templateKey = this.templateDragItem ? this.templateDragItem.key : "";
                var source = templateKey ? "template" : "palette";
                var type = templateKey ? "__section_template" : paletteType;
                if (!frame || !type || !event) return false;
                var rect = frame.getBoundingClientRect();
                if (!rect.width || !rect.height) return false;
                var frameWindow = frame.contentWindow;
                var frameWidth = frameWindow && frameWindow.innerWidth ? frameWindow.innerWidth : (frame.clientWidth || rect.width);
                var frameHeight = frameWindow && frameWindow.innerHeight ? frameWindow.innerHeight : (frame.clientHeight || rect.height);
                var clientX = (event.clientX - rect.left) * (frameWidth / rect.width);
                var clientY = (event.clientY - rect.top) * (frameHeight / rect.height);
                return this.canvasBridge().post({ ykPaletteDrag: {
                    version: 1,
                    phase: phase,
                    source: source,
                    type: type,
                    templateKey: templateKey,
                    clientX: Math.max(0, Math.min(frameWidth, clientX)),
                    clientY: Math.max(0, Math.min(frameHeight, clientY)),
                } });
            },

            canvasPaletteDragOver(event) {
                if (!this.canvasDragActive) return;
                if (event.dataTransfer) event.dataTransfer.dropEffect = "copy";
                this.canvasPaletteDragMessage(event, "move");
            },

            canvasPaletteDragLeave(event) {
                if (event.currentTarget.contains(event.relatedTarget)) return;
                this.canvasBridge().post({ ykPaletteDrag: { version: 1, phase: "cancel" } });
            },

            canvasPaletteDrop(event) {
                if (!this.canvasDragActive) return;
                this.canvasPaletteDragMessage(event, "drop");
            },

            /**
             * 结构树拖放与画布同语义：元素上下半区表示前后，容器中部表示放入。
             * 这里只生成标准目标，写入仍由 addElement/insertElementAt 集中处理。
             */
            treeDropMatches(key) {
                return !!this.treeDropIntent && this.treeDropIntent.key === key;
            },

            treeDropVerdict(target) {
                if (!target) return { valid: false, reason: "invalid" };
                if (this.templateDragItem) {
                    return target.kind === "template-section" ? { valid: true } : { valid: false, reason: "invalid" };
                }
                var el = this.dragEl;
                if (!el) return { valid: false, reason: "invalid" };
                if (el.type === "__section") {
                    return target.kind === "section" ? { valid: true } : { valid: false, reason: "invalid" };
                }
                if (target.kind === "section") return { valid: false, reason: "invalid" };
                var host = null;
                if (target.kind === "container") {
                    host = this.elementAtPath(target.path);
                } else if (target.kind === "element") {
                    var parts = String(target.path || "").split(".");
                    if (parts.length === 4) host = this.elementAtPath(parts.slice(0, 3).join("."));
                }
                if (!host) return { valid: true };
                if (this.canNestElement(host, { type: el.type })) return { valid: true };
                return {
                    valid: false,
                    reason: this.elSchema(el.type).container ? "no-nested-container" : "restricted-children",
                };
            },

            setTreeDropIntent(key, intent, target) {
                var verdict = this.treeDropVerdict(target);
                var label = this.uiText.dropInvalid;
                if (!verdict.valid) {
                    label = verdict.reason === "restricted-children" ? this.uiText.dropRestricted
                        : (verdict.reason === "no-nested-container" ? this.uiText.noNestedContainer : this.uiText.dropInvalid);
                } else if (intent === "before") label = this.uiText.dropBefore;
                else if (intent === "after") label = this.uiText.dropAfter;
                else if (intent === "inside") label = this.uiText.dropIntoContainer;
                else if (intent === "column-end") label = this.uiText.dropIntoColumnEnd;
                else if (intent === "section-after") label = target.kind === "template-section"
                    ? this.uiText.dropSectionAfter
                    : this.uiText.insertAfterSection.replace(":n", parseInt(target.sec, 10) + 1);
                else if (intent === "section-before") label = this.uiText.dropSectionBefore;
                if (this.treeDropIntent && this.treeDropIntent.key === key
                    && this.treeDropIntent.valid === verdict.valid && this.treeDropIntent.label === label) return;
                this.treeDropIntent = { key: key, intent: intent, target: target, valid: verdict.valid, label: label };
            },

            applyTreeDropEffect(event) {
                if (event.dataTransfer) event.dataTransfer.dropEffect = this.treeDropIntent && this.treeDropIntent.valid ? "copy" : "none";
            },

            treeSectionDragOver(event) {
                if (!this.templateDragItem) return;
                var row = event.target && event.target.closest
                    ? event.target.closest('[data-testid="blox-tree-section"]')
                    : null;
                if (!row || !this.$refs.tree || !this.$refs.tree.contains(row)) return;
                var si = parseInt(row.getAttribute("data-section-index"), 10);
                if (isNaN(si)) return;
                event.preventDefault();
                event.stopPropagation();
                var rect = row.getBoundingClientRect();
                var after = event.clientY >= rect.top + rect.height / 2;
                var position = after ? "after" : "before";
                this.setTreeDropIntent(
                    "template-section:" + si + ":" + position,
                    after ? "section-after" : "section-before",
                    { kind: "template-section", index: si + (after ? 1 : 0) }
                );
                this.applyTreeDropEffect(event);
            },

            treeSectionDrop(event) {
                if (!this.templateDragItem) return;
                var row = event.target && event.target.closest
                    ? event.target.closest('[data-testid="blox-tree-section"]')
                    : null;
                if (!row || !this.$refs.tree || !this.$refs.tree.contains(row)) return;
                this.treeDrop(event);
            },

            treeColumnDragOver(event, si, ci, key) {
                if (!this.dragEl) return;
                event.preventDefault();
                event.stopPropagation();
                if (this.dragEl.type === "__section") {
                    this.setTreeDropIntent(key + ":section-after", "section-after", { kind: "section", sec: si });
                    this.applyTreeDropEffect(event);
                    return;
                }
                this.setTreeDropIntent(key + ":column-end", "column-end", { kind: "column", sec: si, col: ci, position: "end" });
                this.applyTreeDropEffect(event);
            },

            treeElementDragOver(event, si, ci, ei, el) {
                if (!this.dragEl) return;
                event.preventDefault();
                event.stopPropagation();
                var path = [si, ci, ei].join(".");
                if (this.dragEl.type === "__section") {
                    this.setTreeDropIntent("section:" + si + ":section-after", "section-after", { kind: "section", sec: si });
                    this.applyTreeDropEffect(event);
                    return;
                }
                var rect = event.currentTarget.getBoundingClientRect();
                var ratio = rect.height > 0 ? (event.clientY - rect.top) / rect.height : 0;
                var isContainer = this.elSchema(el.type).container;
                if (isContainer && ratio >= .25 && ratio <= .75) {
                    this.setTreeDropIntent("element:" + path + ":inside", "inside", { kind: "container", path: path });
                    this.applyTreeDropEffect(event);
                    return;
                }
                var position = ratio < .5 ? "before" : "after";
                this.setTreeDropIntent("element:" + path + ":" + position, position, { kind: "element", path: path, position: position });
                this.applyTreeDropEffect(event);
            },

            treeChildDragOver(event, si, ci, ei, cei) {
                if (!this.dragEl) return;
                event.preventDefault();
                event.stopPropagation();
                if (this.dragEl.type === "__section") {
                    this.setTreeDropIntent("section:" + si + ":section-after", "section-after", { kind: "section", sec: si });
                    this.applyTreeDropEffect(event);
                    return;
                }
                var rect = event.currentTarget.getBoundingClientRect();
                var position = event.clientY < rect.top + rect.height / 2 ? "before" : "after";
                var path = [si, ci, ei, cei].join(".");
                this.setTreeDropIntent("child:" + path + ":" + position, position, { kind: "element", path: path, position: position });
                this.applyTreeDropEffect(event);
            },

            treeDragLeave(event) {
                if (event.currentTarget.contains(event.relatedTarget)) return;
                this.treeDropIntent = null;
            },

            treeDrop(event) {
                if (!this.dragEl && !this.templateDragItem) return;
                event.preventDefault();
                event.stopPropagation();
                var el = this.dragEl;
                var template = this.templateDragItem;
                var drop = this.treeDropIntent;
                this.finishPaletteDrag();
                if (!drop) return;
                if (!drop.valid) {
                    this.toast(drop.label);
                    return;
                }
                if (template) {
                    if (drop.target.kind === "template-section") {
                        this.insertTemplateAt(template, parseInt(drop.target.index, 10));
                    }
                    return;
                }
                if (drop.target.kind === "section") {
                    this.selectSection(parseInt(drop.target.sec, 10), false);
                    this.addSection(1);
                    return;
                }
                var targetSi = drop.target.kind === "column"
                    ? parseInt(drop.target.sec, 10)
                    : parseInt(String(drop.target.path || "").split(".")[0], 10);
                if (!isNaN(targetSi)) this.selectSection(targetSi, false);
                this.addElement(el, drop.target);
            },

            /** 收藏/最近使用作为快捷副本置顶；搜索时只显示普通分类结果。 */
            filteredLib() {
                var q = this.libQuery.trim().toLowerCase();
                var category = this.libCategory || "all";
                var self = this;
                var groups = [];
                var host = this.selTopEl && this.elSchema(this.selTopEl.type).container ? this.selTopEl : null;
                var items = this.elementLib.filter(function (el) {
                    if (el.type === "__section") {
                        if (host) return false;
                    } else if (host) {
                        if (!self.canNestElement(host, { type: el.type })) return false;
                    } else if (el.paletteVisible !== true || el.deprecated) {
                        return false;
                    }
                    if (category !== "all" && el.category !== category) return false;
                    return !q || el.label.toLowerCase().indexOf(q) !== -1 || el.type.indexOf(q) !== -1;
                });
                var favorites = {};
                if (!q && category === "all") {
                    var byType = {};
                    items.forEach(function (el) { byType[el.type] = el; });
                    var favoriteItems = this.favoriteElementTypes.map(function (type) { return byType[type]; }).filter(Boolean);
                    if (favoriteItems.length) {
                        favoriteItems.forEach(function (el) { favorites[el.type] = true; });
                        groups.push({ cat: "__favorites", label: this.elementLibraryText.favorites, icon: "star", quick: true, items: favoriteItems });
                    }
                    var recentItems = this.recentElementTypes.map(function (type) { return byType[type]; }).filter(function (el) {
                        return !!el && !favorites[el.type];
                    });
                    if (recentItems.length) {
                        groups.push({ cat: "__recent", label: this.elementLibraryText.recent, icon: "history", quick: true, items: recentItems });
                    }
                }
                items.forEach(function (el) {
                    var g = groups.find(function (x) { return x.cat === el.category; });
                    if (!g) {
                        g = { cat: el.category, label: self.catLabels[el.category] || el.category, items: [] };
                        groups.push(g);
                    }
                    g.items.push(el);
                });
                var quickCount = groups.filter(function (g) { return g.cat.indexOf("__") === 0; }).length;
                var li = groups.findIndex(function (g) { return g.cat === "layout"; });
                if (li > quickCount) groups.splice(quickCount, 0, groups.splice(li, 1)[0]);
                groups.forEach(function (g) {
                    if (g.cat !== "layout") return;
                    var w = { "__section": 0, "container": 1, "div": 2 };
                    g.items.sort(function (a, b) { return (w[a.type] ?? 5) - (w[b.type] ?? 5); });
                });
                return groups;
            },

            /**
             * 插入元素到选中区块的目标列。
             * data 用注册表给的 defaults 深拷贝——直接引用会让多次插入共享同一个对象，
             * 改一个全变。
             */
            _addElementRaw(el, target) {
                // 合成项「区块」：插顶层 section（1 列起步；多列预设在右下角）
                if (el.type === "__section") { this.addSection(1); return; }
                var s = this.sel;
                if (!s) {
                    this.addSection(1, true);
                    s = this.sel;
                    if (!s) { this.toast(this.uiText.createSectionFailed); return; }
                }
                var node = this.newElementNode(el);
                if (target && this.insertElementAt(node, target, el.label)) return;
                this.appendElementNode(node, el.label);
            },

            newElementNode(el) {
                var node = {
                    id: this.uid("e"),
                    // 预设瓦片（如 __query_cards）用合成 type 保持库内身份，真实节点类型走 insertType
                    type: el.insertType || el.type,
                    data: JSON.parse(JSON.stringify(el.defaults || {})),
                };
                var self = this;
                var assignChildIds = function (item) {
                    if (!item || typeof item !== "object") return;
                    if (!item.id) item.id = self.uid("e");
                    ((item.data && item.data.children) || []).forEach(assignChildIds);
                };
                ((node.data && node.data.children) || []).forEach(assignChildIds);
                return node;
            },

            appendElementNode(node, label) {
                var s = this.sel;
                if (!s) return;
                // 0b：插进最近的容器语境——选中容器（任意深度）=进该容器；
                // 选中容器内叶子=进其直接父容器；其余落回列尾。
                var host = null;
                var hostSubPath = [];
                var deepSel = this.selEl;
                if (deepSel && this.elSchema(deepSel.type).container) {
                    host = deepSel;
                    hostSubPath = this.selectedSubPath.slice();
                } else if (this.selectedSubPath.length && this.selTopEl) {
                    host = this.subPathParent(this.selectedSi, this.selectedCi, this.selectedEi, this.selectedSubPath);
                    hostSubPath = this.selectedSubPath.slice(0, -1);
                }
                if (host && this.elSchema(host.type).container) {
                    if (!this.canNestElement(host, node)) {
                        this.toast(this.isLoopTemplateHost(host) ? runtimeText.loopChildInvalid : this.uiText.noNestedContainer);
                        return;
                    }
                    host.data.children = host.data.children || [];
                    if (this.isHomeBannerHost(host)) host.data.items_mode = "custom";
                    host.data.children.push(node);
                    this.selectDescendant(this.selectedSi, this.selectedCi, this.selectedEi,
                        hostSubPath.concat(host.data.children.length - 1), false);
                    this.toast(this.uiText.insertedContainer.replace(":label", label || this.uiText.elementWord));
                    return;
                }
                var ci = this.colIndex();
                s.columns[ci].elements.push(node);
                // 插入即选中：左栏自动切到它的设置（selectElement 会收起元素库），接着就能改文字
                this.selectElement(this.selectedSi, ci, s.columns[ci].elements.length - 1, false);
                this.toast((s.columns.length > 1 ? this.uiText.insertedCol.replace(":n", ci + 1) : this.uiText.inserted).replace(":label", label || this.uiText.elementWord));
            },

            /**
             * 统一处理画布和结构树使用的插入目标：
             * column/end 追加到列尾；container 放入容器；element/before|after 插入到节点同级。
             * 子元素路径按同一套 si.ci.ei.cei 规则处理，容器约束在这里集中校验。
             */
            insertElementAt(node, target, label) {
                if (!target || !target.kind) return false;
                var si = parseInt(target.sec, 10);
                var ci = parseInt(target.col, 10);
                if (target.kind === "column") {
                    var section = this.sections[si];
                    var column = section && section.columns ? section.columns[ci] : null;
                    if (!column) return false;
                    column.elements = column.elements || [];
                    var endIndex = column.elements.length;
                    column.elements.splice(endIndex, 0, node);
                    this.selectElement(si, ci, endIndex, false);
                    this.toast((section.columns.length > 1 ? this.uiText.insertedCol.replace(":n", ci + 1) : this.uiText.inserted).replace(":label", label || this.uiText.elementWord));
                    return true;
                }
                if (target.kind === "container") {
                    // 0b：容器落点接受任意深度路径（最深 9 段——第 8 层容器不能再收子级）
                    var hostParts = String(target.path || "").split(".").map(function (value) { return parseInt(value, 10); });
                    if (hostParts.length < 3 || hostParts.length > 9 || hostParts.some(function (value) { return isNaN(value); })) return false;
                    var host = this.elementAtPath(hostParts.join("."));
                    if (!host || !this.elSchema(host.type).container) return false;
                    if (!this.canNestElement(host, node)) {
                        this.toast(this.isLoopTemplateHost(host) ? runtimeText.loopChildInvalid : this.uiText.noNestedContainer);
                        return true;
                    }
                    host.data.children = host.data.children || [];
                    if (this.isHomeBannerHost(host)) host.data.items_mode = "custom";
                    host.data.children.push(node);
                    this.selectDescendant(hostParts[0], hostParts[1], hostParts[2],
                        hostParts.slice(3).concat(host.data.children.length - 1), false);
                    this.toast(this.uiText.insertedContainer.replace(":label", label || this.uiText.elementWord));
                    return true;
                }
                if (target.kind !== "element") return false;
                var parts = String(target.path || "").split(".").map(function (value) { return parseInt(value, 10); });
                if (parts.length < 3 || parts.length > 10 || parts.some(function (value) { return isNaN(value); })) return false;
                si = parts[0];
                ci = parts[1];
                var targetSection = this.sections[si];
                var targetColumn = targetSection && targetSection.columns ? targetSection.columns[ci] : null;
                if (!targetColumn) return false;
                var position = target.position === "before" ? "before" : "after";
                if (parts.length >= 4) {
                    // 0b：目标是容器内（任意深度）的子级——插到它的同层前/后
                    var subPath = parts.slice(3);
                    var parent = this.subPathParent(si, ci, parts[2], subPath);
                    var children = parent && parent.data ? (parent.data.children || []) : [];
                    var slot = subPath[subPath.length - 1];
                    if (!parent || !this.elSchema(parent.type).container || !children[slot]) return false;
                    if (!this.canNestElement(parent, node)) {
                        this.toast(this.isLoopTemplateHost(parent) ? runtimeText.loopChildInvalid : this.uiText.noNestedContainer);
                        return true;
                    }
                    var childIndex = slot + (position === "before" ? 0 : 1);
                    children.splice(Math.min(children.length, childIndex), 0, node);
                    this.selectDescendant(si, ci, parts[2],
                        subPath.slice(0, -1).concat(Math.min(children.length - 1, childIndex)), false);
                    this.toast((position === "before" ? this.uiText.insertedBefore : this.uiText.insertedAfter).replace(":label", label || this.uiText.elementWord));
                    return true;
                }
                var elements = targetColumn.elements || [];
                if (!elements[parts[2]]) return false;
                var index = parts[2] + (position === "before" ? 0 : 1);
                elements.splice(Math.min(elements.length, index), 0, node);
                this.selectElement(si, ci, Math.min(elements.length - 1, index), false);
                this.toast((position === "before" ? this.uiText.insertedBefore : this.uiText.insertedAfter).replace(":label", label || this.uiText.elementWord));
                return true;
            },

            openRevisions() {
                this.revisionOpen = true;
                this.focusDialog(this.$refs.revisionDialog, "[data-dialog-initial]");
                this.loadRevisions();
            },

            closeRevisions() {
                if (!this.revisionOpen) return;
                var root = this.$refs.revisionDialog;
                this.revisionOpen = false;
                this.releaseDialog(root);
            },
