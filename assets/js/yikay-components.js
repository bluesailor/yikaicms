/**
 * Blox 组件（v2.1，RFC-2）编辑器混入：组件库、实例属性面板、母版属性定义。
 *
 * 实例 = 元素 {type:"component", data:{component:uuid, props:{只存覆盖过的键}}}；重置 = 删键。
 * 母版 = type=component 的模板，属性定义存 docSettings.component.props，改完要 markDocumentSettingsChanged()。
 * 渲染全在服务端（画布预览走 PHP），这里只改数据。
 */
(function (root) {
    "use strict";

    // 控件类型 → 属性类型（不在表里的控件不能导出为属性）
    var CONTROL_TO_PROP = {
        text: "text", textarea: "text", richtext: "richtext", image: "image", icon: "icon",
        url: "url", number: "number", range: "number", checkbox: "boolean", select: "select", color: "color"
    };

    function api(self, action, fields) {
        var body = new URLSearchParams(Object.assign({ action: action, _token: self.csrf }, fields || {}));
        return fetch((root.YK_BASE || "") + "/admin/blox_component_api.php", { method: "POST", body: body })
            .then(function (response) { return response.json(); })
            .then(function (result) {
                if (!result || Number(result.code) !== 0) {
                    throw new Error((result && result.msg) || self.componentText.failed);
                }
                return result.data || {};
            });
    }

    function slugKey(label, taken) {
        var base = String(label || "").toLowerCase().replace(/[^a-z0-9]+/g, "_").replace(/^_+|_+$/g, "");
        if (!/^[a-z]/.test(base)) base = "prop" + (base ? "_" + base : "");
        base = base.slice(0, 28);
        var key = base, n = 2;
        while (taken.indexOf(key) !== -1) key = base + "_" + (n++);
        return key;
    }

    root.YikaiBloxComponents = {
        mixin: function (initial) {
            return {
                componentMasterMode: !!initial.master,
                componentCanManage: !!initial.canManage,
                componentText: initial.text || {},
                libTab: "elements",
                componentList: [],
                componentCategories: [],
                componentLoaded: false,
                componentLoading: false,
                componentError: "",
                componentQuery: "",
                componentCategory: "all",
                componentCards: {},
                componentBindings: {},
                componentBusy: false,

                // ── 组件库 ────────────────────────────────────────────
                openComponentLibrary: function () {
                    this.libTab = "components";
                    this.loadComponents(false);
                },
                loadComponents: function (force) {
                    if (this.componentLoading || (this.componentLoaded && !force)) return;
                    var self = this;
                    this.componentLoading = true;
                    this.componentError = "";
                    api(this, "list").then(function (data) {
                        self.componentList = data.components || [];
                        self.componentCategories = data.categories || [];
                        self.componentCanManage = !!data.can_manage;
                        self.componentList.forEach(function (card) { self.componentCards[card.uuid] = card; });
                        self.componentLoaded = true;
                    }).catch(function (error) {
                        self.componentError = error.message;
                    }).finally(function () {
                        self.componentLoading = false;
                    });
                },
                filteredComponents: function () {
                    var query = this.componentQuery.trim().toLowerCase();
                    var category = this.componentCategory;
                    return this.componentList.filter(function (card) {
                        return (category === "all" || card.category === category)
                            && (query === "" || card.name.toLowerCase().indexOf(query) !== -1);
                    });
                },
                componentCategoryLabel: function (key) {
                    var found = this.componentCategories.filter(function (c) { return c.key === key; })[0];
                    return found ? found.label : key;
                },
                insertComponent: function (card) {
                    this.componentCards[card.uuid] = card;
                    this.addElement({ type: "component", label: card.name, icon: "components", defaults: { component: card.uuid, props: {} } });
                },

                // ── 实例属性面板 ──────────────────────────────────────
                componentCard: function (uuid) {
                    if (!uuid) return null;
                    if (!this.componentCards[uuid] && !this.componentCards["loading:" + uuid]) {
                        var self = this;
                        this.componentCards["loading:" + uuid] = true;
                        api(this, "schema", { uuid: uuid }).then(function (data) {
                            self.componentCards[uuid] = data.component;
                            self.componentBindings[uuid] = data.loop_bindings || {};
                        }).catch(function () {
                            self.componentCards[uuid] = { uuid: uuid, missing: true, props: [] };
                        });
                    }
                    return this.componentCards[uuid] || null;
                },
                instanceProps: function () {
                    var card = this.selEl && this.selEl.type === "component" ? this.componentCard(this.selEl.data.component) : null;
                    return card && !card.missing ? card.props : [];
                },
                instanceOverridden: function (key) {
                    var props = this.selEl && this.selEl.data && this.selEl.data.props;
                    return !!props && !Array.isArray(props) && Object.prototype.hasOwnProperty.call(props, key);
                },
                instanceValue: function (prop) {
                    return this.instanceOverridden(prop.key) ? this.selEl.data.props[prop.key] : prop.default;
                },
                setInstanceValue: function (prop, value) {
                    var props = Object.assign({}, Array.isArray(this.selEl.data.props) ? {} : (this.selEl.data.props || {}));
                    props[prop.key] = value;
                    this.selEl.data.props = props;
                },
                resetInstanceValue: function (prop) {
                    // 重置 = 删掉覆盖值，重新跟随母版默认（不是把当前默认值抄进来）
                    var props = Object.assign({}, this.selEl.data.props || {});
                    delete props[prop.key];
                    this.selEl.data.props = props;
                },
                selectedInsideLoop: function () {
                    if (!this.selTopEl || this.selectedSubPath === undefined) return false;
                    var node = this.selTopEl, path = (this.selectedSubPath || []).slice();
                    if (node.data && node.data._query && path.length) return true;
                    for (var i = 0; i < path.length - 1; i++) {
                        node = node && node.data && node.data.children ? node.data.children[path[i]] : null;
                        if (node && node.data && node.data._query) return true;
                    }
                    return false;
                },
                loopBindingSuggestions: function () {
                    var uuid = this.selEl && this.selEl.data ? this.selEl.data.component : "";
                    var all = this.componentBindings[uuid] || {};
                    var self = this, pending = {};
                    Object.keys(all).forEach(function (key) {
                        if (!self.instanceOverridden(key)) pending[key] = all[key];
                    });
                    return pending;
                },
                applyLoopBindings: function () {
                    var pending = this.loopBindingSuggestions();
                    if (!Object.keys(pending).length) return;
                    this.selEl.data.props = Object.assign({}, this.selEl.data.props || {}, pending);
                    this.toast(this.componentText.bound.replace(":n", Object.keys(pending).length));
                },
                editComponentMaster: function () {
                    var card = this.componentCard(this.selEl.data.component);
                    if (card && card.id) root.open("blox_editor.php?template=" + card.id, "_blank", "noopener");
                },
                detachSelectedComponent: function () {
                    var self = this, el = this.selEl;
                    if (!el || el.type !== "component" || this.componentBusy) return;
                    if (!root.confirm(this.componentText.detachConfirm)) return;
                    this.componentBusy = true;
                    api(this, "detach", { element: JSON.stringify(el) }).then(function (data) {
                        // 原地换成普通结构：同一个对象引用，选中状态与路径不变
                        self.runCommand("detach-component", function () {
                            el.type = data.element.type;
                            el.id = data.element.id;
                            el.data = data.element.data;
                        });
                        self.toast(self.componentText.detached);
                    }).catch(function (error) {
                        self.toast(error.message);
                    }).finally(function () {
                        self.componentBusy = false;
                    });
                },
                saveSelectionAsComponent: function () {
                    var self = this, el = this.selEl;
                    if (!el || el.type === "component" || this.componentBusy) return;
                    var name = root.prompt(this.componentText.namePrompt, (this.elSchema(el.type) || {}).label || "");
                    if (!name || !name.trim()) return;
                    this.componentBusy = true;
                    api(this, "create_from_element", { element: JSON.stringify(el), name: name.trim(), category: "custom" }).then(function (data) {
                        self.toast(self.componentText.created);
                        root.open(data.editor_url, "_blank", "noopener");
                    }).catch(function (error) {
                        self.toast(error.message);
                    }).finally(function () {
                        self.componentBusy = false;
                    });
                },

                // ── 母版：属性定义 ────────────────────────────────────
                masterProps: function () {
                    if (!this.docSettings.component || !Array.isArray(this.docSettings.component.props)) {
                        this.docSettings.component = { props: [] };
                    }
                    return this.docSettings.component.props;
                },
                componentExposable: function (ctrl) {
                    return this.componentMasterMode && !!this.selEl && !!this.selEl.id && !ctrl.responsive
                        && Object.prototype.hasOwnProperty.call(CONTROL_TO_PROP, ctrl.type) && String(ctrl.key).charAt(0) !== "_";
                },
                componentExposableCtrls: function () {
                    if (!this.componentMasterMode || !this.selEl) return [];
                    var self = this, schema = this.elSchema(this.selEl.type) || {};
                    return (schema.controls || []).filter(function (ctrl) {
                        return ctrl.key && !ctrl.editor_hidden && self.componentExposable(ctrl);
                    });
                },
                exposedProp: function (ctrl) {
                    var nodeId = this.selEl ? this.selEl.id : "";
                    return this.masterProps().filter(function (prop) {
                        return (prop.targets || []).some(function (t) { return t.node === nodeId && t.field === ctrl.key; });
                    })[0] || null;
                },
                exposeControl: function (ctrl) {
                    if (!this.componentExposable(ctrl) || this.exposedProp(ctrl)) return;
                    var props = this.masterProps();
                    var type = CONTROL_TO_PROP[ctrl.type];
                    var prop = {
                        key: slugKey(ctrl.key, props.map(function (p) { return p.key; })),
                        type: type,
                        label: ctrl.label || ctrl.key,
                        default: this.selEl.data[ctrl.key] !== undefined ? this.selEl.data[ctrl.key] : (ctrl.default !== undefined ? ctrl.default : ""),
                        targets: [{ node: this.selEl.id, field: ctrl.key }]
                    };
                    if (type === "select") prop.options = ctrl.options || {};
                    props.push(prop);
                    this.markDocumentSettingsChanged();
                    this.toast(this.componentText.exposed.replace(":label", prop.label));
                },
                unexposeControl: function (ctrl) {
                    var nodeId = this.selEl ? this.selEl.id : "";
                    var props = this.masterProps();
                    for (var i = props.length - 1; i >= 0; i--) {
                        props[i].targets = (props[i].targets || []).filter(function (t) { return !(t.node === nodeId && t.field === ctrl.key); });
                        if (!props[i].targets.length) props.splice(i, 1);
                    }
                    this.markDocumentSettingsChanged();
                },
                moveMasterProp: function (index, delta) {
                    var props = this.masterProps(), to = index + delta;
                    if (to < 0 || to >= props.length) return;
                    props.splice(to, 0, props.splice(index, 1)[0]);
                    this.markDocumentSettingsChanged();
                },
                removeMasterProp: function (index) {
                    this.masterProps().splice(index, 1);
                    this.markDocumentSettingsChanged();
                },
                renameMasterProp: function (prop, field, value) {
                    if (field === "key") {
                        var others = this.masterProps().filter(function (p) { return p !== prop; }).map(function (p) { return p.key; });
                        value = slugKey(value, others);
                    }
                    prop[field] = value;
                    this.markDocumentSettingsChanged();
                }
            };
        }
    };
})(window);
