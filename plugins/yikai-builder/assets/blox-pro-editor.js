/* BLOX Pro 作者端模块：由编辑器以 `...window.BloxProEditor.methods` 混入 Alpine 组件。
 * 只负责作者交互；保存校验与前台条件匹配始终留在核心。 */
(function () {
    "use strict";

    var data = window.BloxProEditorData || {};

    var conditions = {
        conditionChannels: Array.isArray(data.conditionChannels) ? data.conditionChannels : [],
        conditionLanguages: Array.isArray(data.conditionLanguages) ? data.conditionLanguages : [],
        conditionText: data.conditionText && typeof data.conditionText === "object" ? data.conditionText : {},
        elementConditionReport: null,
        elementConditionReportKey: "",
        elementConditionBusy: false,
        elementConditionError: "",
        elementConditionSeq: 0,

        elementConditionRequest() {
            var path = this.selEl ? this.selectedPath() : String(this.selectedSi);
            if (!this.conditionTarget() || !/^\d+(?:\.\d+)*$/.test(path)) return null;
            var selector = '[' + (this.selEl ? 'data-yk-el' : 'data-yk-sec') + '="' + path + '"]';
            var params = this.productTemplateMode ? { preview_product: String(this.productPreviewId) }
                : this.articleTemplateMode ? { preview_article: String(this.articlePreviewId) }
                : this.headerTemplateMode ? { header_state: this.headerPreviewState } : {};
            var document = this.documentData();
            return { selector: selector, params: params, document: document, endpoint: this.previewEndpoint,
                key: JSON.stringify([selector, document, this.previewEndpoint, params]) };
        },

        elementConditionStale() {
            var request = this.elementConditionRequest();
            return !request || request.key !== this.elementConditionReportKey;
        },

        elementConditionResultText(matched) {
            return matched ? this.conditionText.satisfied : this.conditionText.unsatisfied;
        },

        async diagnoseElementConditions() {
            var request = this.elementConditionRequest();
            if (!request || !this.displayConditionsEnabled) return;
            var sequence = ++this.elementConditionSeq;
            this.elementConditionBusy = true;
            this.elementConditionError = "";
            this.elementConditionReport = null;
            var body = new URLSearchParams(Object.assign({}, request.params, {
                action: "preview", blox: "1", condition_diagnostics: "1",
                blocks_data: request.document, _token: this.csrf
            }));
            try {
                // 复用有登录、权限、CSRF 和作者能力门禁的预览，不另建判定器或执行返回脚本。
                var response = await fetch(request.endpoint, { method: "POST", body: body });
                if (!response.ok) throw new Error("preview-failed");
                var html = await response.text();
                if (sequence !== this.elementConditionSeq) return;
                var current = this.elementConditionRequest();
                if (!current || current.key !== request.key) {
                    this.elementConditionError = this.conditionText.stale;
                    return;
                }
                var parsed = new DOMParser().parseFromString(html, "text/html");
                var nodes = parsed.querySelectorAll(request.selector);
                if (nodes.length !== 1 || !nodes[0].hasAttribute("data-yk-condition-report")) {
                    throw new Error("preview-target-unavailable");
                }
                var report = JSON.parse(nodes[0].getAttribute("data-yk-condition-report"));
                if (report === null) {
                    this.elementConditionError = this.conditionText.invalid;
                    return;
                }
                if (typeof report.matched !== "boolean" || !Array.isArray(report.groups)) throw new Error("invalid-report");
                this.elementConditionReport = report;
                this.elementConditionReportKey = request.key;
            } catch (error) {
                if (sequence === this.elementConditionSeq) this.elementConditionError = this.conditionText.diagnoseFailed;
            } finally {
                if (sequence === this.elementConditionSeq) this.elementConditionBusy = false;
            }
        },

        conditionGroups() {
            var target = this.conditionTarget();
            return target && Array.isArray(target._conditions) ? target._conditions : [];
        },

        defaultConditionRule() {
            return { type: "login", operator: "is", value: "logged_in" };
        },

        addConditionGroup() {
            var target = this.conditionTarget();
            if (!target) return;
            if (!Array.isArray(target._conditions)) target._conditions = [];
            if (target._conditions.length >= 10) return;
            target._conditions.push({ rules: [this.defaultConditionRule()] });
        },

        removeConditionGroup(groupIndex) {
            var target = this.conditionTarget();
            if (!target || !Array.isArray(target._conditions)) return;
            target._conditions.splice(groupIndex, 1);
            if (!target._conditions.length) delete target._conditions;
        },

        addConditionRule(groupIndex) {
            var group = this.conditionGroups()[groupIndex];
            if (!group) return;
            if (!Array.isArray(group.rules)) group.rules = [];
            if (group.rules.length < 10) group.rules.push(this.defaultConditionRule());
        },

        removeConditionRule(groupIndex, ruleIndex) {
            var group = this.conditionGroups()[groupIndex];
            if (!group || !Array.isArray(group.rules)) return;
            group.rules.splice(ruleIndex, 1);
            if (!group.rules.length) this.removeConditionGroup(groupIndex);
        },

        conditionTypeChanged(rule) {
            if (!rule) return;
            if (rule.type === "login") {
                rule.operator = "is";
                rule.value = "logged_in";
            } else if (rule.type === "date") {
                rule.operator = "on";
                var today = new Date();
                rule.value = today.getFullYear() + "-"
                    + String(today.getMonth() + 1).padStart(2, "0") + "-"
                    + String(today.getDate()).padStart(2, "0");
            } else if (rule.type === "channel") {
                rule.operator = "is";
                rule.value = this.conditionChannels.length ? this.conditionChannels[0].value : "";
            } else if (rule.type === "language") {
                rule.operator = "is";
                rule.value = this.conditionLanguages.length ? this.conditionLanguages[0].value : "";
            } else if (rule.type === "device") {
                rule.operator = "is";
                rule.value = "mobile";
            } else if (rule.type === "datetime") {
                rule.operator = "after";
                var now = new Date();
                rule.value = now.getFullYear() + "-"
                    + String(now.getMonth() + 1).padStart(2, "0") + "-"
                    + String(now.getDate()).padStart(2, "0") + " "
                    + String(now.getHours()).padStart(2, "0") + ":"
                    + String(now.getMinutes()).padStart(2, "0");
            } else if (rule.type === "param") {
                rule.operator = "equals";
                rule.name = "";
                rule.value = "";
            } else if (rule.type === "field") {
                rule.operator = "equals";
                rule.name = "";
                rule.value = "";
            } else {
                rule.type = "url";
                rule.operator = "contains";
                rule.value = "/";
            }
        },

        conditionOperators(type) {
            if (type === "login") return [{ value: "is", label: this.conditionText.is }];
            if (type === "date") return [
                { value: "before", label: this.conditionText.before },
                { value: "on", label: this.conditionText.on },
                { value: "after", label: this.conditionText.after },
            ];
            if (type === "channel" || type === "language") return [
                { value: "is", label: this.conditionText.is },
                { value: "is_not", label: this.conditionText.isNot },
            ];
            if (type === "device") return [{ value: "is", label: this.conditionText.is }];
            if (type === "datetime") return [
                { value: "before", label: this.conditionText.before },
                { value: "after", label: this.conditionText.after },
            ];
            if (type === "param") return [
                { value: "equals", label: this.conditionText.equals },
                { value: "not_equals", label: this.conditionText.notEquals },
                { value: "contains", label: this.conditionText.contains },
                { value: "exists", label: this.conditionText.exists },
                { value: "not_exists", label: this.conditionText.notExists },
            ];
            if (type === "field") return [
                { value: "equals", label: this.conditionText.equals },
                { value: "not_equals", label: this.conditionText.notEquals },
                { value: "contains", label: this.conditionText.contains },
                { value: "empty", label: this.conditionText.opEmpty },
                { value: "not_empty", label: this.conditionText.opNotEmpty },
            ];
            return [
                { value: "equals", label: this.conditionText.equals },
                { value: "not_equals", label: this.conditionText.notEquals },
                { value: "contains", label: this.conditionText.contains },
                { value: "not_contains", label: this.conditionText.notContains },
                { value: "starts_with", label: this.conditionText.startsWith },
            ];
        },
    };

    // 全局命名样式：设计系统数据（designSystem、activeGlobalStyles）仍由核心提供。
    var stylePresets = {
        globalStyleOptions(currentId) {
            var items = this.activeGlobalStyles();
            currentId = String(currentId || "");
            if (!currentId || items.some(function (style) { return style.id === currentId; })) return items;
            var archived = (this.designSystem.styles || []).find(function (style) { return style.id === currentId; });
            return archived ? items.concat([archived]) : items;
        },

        globalStyleLabel(style) {
            return style.status === "archived"
                ? style.name + " · " + this.designText.archived
                : style.name;
        },

        applyGlobalStyle(id) {
            if (!this.selEl) return;
            id = String(id || "");
            if (!id) {
                this.selEl.data._global_style = "";
                this.selEl.data._global_style_snapshot = {};
                return;
            }
            var style = (this.designSystem.styles || []).find(function (item) { return item.id === id; });
            if (!style) return;
            this.selEl.data._global_style = id;
            this.selEl.data._global_style_snapshot = {
                color: style.color || "",
                background: style.background || "",
                border_color: style.border_color || "",
                radius: style.radius || "none"
            };
        },
    };

    // 全局样式类：目录数据（globalClasses / designSystem.classes）由核心提供；这里只做作者端交互。
    // 挂类/摘类改的是 selEl.data._classes（随文档保存、可撤销）；创建与修改类走 blox_class_api
    // （CSRF + blox_global 权限 + 作者端授权 + revision 乐观并发），是全站生效的独立保存，不进页面撤销历史。
    // 未保存的类修改按 class_id 暂存在 classDrafts：切换元素不丢，画布用服务端编译的整张样式表预览。
    var classFields = Array.isArray(data.classFields) ? data.classFields : [];
    var classApi = function (params) {
        return fetch((window.YK_BASE || "") + "/admin/blox_class_api.php", { method: "POST", body: new URLSearchParams(params) })
            .then(function (response) {
                return response.json().then(function (result) {
                    if (!result || Number(result.code) !== 0 || !result.data) {
                        var error = new Error((result && result.msg) || "error");
                        error.conflict = response.status === 409 || Number(result && result.code) === 409;
                        throw error;
                    }
                    return result.data;
                });
            });
    };
    var classTiers = ["d", "t", "m", "w"];
    var globalClasses = {
        classFields: classFields,
        classText: data.classText && typeof data.classText === "object" ? data.classText : {},
        classAddOpen: false,
        classQuery: "",
        classDrafts: {},
        classSaving: false,
        // 交互状态：'' = 基础；hover / focus 时表单读写 settings.states[state]，画布强制显示该状态
        classStates: Array.isArray(data.classStates) ? data.classStates : [],
        classStateKeys: Array.isArray(data.classStateKeys) ? data.classStateKeys : [],
        classState: "",
        _classForcedKey: "",
        classUsage: null,
        _classPreviewTimer: 0,
        _classCanvasCss: null,

        elementClassIds() {
            return this.selEl && Array.isArray(this.selEl.data._classes) ? this.selEl.data._classes : [];
        },

        globalClassRow(classId) {
            return (this.globalClasses || []).find(function (item) { return item.class_id === classId; }) || null;
        },

        globalClassLabel(classId) {
            var found = this.globalClassRow(classId);
            return found ? found.name : classId;
        },

        setStyleTarget(classId) {
            this.styleTargetClass = String(classId || "");
            this.classState = "";
            this.classAddOpen = false;
            if (this.styleTargetClass && this.classUsage === null) this.loadClassUsage();
        },

        addElementClass(classId) {
            classId = String(classId || "");
            if (!this.selEl || !classId) return false;
            var list = this.elementClassIds().slice();
            if (list.indexOf(classId) !== -1 || list.length >= 8) return false;
            list.push(classId);
            this.selEl.data._classes = list;
            return true;
        },

        removeElementClass(classId) {
            if (!this.selEl) return;
            var list = this.elementClassIds().filter(function (item) { return item !== classId; });
            if (list.length) this.selEl.data._classes = list;
            else delete this.selEl.data._classes;
            if (this.styleTargetClass === classId) this.styleTargetClass = "";
        },

        // ── 查找 / 挂载 / 新建 ──────────────────────────────────────
        classQueryName() {
            return String(this.classQuery || "").trim().toLowerCase().replace(/^\.?yk-c-/, "").replace(/^\./, "");
        },

        classFinderMatches() {
            var assigned = this.elementClassIds();
            var query = this.classQueryName();
            return (this.globalClasses || []).filter(function (item) {
                return assigned.indexOf(item.class_id) === -1 && (!query || item.name.indexOf(query) !== -1);
            }).slice(0, 30);
        },

        classFinderCanCreate() {
            var name = this.classQueryName();
            return !!this.canManageDesign && /^[a-z][a-z0-9-]{1,47}$/.test(name)
                && !(this.globalClasses || []).some(function (item) { return item.name === name; });
        },

        classFinderEnter() {
            var name = this.classQueryName();
            var exact = (this.globalClasses || []).find(function (item) { return item.name === name; });
            if (exact) this.attachClassFromFinder(exact.class_id);
            else if (this.classFinderCanCreate()) this.createGlobalClass(name);
        },

        attachClassFromFinder(classId) {
            if (this.addElementClass(classId)) this.setStyleTarget(classId);
            this.classQuery = "";
        },

        createGlobalClass(rawName) {
            this.classQuery = String(rawName || this.classQuery || "");
            var name = this.classQueryName();
            if (!name || this._creatingClass) return;
            this._creatingClass = true;
            var self = this;
            classApi({ action: "class_add", name: name, _token: this.csrf })
                .then(function (result) {
                    if (!result.class) throw new Error("error");
                    self.storeClassRow(result.class);
                    self.classQuery = "";
                    self.attachClassFromFinder(result.class.class_id);
                })
                .catch(function (error) { self.toast(String(error && error.message || error)); })
                .finally(function () { self._creatingClass = false; });
        },

        /** 服务端返回的类行同时写回两份目录：挂类列表（globalClasses）与来源提示用的 designSystem.classes。 */
        storeClassRow(row) {
            var list = (this.globalClasses || []).filter(function (item) { return item.class_id !== row.class_id; });
            list.push(row);
            list.sort(function (a, b) { return a.name < b.name ? -1 : (a.name > b.name ? 1 : 0); });
            this.globalClasses = list;
            if (this.designSystem) {
                var classes = Object.assign({}, this.designSystem.classes && !Array.isArray(this.designSystem.classes) ? this.designSystem.classes : {});
                classes[row.class_id] = { name: row.name, settings: row.settings || {} };
                this.designSystem.classes = classes;
            }
        },

        loadClassUsage() {
            var self = this;
            this.classUsage = {};
            classApi({ action: "usage", _token: this.csrf })
                .then(function (result) { self.classUsage = result.usage && !Array.isArray(result.usage) ? result.usage : {}; })
                .catch(function () { self.classUsage = null; });
        },

        classUsageText(classId) {
            var usage = this.classUsage && this.classUsage[classId];
            if (!usage || !this.classText.usage) return "";
            return this.classText.usage.replace(":docs", usage.docs).replace(":refs", usage.refs);
        },

        // ── 表单：字段值与分档（与 BloxResponsiveValue 同义：t←d、m←t、w←d） ─────────
        setClassState(state) {
            this.classState = this.classStates.some(function (item) { return item.key === state; }) ? state : "";
        },

        classStateLabel(state) {
            var found = this.classStates.find(function (item) { return item.key === state; });
            return found ? found.label : "";
        },

        /**
         * 状态预览同步（由 Alpine.effect 驱动）：编辑目标或状态变化时重算画布里的强制状态；
         * 选中的元素不再挂着这个类（或离开样式页签）时回到基础，强制规则随之撤掉。
         */
        syncClassStatePreview(target, state) {
            if (!target && state) {
                this.classState = "";
                return;
            }
            var key = target && state ? target + ":" + state : "";
            if (key === this._classForcedKey) return;
            this._classForcedKey = key;
            this.scheduleClassPreview();
        },

        classFieldGroups() {
            var self = this;
            if (this.classState) {
                // 状态页签：只列可以按状态设置的字段（不分档），外加基础里的过渡时长
                var fields = classFields.filter(function (field) {
                    return field.group === "states" || self.classStateKeys.indexOf(field.key) !== -1;
                }).map(function (field) { return Object.assign({}, field, { responsive: false }); });
                return [{ key: "state-" + this.classState, label: this.classStateLabel(this.classState), fields: fields }];
            }
            var groups = [];
            classFields.forEach(function (field) {
                if (field.group === "states") return;
                var group = groups.find(function (item) { return item.key === field.group; });
                if (!group) groups.push(group = { key: field.group, label: field.group_label, fields: [] });
                group.fields.push(field);
            });
            return groups;
        },

        classFieldLabel(key) {
            var field = classFields.find(function (item) { return item.key === key; });
            return field ? field.label : key;
        },

        classSettings(classId) {
            if (Object.prototype.hasOwnProperty.call(this.classDrafts, classId)) return this.classDrafts[classId];
            var row = this.globalClassRow(classId);
            return row && row.settings && !Array.isArray(row.settings) ? row.settings : {};
        },

        classTier() {
            var tier = this.responsiveDeviceKey();
            return classTiers.indexOf(tier) === -1 ? "d" : tier;
        },

        classTierTable(raw) {
            if (raw === undefined || raw === null || raw === "") return {};
            if (typeof raw === "object" && !Array.isArray(raw)) return Object.assign({}, raw);
            return { d: raw };
        },

        /** 字段当前读写的那一层：状态页签下是 settings.states[state]，过渡时长与基础页签是 settings 本身。 */
        classFieldBag(field) {
            var settings = this.classSettings(this.classStyleTarget());
            if (!this.classState || field.group === "states") return settings;
            var states = settings.states && typeof settings.states === "object" ? settings.states : {};
            return states[this.classState] && typeof states[this.classState] === "object" ? states[this.classState] : {};
        },

        classFieldOwn(field) {
            var raw = this.classFieldBag(field)[field.key];
            if (!field.responsive) return raw !== undefined && raw !== null && raw !== "";
            var table = this.classTierTable(raw);
            return table[this.classTier()] !== undefined && table[this.classTier()] !== null && table[this.classTier()] !== "";
        },

        classFieldValue(field) {
            var raw = this.classFieldBag(field)[field.key];
            if (!field.responsive) return raw === undefined || raw === null ? "" : raw;
            var value = this.classTierTable(raw)[this.classTier()];
            return value === undefined || value === null ? "" : value;
        },

        /** 本档未设时显示继承来的值（placeholder），让「缺省 / 继承 / 覆盖」看得见。 */
        classFieldPlaceholder(field) {
            if (this.classState && field.group !== "states") {
                // 状态未设的属性沿用基础值：把基础值显示出来
                var base = this.classSettings(this.classStyleTarget())[field.key];
                if (base === undefined || base === null || base === "" || typeof base === "object") return "";
                return (this.classText.inherits || ":value").replace(":value", base + (field.unit || ""));
            }
            if (!field.responsive) return "";
            var table = this.classTierTable(this.classSettings(this.classStyleTarget())[field.key]);
            var chain = { d: ["d"], t: ["t", "d"], m: ["m", "t", "d"], w: ["w", "d"] }[this.classTier()] || ["d"];
            for (var i = 1; i < chain.length; i++) {
                var value = table[chain[i]];
                if (value !== undefined && value !== null && value !== "") {
                    return (this.classText.inherits || ":value").replace(":value", value + (field.unit || ""));
                }
            }
            return "";
        },

        /** 本层没填时取继承来的颜色（状态页签下是基础色），用于淡显色块与「继承 …」文字。 */
        classColorEffective(field) {
            var own = this.classFieldValue(field);
            if (own !== "") return String(own);
            if (this.classState && field.group !== "states") {
                var base = this.classSettings(this.classStyleTarget())[field.key];
                return typeof base === "string" ? base : "";
            }
            return "";
        },

        /** 色块：设计变量（var(--yk-color-*)）按当前站点颜色取值，改了站点颜色这里跟着变。 */
        classColorPreview(field) {
            return this.colorFieldPreview(this.classColorEffective(field), "#ffffff");
        },

        classColorLabel(field) {
            var own = this.classFieldValue(field);
            if (own !== "") return this.colorFieldLabel(own);
            var inherited = this.classColorEffective(field);
            if (inherited) return (this.classText.inherits || ":value").replace(":value", this.colorFieldLabel(inherited));
            return this.classText.colorEmpty || "";
        },

        setClassField(field, raw) {
            var classId = this.classStyleTarget();
            if (!classId || !this.canManageDesign) return;
            var settings = JSON.parse(JSON.stringify(this.classSettings(classId) || {}));
            var value = typeof raw === "string" ? raw.trim() : raw;
            if (value !== "" && ["px", "pct", "number"].indexOf(field.type) !== -1) {
                value = Number(value);
                if (!isFinite(value)) return;
                value = Math.min(field.max, Math.max(field.min, value));
                if (field.type !== "number") value = Math.round(value);
            }
            if (field.type === "enum" && value !== "" && field.key === "font_weight") value = Number(value);
            if (this.classState && field.group !== "states") {
                var state = this.classState;
                var states = settings.states && typeof settings.states === "object" ? settings.states : {};
                var bag = Object.assign({}, states[state] && typeof states[state] === "object" ? states[state] : {});
                if (value === "") delete bag[field.key];
                else bag[field.key] = value;
                if (Object.keys(bag).length) states[state] = bag;
                else delete states[state];
                if (Object.keys(states).length) settings.states = states;
                else delete settings.states;
            } else if (field.responsive) {
                var table = this.classTierTable(settings[field.key]);
                if (value === "") delete table[this.classTier()];
                else table[this.classTier()] = value;
                var tiers = Object.keys(table);
                if (!tiers.length) delete settings[field.key];
                else settings[field.key] = tiers.length === 1 && tiers[0] === "d" ? table.d : table;
            } else if (value === "") {
                delete settings[field.key];
            } else {
                settings[field.key] = value;
            }
            var drafts = Object.assign({}, this.classDrafts);
            drafts[classId] = settings;
            this.classDrafts = drafts;
            this.watchClassDraftsOnUnload();
            this.scheduleClassPreview();
        },

        classDraftPending(classId) {
            return !!classId && Object.prototype.hasOwnProperty.call(this.classDrafts, classId);
        },

        pendingClassDrafts() {
            var self = this;
            return Object.keys(this.classDrafts).map(function (classId) {
                return { class_id: classId, name: self.globalClassLabel(classId) };
            });
        },

        // ── 画布预览：服务端按草稿编译整张类样式表（与前台同一编译器），替换画布里的 #yk-blox-classes ──
        scheduleClassPreview() {
            var self = this;
            clearTimeout(this._classPreviewTimer);
            this._classPreviewTimer = setTimeout(function () {
                var forced = {};
                var parts = String(self._classForcedKey || "").split(":");
                if (parts.length === 2 && parts[0] && parts[1]) forced[parts[0]] = parts[1];
                classApi({
                    action: "class_preview", drafts: JSON.stringify(self.classDrafts),
                    force_states: JSON.stringify(forced), _token: self.csrf,
                })
                    .then(function (result) { self.applyClassCanvasCss(String(result.stylesheet || "")); })
                    .catch(function (error) { self.toast(String(error && error.message || error)); });
            }, 200);
        },

        applyClassCanvasCss(css) {
            this._classCanvasCss = css;
            var frame = this.$refs && this.$refs.canvas;
            if (!frame) return;
            var self = this;
            if (!frame._ykClassCssHook) {
                // 画布整页重载后（改文档触发的刷新）重新套用草稿样式，否则预览会退回已保存版本
                frame._ykClassCssHook = true;
                frame.addEventListener("load", function () {
                    if (self._classCanvasCss !== null && (Object.keys(self.classDrafts).length || self._classForcedKey)) {
                        self.applyClassCanvasCss(self._classCanvasCss);
                    }
                });
            }
            try {
                var doc = frame.contentDocument;
                if (!doc || !doc.head) return;
                var tag = doc.getElementById("yk-blox-classes");
                if (!tag || tag.tagName !== "STYLE") {
                    if (tag) tag.remove();
                    tag = doc.createElement("style");
                    tag.id = "yk-blox-classes";
                    doc.head.appendChild(tag);
                }
                tag.textContent = css;
            } catch (error) {
                // 画布跨域或尚未就绪：预览跳过，不影响保存
            }
        },

        saveClassDraft(classId) {
            var row = this.globalClassRow(classId);
            if (!row || !this.classDraftPending(classId) || this.classSaving) return;
            this.classSaving = true;
            var self = this;
            classApi({
                action: "class_update", id: classId, settings: JSON.stringify(this.classDrafts[classId]),
                revision: String(row.revision || 0), _token: this.csrf,
            })
                .then(function (result) {
                    self.storeClassRow(result.class);
                    self.dropClassDraft(classId);
                    // 其他类还有未保存草稿时，预览要继续带着它们
                    if (Object.keys(self.classDrafts).length || self._classForcedKey) self.scheduleClassPreview();
                    else self.applyClassCanvasCss(String(result.stylesheet || ""));
                    self.toast(self.classText.saved);
                })
                .catch(function (error) {
                    if (!error.conflict) { self.toast(String(error && error.message || error)); return; }
                    // 409：绝不覆盖较新的值——载入最新目录、丢弃本地草稿并提示重新修改
                    return classApi({ action: "list", _token: self.csrf }).then(function (result) {
                        (result.classes || []).forEach(function (item) { self.storeClassRow(item); });
                        self.dropClassDraft(classId);
                        self.scheduleClassPreview();
                        self.toast(self.classText.conflictReloaded, 6000);
                    });
                })
                .finally(function () { self.classSaving = false; });
        },

        discardClassDraft(classId) {
            this.dropClassDraft(classId);
            this.scheduleClassPreview();
        },

        dropClassDraft(classId) {
            var drafts = Object.assign({}, this.classDrafts);
            delete drafts[classId];
            this.classDrafts = drafts;
        },

        watchClassDraftsOnUnload() {
            if (this._classUnloadHook) return;
            this._classUnloadHook = true;
            var self = this;
            window.addEventListener("beforeunload", function (event) {
                if (!Object.keys(self.classDrafts).length) return;
                event.preventDefault();
                event.returnValue = self.classText.pending || "";
            });
        },

        // ── 冲突提示：类属性在当前元素上被谁挡住（规则见 BloxStyleSources.classConflicts） ──
        classConflictList() {
            var classId = this.classStyleTarget();
            if (!classId || !window.BloxStyleSources || !window.BloxStyleSources.classConflicts || !this.selEl) return [];
            var classes = Object.assign({}, this.designSystem && this.designSystem.classes && !Array.isArray(this.designSystem.classes) ? this.designSystem.classes : {});
            classes[classId] = { name: this.globalClassLabel(classId), settings: this.classSettings(classId) };
            var state = this.classState;
            return window.BloxStyleSources.classConflicts(this.selEl, classId, {
                styles: this.designSystem && Array.isArray(this.designSystem.styles) ? this.designSystem.styles : [],
                classes: classes,
            }).filter(function (conflict) { return (conflict.state || "") === state; });
        },

        classConflictText(conflict) {
            if (conflict.by === "preset") return String(this.classText.blockedPreset || "").replace(":name", conflict.name);
            if (conflict.by === "class") return String(this.classText.blockedClass || "").replace(":name", conflict.name);
            return this.classText.blockedElement || "";
        },

        /** 清除本地覆盖是对页面文档的修改（进撤销历史、随页面保存），与类本身无关。 */
        clearClassLocalOverride(conflict) {
            if (!this.selEl || !conflict || !Array.isArray(conflict.localKeys)) return;
            var data = this.selEl.data;
            conflict.localKeys.forEach(function (key) { delete data[key]; });
        },
    };

    // 容器 Loop（v1.25）：container/div 的 _query 配置。结构归一以服务端 BloxLoopQuery 为准，
    // 这里只做输入钳位与键的增删（空值/默认值不落盘，保持文档干净）。
    var loopQuery = {
        loopText: data.loopText && typeof data.loopText === "object" ? data.loopText : {},

        loopQueryEnabled() {
            return !!(this.selEl && this.selEl.data && this.selEl.data._query
                && typeof this.selEl.data._query === "object");
        },

        loopQueryField(key) {
            var query = this.loopQueryEnabled() ? this.selEl.data._query : {};
            if (key === "limit") return query.limit ?? 6;
            if (key === "offset") return query.offset ?? 0;
            if (key === "source") return query.source ?? "type:article";
            if (key === "pagination") return query.pagination ?? "none";
            if (key === "empty_mode") return query.empty_mode ?? "message";
            return query[key] ?? "";
        },

        toggleLoopQuery(enabled) {
            if (!this.selEl) return;
            if (enabled) {
                if (!this.loopQueryEnabled()) this.selEl.data._query = { source: "type:article", limit: 6 };
            } else {
                delete this.selEl.data._query;
            }
        },

        // 全局查询引用（v1.25）：_query = {ref: gq_xxx}。切回内联时把查询体物化拷回，保证可继续编辑。
        loopQueryRefId() {
            return this.loopQueryEnabled() && typeof this.selEl.data._query.ref === "string"
                ? this.selEl.data._query.ref : "";
        },

        setLoopQueryRef(queryId) {
            if (!this.selEl || !this.loopQueryEnabled()) return;
            queryId = String(queryId || "");
            if (!queryId) {
                var current = (this.globalQueries || []).find(function (item) { return item.query_id === (this.selEl.data._query.ref || ""); }.bind(this));
                this.selEl.data._query = current && current.query
                    ? Object.assign({}, current.query)
                    : { source: "type:article", limit: 6 };
                return;
            }
            this.selEl.data._query = { ref: queryId };
        },

        saveLoopQueryAsGlobal() {
            if (!this.loopQueryEnabled() || this.loopQueryRefId() || this._savingLoopQuery) return;
            var name = window.prompt(this.loopText && this.loopText.saveAsName || "Name");
            if (!name || !name.trim()) return;
            this._savingLoopQuery = true;
            var body = new URLSearchParams({
                action: "query_add",
                name: name.trim(),
                query: JSON.stringify(this.selEl.data._query),
                _token: this.csrf,
            });
            var self = this;
            fetch((window.YK_BASE || "") + "/admin/blox_query_api.php", { method: "POST", body: body })
                .then(function (response) { return response.json(); })
                .then(function (result) {
                    if (!result || Number(result.code) !== 0 || !result.data || !result.data.query) {
                        throw new Error((result && result.msg) || "error");
                    }
                    self.globalQueries = (self.globalQueries || []).concat([result.data.query]);
                    self.selEl.data._query = { ref: result.data.query.query_id };
                })
                .catch(function (error) { self.toast(String(error && error.message || error)); })
                .finally(function () { self._savingLoopQuery = false; });
        },

        // 自定义字段过滤（v1.25）：AND 平铺 ≤5 条 {field,op,value}。编辑期允许留空行
        // （输入顺序不定），落盘归一交给服务端 normalizeFilters——非法行静默丢弃。
        loopQueryFilters() {
            var query = this.loopQueryEnabled() ? this.selEl.data._query : {};
            return Array.isArray(query.filters) ? query.filters : [];
        },

        addLoopQueryFilter() {
            if (!this.loopQueryEnabled()) return;
            var filters = this.loopQueryFilters().slice();
            if (filters.length >= 5) return;
            filters.push({ field: "", op: "=", value: "" });
            this.selEl.data._query = Object.assign({}, this.selEl.data._query, { filters: filters });
        },

        removeLoopQueryFilter(index) {
            if (!this.loopQueryEnabled()) return;
            var filters = this.loopQueryFilters().slice();
            filters.splice(index, 1);
            var query = Object.assign({}, this.selEl.data._query);
            if (filters.length) query.filters = filters; else delete query.filters;
            this.selEl.data._query = query;
        },

        setLoopQueryFilter(index, key, value) {
            if (!this.loopQueryEnabled()) return;
            var filters = this.loopQueryFilters().slice();
            if (!filters[index]) return;
            var row = Object.assign({}, filters[index]);
            if (key === "field") {
                row.field = String(value || "").trim().toLowerCase().replace(/[^a-z0-9_]/g, "").slice(0, 64);
            } else if (key === "op") {
                var ops = ["=", "!=", ">", ">=", "<", "<=", "like", "in", "between", "empty"];
                row.op = ops.indexOf(String(value)) >= 0 ? String(value) : "=";
                if (row.op === "empty") row.value = "";
            } else {
                row.value = String(value == null ? "" : value).trim().slice(0, 200);
            }
            filters[index] = row;
            this.selEl.data._query = Object.assign({}, this.selEl.data._query, { filters: filters });
        },

        setLoopQueryField(key, value) {
            if (!this.loopQueryEnabled()) return;
            var query = Object.assign({}, this.selEl.data._query);
            if (key === "limit") {
                query.limit = Math.max(1, Math.min(50, parseInt(value, 10) || 6));
            } else if (key === "offset") {
                var offset = Math.max(0, Math.min(5000, parseInt(value, 10) || 0));
                if (offset > 0) query.offset = offset; else delete query.offset;
            } else if (key === "recommend" || key === "hot" || key === "top") {
                if (value) query[key] = true; else delete query[key];
            } else {
                var text = String(value == null ? "" : value).trim();
                var isDefault = text === ""
                    || (key === "pagination" && text === "none")
                    || (key === "empty_mode" && text === "message")
                    || (key === "order" && text === "default");
                if (isDefault) delete query[key]; else query[key] = text;
            }
            this.selEl.data._query = query;
        },
    };

    // 元素交互（v1.28）：repeater 编辑 data._interactions。客户端只做结构与联动，
    // 归一化权威（白名单/上限）在服务端 BloxInteractions。
    var interactions = {
        interactionText: data.interactionText && typeof data.interactionText === "object" ? data.interactionText : { triggers: {}, actions: {}, targets: {} },

        interactionItems() {
            return this.selEl && Array.isArray(this.selEl.data._interactions) ? this.selEl.data._interactions : [];
        },

        addInteraction() {
            if (!this.selEl) return;
            if (!Array.isArray(this.selEl.data._interactions)) this.selEl.data._interactions = [];
            if (this.selEl.data._interactions.length >= 10) return;
            this.selEl.data._interactions.push({ trigger: "click", action: "toggle", target: "self", selector: "", value: "", run_once: false });
        },

        removeInteraction(index) {
            if (!this.selEl || !Array.isArray(this.selEl.data._interactions)) return;
            this.selEl.data._interactions.splice(index, 1);
            if (!this.selEl.data._interactions.length) delete this.selEl.data._interactions;
        },

        interactionChanged(item) {
            if (!item) return;
            if (item.trigger === "scroll" && !item.scroll_depth) item.scroll_depth = 50;
            if (item.action === "animate" && ["fade", "fade-up", "fade-down", "fade-left", "fade-right", "zoom-in"].indexOf(item.value) === -1) {
                item.value = "fade";
            }
            if (["add_class", "remove_class", "toggle_class", "animate"].indexOf(item.action) === -1) item.value = "";
            if (["open_popup", "close_popup"].indexOf(item.action) !== -1) { item.target = "self"; item.selector = ""; }
        },
    };

    // 类状态的画布强制预览跟随「编辑目标 + 状态」变化（含切换元素、离开样式页签）。
    // alpine.min.js 带 defer，一定在本脚本之后初始化。单测在无 DOM 的沙箱里加载本文件，此时跳过。
    if (typeof document !== "undefined" && document.addEventListener) document.addEventListener("alpine:initialized", function () {
        var app = window.Alpine && window.Alpine.$data(document.body);
        if (!app || typeof app.syncClassStatePreview !== "function" || typeof app.classStyleTarget !== "function") return;
        window.Alpine.effect(function () {
            app.syncClassStatePreview(app.classStyleTarget(), app.classState);
        });
    });

    var editor = window.BloxProEditor || { modules: [], methods: {} };
    // query_loop 的作者端由服务端面板与控件开放状态提供，无额外交互方法。
    editor.modules = (editor.modules || []).concat(["query_loop", "display_conditions", "style_presets", "global_classes", "interactions"]);
    editor.methods = Object.assign({}, editor.methods || {}, conditions, stylePresets, globalClasses, loopQuery, interactions);
    window.BloxProEditor = editor;
})();
