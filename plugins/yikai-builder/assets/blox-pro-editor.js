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

    // 全局样式类（v1.23）：目录数据（globalClasses）由核心提供；这里只做作者端交互。
    // 挂类/摘类改的是 selEl.data._classes（随文档保存）；创建走 blox_class_api（服务端授权门）。
    var globalClasses = {
        newGlobalClassName: "",

        elementClassIds() {
            return this.selEl && Array.isArray(this.selEl.data._classes) ? this.selEl.data._classes : [];
        },

        globalClassLabel(classId) {
            var found = (this.globalClasses || []).find(function (item) { return item.class_id === classId; });
            return found ? found.name : classId;
        },

        availableClassOptions() {
            var assigned = this.elementClassIds();
            return (this.globalClasses || []).filter(function (item) {
                return assigned.indexOf(item.class_id) === -1;
            });
        },

        addElementClass(classId) {
            classId = String(classId || "");
            if (!this.selEl || !classId) return;
            var list = this.elementClassIds().slice();
            if (list.indexOf(classId) !== -1 || list.length >= 8) return;
            list.push(classId);
            this.selEl.data._classes = list;
        },

        removeElementClass(classId) {
            if (!this.selEl) return;
            var list = this.elementClassIds().filter(function (item) { return item !== classId; });
            if (list.length) this.selEl.data._classes = list;
            else delete this.selEl.data._classes;
        },

        createGlobalClass() {
            var name = String(this.newGlobalClassName || "").trim();
            if (!name || this._creatingClass) return;
            this._creatingClass = true;
            var body = new URLSearchParams({ action: "class_add", name: name, _token: this.csrf });
            var self = this;
            fetch("/admin/blox_class_api.php", { method: "POST", body: body })
                .then(function (response) { return response.json(); })
                .then(function (result) {
                    if (!result || Number(result.code) !== 0 || !result.data || !result.data.class) {
                        throw new Error((result && result.msg) || "error");
                    }
                    self.globalClasses = (self.globalClasses || []).concat([result.data.class]);
                    self.newGlobalClassName = "";
                    self.addElementClass(result.data.class.class_id);
                })
                .catch(function (error) { self.toast(String(error && error.message || error)); })
                .finally(function () { self._creatingClass = false; });
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
            fetch("/admin/blox_query_api.php", { method: "POST", body: body })
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

    var editor = window.BloxProEditor || { modules: [], methods: {} };
    // query_loop 的作者端由服务端面板与控件开放状态提供，无额外交互方法。
    editor.modules = (editor.modules || []).concat(["query_loop", "display_conditions", "style_presets", "global_classes", "interactions"]);
    editor.methods = Object.assign({}, editor.methods || {}, conditions, stylePresets, globalClasses, loopQuery, interactions);
    window.BloxProEditor = editor;
})();
