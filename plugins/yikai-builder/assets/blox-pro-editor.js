/* BLOX Pro 作者端模块：由编辑器以 `...window.BloxProEditor.methods` 混入 Alpine 组件。
 * 只负责作者交互；保存校验与前台条件匹配始终留在核心。 */
(function () {
    "use strict";

    var data = window.BloxProEditorData || {};

    var conditions = {
        conditionChannels: Array.isArray(data.conditionChannels) ? data.conditionChannels : [],
        conditionText: data.conditionText && typeof data.conditionText === "object" ? data.conditionText : {},

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
            if (type === "channel") return [
                { value: "is", label: this.conditionText.is },
                { value: "is_not", label: this.conditionText.isNot },
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

    var editor = window.BloxProEditor || { modules: [], methods: {} };
    // query_loop 的作者端由服务端面板与控件开放状态提供，无额外交互方法。
    editor.modules = (editor.modules || []).concat(["query_loop", "display_conditions", "style_presets", "global_classes"]);
    editor.methods = Object.assign({}, editor.methods || {}, conditions, stylePresets, globalClasses);
    window.BloxProEditor = editor;
})();
