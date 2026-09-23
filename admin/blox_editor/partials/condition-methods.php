<?php
/**
 * 详情模板条件面板方法（编辑器状态层拆分 · 外审 P1-3 修复轮，500KB 预算拆出）。
 *
 * 归属 bloxEditor() 数据对象：条件行装载/编辑/提交组包、语言作用域基线（scopeBaseLang，
 * 显式 lang='' 全语言必须原样保留）、诊断面板与保存拦截。
 * 从 admin/blox_editor.php 原样拆出，保持方法体不变。
 */
?>
            /**
             * 面板行的装载入口。
             * - 文档已声明 v2：直接用（面板行与 v2 无损往返）；
             * - 只有 v1：做**只读适配**（产品取 product_template，其它取 detail_template 的简化 include），
             *   使 v1 模板打开面板不是空白、加一条条件也不会丢掉原有范围；适配**不改文档**，
             *   要到用户确实改了条件、保存时才写成 v2。
             * 已保存基线只在首次打开时从文档建立，之后只由保存成功回执推进：撤销/恢复稿换回来的是
             * "当前文档"，不是服务器上的版本，拿它当基线会把与服务器不同的内容判成"未修改"。
             */
            conditionEnsure() {
                if (this.conditionRows !== null) return;
                var settings = (this.docSettings && typeof this.docSettings === 'object') ? this.docSettings : {};
                var stored = settings.detail_template || {};
                var storedIsV2 = !!(stored && typeof stored === 'object' && Number(stored.version) === 2);
                var view = storedIsV2 ? stored : this.conditionLegacyBase(stored);
                if (this.conditionBaseline === null) {
                    this.conditionDocumentHadScopeKey = Object.prototype.hasOwnProperty.call(settings, 'detail_template');
                    this.conditionOriginalScope = stored;
                    this.conditionDocumentHadV2 = storedIsV2;
                    this.conditionBase = view;
                    this.conditionBaseline = view;
                }
                this.conditionRows = window.BloxDetailConditions.rowsFromScope(view);
                // TASK-007：优先级跟着文档初始化；编辑中途留下的非法输入原样带回（不悄悄变成 0）
                this.conditionPriority = window.BloxDetailConditions.priorityInput(view, this.conditionMaxPriority);
            },

            /** 撤销/重做、恢复稿替换了文档设置：面板行按新文档重建（保留已保存基线），旧诊断作废。 */
            conditionReloadFromDocument() {
                if (this.conditionContentType === '') return;
                this.conditionRows = null;
                this.conditionEnsure();
                this.conditionDiagnosisSeq++;
                this.conditionDiagnosis = null;
                this.conditionDiagnosisKey = '';
                this.conditionDiagnosisError = '';
                this.conditionDiagnosisBusy = false;
            },

            /** 打开时的只读基线：v1 产品走 product_template；其它按 detail_template 的简化 include 视图读。 */
            conditionLegacyBase(stored) {
                var legacyProduct = this.docSettings && this.docSettings.product_template;
                if (this.conditionContentType === 'product' && legacyProduct && typeof legacyProduct === 'object') {
                    return window.BloxDetailConditions.legacyProductScope(legacyProduct);
                }
                return {
                    lang: (stored && stored.lang) || '',
                    source: (stored && stored.source) || 'custom',
                    priority: typeof (stored && stored.priority) === 'number' ? stored.priority : 0,
                    include: (stored && Array.isArray(stored.include)) ? stored.include : [],
                    exclude: (stored && Array.isArray(stored.exclude)) ? stored.exclude : [],
                };
            },

            conditionAdd(side, kind) {
                this.conditionEnsure();
                this.conditionRows[side].push({ kind: kind, ids: [], include_children: kind === 'category' });
                this.syncConditionDocument();
            },

            /** 规则里引用、但下拉选项里找不到的目标（已删除或语言不同）：单独显示，可逐个移除。 */
            conditionMissingIds(row) {
                if (!row || row.kind === 'all') return [];
                var options = row.kind === 'category' ? this.conditionCategories : this.conditionItems;
                var known = (options || []).map(function (opt) { return String(opt.id); });
                return (row.ids || []).map(String).filter(function (id) { return known.indexOf(id) === -1; });
            },

            conditionRemoveId(side, index, id) {
                this.conditionEnsure();
                var row = this.conditionRows[side][index];
                if (!row) return;
                row.ids = (row.ids || []).map(String).filter(function (value) { return value !== String(id); });
                this.syncConditionDocument();
            },

            conditionRemove(side, index) {
                this.conditionEnsure();
                this.conditionRows[side].splice(index, 1);
                this.syncConditionDocument();
            },

            /** 规则类型换了，原目标不再属于同一集合（分类 id ≠ 内容 id）——必须清掉，避免提交错目标。 */
            conditionKindChanged(side, index) {
                this.conditionEnsure();
                var row = this.conditionRows[side][index];
                if (row) row.ids = [];
                this.syncConditionDocument();
            },

            /**
             * 面板编辑要同步进文档设置：全局"未保存"状态、离开保护与保存载荷都以文档为准，
             * 面板若只改自己的行模型，改条件就不会点亮保存按钮（TASK-006-R01 的保存状态项）。
             * 改回原样时把文档恢复成打开时的样子，避免"只是点了点就留下 v2 契约"。
             */
            syncConditionDocument() {
                if (this.conditionContentType === '') return;
                this.conditionEnsure();
                if (!this.docSettings || typeof this.docSettings !== 'object') this.docSettings = {};
                if (this.conditionProblems().length) {
                    // 改到一半的非法状态也要进文档：否则文档与已保存值相同，历史、未保存标记、离开保护与
                    // 恢复稿都会当它没改过。这份投影不会被提交——保存/发布先被 conditionSubmitState() 拦下。
                    this.docSettings.detail_template = window.BloxDetailConditions.editingScope(
                        this.conditionRows, this.conditionScopeBase(), this.conditionPriority
                    );
                } else if (this.conditionDirty()) {
                    this.docSettings.detail_template = this.conditionScope();
                } else if (this.conditionDocumentHadScopeKey) {
                    this.docSettings.detail_template = this.conditionOriginalScope;
                } else {
                    delete this.docSettings.detail_template;
                }
                this.$nextTick(() => this.markDocumentSettingsChanged());
            },

            /**
             * 面板负责的完整状态（规则行 + 优先级）。脏判断、保存快照与提交都以它为准，
             * 不能只比较 include/exclude——那样"只改优先级"既不标脏也存不下去（TASK-007）。
             */
            conditionPanelState() {
                this.conditionEnsure();
                return {
                    include: this.conditionRows.include,
                    exclude: this.conditionRows.exclude,
                    priority: this.conditionPriority,
                };
            },

            /** 面板状态相对基线是否已修改（规则 include/exclude + 优先级）。 */
            conditionDirty() {
                this.conditionEnsure();
                return window.BloxDetailConditions.changed(this.conditionBaseline, this.conditionPanelState());
            },

            conditionProblems() {
                this.conditionEnsure();
                return window.BloxDetailConditions.problems(this.conditionRows, this.conditionPriority, this.conditionMaxPriority);
            },

            /** 面板上是否出现了某类问题（文案分开显示，原因要具体）。 */
            conditionHasProblem(code) {
                return this.conditionProblems().some(function (problem) { return problem.code === code; });
            },

            /** 优先级控件入口：只负责把输入回灌到状态并同步文档，合法性交给 conditionProblems。 */
            conditionPriorityChanged(value) {
                this.conditionEnsure();
                this.conditionPriority = value;
                this.syncConditionDocument();
            },

            // ---- TASK-008：单条真实内容的只读诊断（不写库、不写回草稿、不影响 dirty） ----

            /** 诊断对象＝编辑器上方"预览内容"选中的那条（没有选择就不诊断）。 */
            conditionDiagnoseContentId() {
                if (this.conditionDiagnosisContentOverride > 0) return this.conditionDiagnosisContentOverride;
                var raw = this.conditionContentType === 'product' ? this.productPreviewId : this.articlePreviewId;
                return Number(raw) > 0 ? Number(raw) : 0;
            },

            /** 结果对应的内容＋条件签名：条件一改，旧结果即视为过期（不自动重查）。 */
            conditionDiagnosisRequestKey() {
                this.conditionEnsure();
                return JSON.stringify(this.conditionScope()) + '|' + this.conditionDiagnoseContentId();
            },

            conditionDiagnosisIsStale() {
                return this.conditionDiagnosis !== null && this.conditionDiagnosisKey !== this.conditionDiagnosisRequestKey();
            },

            conditionDiagnosisVerdictText() {
                var diag = this.conditionDiagnosis;
                if (!diag) return '';
                var text = (this.conditionDiagText.verdicts || {})[diag.verdict] || '';
                if (diag.verdict === 'not_considered' && diag.draft) {
                    var pre = (this.conditionDiagText.preconditions || {})[diag.draft.precondition];
                    if (pre) text += '：' + pre;
                }
                return text;
            },

            /** 草稿单独面对这条内容时是否命中；没命中要说清是被排除还是纳入条件不包含它。 */
            conditionDiagnosisMatchText() {
                var diag = this.conditionDiagnosis;
                if (!diag || !diag.draft || !diag.draft.match) return '';
                return (this.conditionDiagText.matches || {})[diag.draft.match] || '';
            },

            /** 规则引用了已不存在（或语言不同）的内容/分类：只报事实，不替用户改规则。 */
            conditionDiagnosisMissingText() {
                var diag = this.conditionDiagnosis;
                var missing = diag && diag.draft ? diag.draft.missing_references : null;
                if (!missing) return '';
                var parts = [];
                if (Array.isArray(missing.items) && missing.items.length) {
                    parts.push(this.conditionDiagText.missingItems.replace(':ids', missing.items.join(', ')));
                }
                if (Array.isArray(missing.categories) && missing.categories.length) {
                    parts.push(this.conditionDiagText.missingCategories.replace(':ids', missing.categories.join(', ')));
                }
                return parts.join(' ');
            },

            /** 实际决定输出的模板 ID（模板自身声明 native 时 template_id 为空，仍要显示是谁）。 */
            conditionDiagnosisDeciderId() {
                var winner = this.conditionDiagnosis && this.conditionDiagnosis.winner;
                if (!winner) return 0;
                return Number(winner.decided_template_id || winner.template_id || 0);
            },

            conditionDiagnosisReasonText() {
                var diag = this.conditionDiagnosis;
                if (!diag || !diag.winner) return '';
                return (this.conditionDiagText.reasons || {})[diag.winner.reason] || '';
            },

            /**
             * 只读诊断：拿"当前面板条件 + 预览内容"问服务端同一个 resolver 会怎么判。
             * 非法条件不发请求；请求带序号，只显示最新一次的结果（乱序响应直接丢弃）。
             */
            conditionDiagnose(contentId) {
                var self = this;
                if (this.conditionContentType === '' || this.conditionDiagnosisBusy) return;
                // 发布冲突列表里的"诊断这条"可指定内容；普通按钮仍诊断上方预览内容
                this.conditionDiagnosisContentOverride = Number(contentId) > 0 ? Number(contentId) : 0;
                if (this.conditionSubmitState() === 'invalid') {
                    this.blockForInvalidConditions();
                    return;
                }
                var contentId = this.conditionDiagnoseContentId();
                if (contentId <= 0) {
                    this.toast(this.conditionDiagText.needContent);
                    return;
                }
                if (this.conditionTemplateId <= 0) return;

                var scope = this.conditionScope();
                var seq = ++this.conditionDiagnosisSeq;
                var key = JSON.stringify(scope) + '|' + contentId;
                this.conditionDiagnosisBusy = true;
                this.conditionDiagnosisError = '';

                var body = new URLSearchParams();
                body.set('action', 'diagnose_conditions');
                body.set('id', String(this.conditionTemplateId));
                body.set('content_id', String(contentId));
                body.set('conditions_json', JSON.stringify(scope));
                body.set('_token', this.csrf);

                // 带 AJAX 头：权限不足等拒绝一律回 JSON，界面才能显示具体原因而不是"诊断失败"
                fetch((window.YK_BASE || '') + '/admin/blox_template_api.php', { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json().catch(function () { return { code: 1 }; }); })
                    .then(function (res) {
                        if (seq !== self.conditionDiagnosisSeq) return;   // 乱序：旧请求不得覆盖新结果
                        if (Number(res.code) !== 0 || !res.data || !res.data.diagnosis) {
                            self.conditionDiagnosisError = (res && res.msg) || self.conditionDiagText.failed;
                            return;
                        }
                        self.conditionDiagnosis = res.data.diagnosis;
                        self.conditionDiagnosisKey = key;
                    })
                    .catch(function () {
                        if (seq === self.conditionDiagnosisSeq) self.conditionDiagnosisError = self.conditionDiagText.failed;
                    })
                    .finally(function () {
                        if (seq === self.conditionDiagnosisSeq) self.conditionDiagnosisBusy = false;
                    });
            },

            /**
             * 头部只读语言框的显示值（外审 P1-3）：作用域里显式的 lang='' 表示全部语言，
             * 必须显示为"全部语言"而不是 truthy 回退到当前预览语言。
             */
            templateLanguageLabel() {
                return window.BloxDetailConditions.languageLabel([
                    this.docSettings && this.docSettings.detail_template,
                    this.docSettings && this.docSettings.product_template,
                ], this.conditionAllLanguagesLabel, this.conditionLang);
            },

            /** 面板不编辑、但提交必须原样保留的字段（来自已保存基线，避免 source/lang 漂移）。 */
            conditionScopeBase() {
                var base = this.conditionBase || {};
                return {
                    content_type: this.conditionContentType,
                    // v1.26 全语言作用域的 lang='' 是显式值（外审 P1-3）：truthy 回退会在
                    // 下一次保存时把它静默改写成当前预览语言；只有基线里根本没有 lang 才补。
                    lang: window.BloxDetailConditions.scopeBaseLang(base, this.conditionLang),
                    source: base.source || 'custom',
                    // TASK-007：优先级取面板当前值（控件维护），不再回读文档
                    priority: Number(this.conditionPriority) || 0,
                };
            },

            conditionScope() {
                this.conditionEnsure();
                return window.BloxDetailConditions.scopeFromRows(this.conditionRows, this.conditionScopeBase());
            },

            /**
             * 条件提交三态（TASK-006-R01 P1：必须区分，否则非法输入会退回旧路径把用户改动丢掉）：
             * - invalid  ：存在空目标行等非法状态 → 中止整个保存/发布，不发请求，保留面板
             * - valid    ：文档已声明 v2（始终完整提交，绝不回退旧投影）；或 v1 但用户明确改了条件（此刻转换）
             * - unchanged：v1 且用户没动条件 → 不带完整条件，交回旧路径（不迁移）
             */
            conditionSubmitState() {
                if (this.conditionContentType === '') return 'unchanged';
                this.conditionEnsure();
                if (this.conditionProblems().length) return 'invalid';
                if (this.conditionDocumentHadV2) return 'valid';
                return this.conditionDirty() ? 'valid' : 'unchanged';
            },

            /**
             * 把条件字段写进保存/发布请求体。
             * @return string 'invalid' | 'valid' | 'unchanged'
             */
            applyConditionSubmit(body, captured) {
                var submission = captured || this.conditionSubmission();
                // 每次提交先清空：失败的提交或走旧路径的提交，绝不能在下一次成功回执里被当成"刚保存的条件"
                this._submittedConditionScope = null;
                if (submission.state === 'valid') {
                    body.set('conditions_json', JSON.stringify(submission.scope));
                    this._submittedConditionScope = submission.scope;
                }
                return submission.state;
            },

            /** 与文档载荷同一时刻取下的条件提交（发布重试时沿用，不能换成重试那一刻的面板）。 */
            conditionSubmission() {
                var state = this.conditionSubmitState();
                return { state: state, scope: state === 'valid' ? this.conditionScope() : null };
            },

            /** 非法条件被拦下时，把面板摊开并滚到可见处——原因必须能看见才谈得上修。 */
            revealConditionPanel() {
                var panel = document.querySelector('[data-testid="blox-detail-conditions"]');
                if (!panel) return;
                var details = panel.closest('details');
                if (details && !details.open) details.open = true;
                if (typeof panel.scrollIntoView === 'function') panel.scrollIntoView({ block: 'nearest' });
            },

            /** 保存/发布被条件拦下时的统一反馈：说明原因 + 摊开面板，不发请求。 */
            blockForInvalidConditions() {
                this.saveOutcome = '';
                this.toast(this.uiText.conditionSaveBlocked);
                this.$nextTick(() => this.revealConditionPanel());
            },
