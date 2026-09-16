<?php
declare(strict_types=1);
?>
            // ---- 第三轮：发布冲突检查（与发布请求同一份文档与条件；结论由服务端对真实内容前后对比给出） ----

            /** 检查结论对应的条件：条件一改，旧结论只能算过期参考（发布时服务端会重新校验）。 */
            publishCheckKeyFor(condition) {
                var submission = condition || this.conditionSubmission();
                return JSON.stringify({ state: submission.state, scope: submission.scope });
            },

            publishCheckIsStale() {
                return this.publishCheck !== null && !this.publishCheckBusy && this.publishCheckKey !== this.publishCheckKeyFor();
            },

            publishCheckText(key, params) {
                var text = (this.publishCheckTexts || {})[key] || '';
                Object.keys(params || {}).forEach(function (name) { text = text.split(':' + name).join(String(params[name])); });
                return text;
            },

            publishCheckSummary() {
                var check = this.publishCheck;
                if (!check) return '';
                if (this.publishCheckBusy) return this.publishCheckText('running', { scanned: check.scanned || 0, total: check.total || 0 });
                if (check.status === 'clear') return this.publishCheckText('clear', { total: check.total || 0 });
                if (check.status === 'conflict') return this.publishCheckText('conflict', { count: check.found || 0 });
                if (check.status === 'cancelled') return this.publishCheckText('cancelled');
                return this.publishCheckText('incomplete', { scanned: check.scanned || 0, total: check.total || 0 });
            },

            publishCheckTemplateLabel(id) {
                var names = (this.publishCheck && this.publishCheck.template_names) || {};
                var label = '#' + id + (names[String(id)] ? ' ' + names[String(id)] : '');
                return Number(id) === Number(this.conditionTemplateId) ? label + ' ' + this.publishCheckText('self') : label;
            },

            /** 检查请求的文档与条件字段（与发布一致；不改动保存回执用的条件快照）。 */
            publishCheckBody(payload, condition) {
                var body = new URLSearchParams();
                body.set('action', 'check_publish_conflicts');
                body.set('id', String(this.conditionTemplateId));
                body.set('blocks_data', payload);
                if (condition.state === 'valid') body.set('conditions_json', JSON.stringify(condition.scope));
                else if (condition.state === 'unchanged' && this.conditionContentType === 'product') body.set('ui_scope', '1');
                body.set('_token', this.csrf);
                return body;
            },

            /** 手动检查入口：当前画布与条件，从头逐页检查到有结论为止。 */
            runPublishCheckNow() {
                var condition = this.conditionSubmission();
                if (condition.state === 'invalid') {
                    this.blockForInvalidConditions();
                    return;
                }
                this.runPublishCheck(this.documentData(), condition, true);
            },

            /**
             * 分页检查：服务端在会话里记进度、每页有行数与时间上限；直到 clear / conflict，或被停止、出错。
             * @return Promise<object|null> 最终报告；被停止、出错或仍未完成时为 null
             */
            runPublishCheck(payload, condition, restart) {
                var self = this;
                if (this.conditionContentType === '' || this.conditionTemplateId <= 0) return Promise.resolve(null);
                var seq = ++this.publishCheckSeq;
                this.publishCheckBusy = true;
                this.publishCheckKey = this.publishCheckKeyFor(condition);
                var first = restart === true;
                var pages = 0;
                var step = function () {
                    var body = self.publishCheckBody(payload, condition);
                    if (first) body.set('restart', '1');
                    first = false;
                    pages++;
                    return fetch('/admin/blox_template_api.php', { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (r) { return r.json().catch(function () { return { code: 1 }; }); })
                        .then(function (res) {
                            if (seq !== self.publishCheckSeq) return null;   // 已停止，或有更新的检查
                            if (Number(res.code) !== 0 || !res.data || !res.data.check) {
                                self.toast((res && res.msg) || self.publishCheckText('failed'));
                                return null;
                            }
                            self.publishCheck = res.data.check;
                            // 内容在检查期间持续变化会让指纹反复重置：设上限，停在"未完成"而不是无限重试
                            if (res.data.check.status === 'incomplete') return pages < 500 ? step() : null;
                            return res.data.check;
                        });
                };
                return step()
                    .catch(function () {
                        if (seq === self.publishCheckSeq) self.toast(self.publishCheckText('failed'));
                        return null;
                    })
                    .finally(function () {
                        if (seq === self.publishCheckSeq) self.publishCheckBusy = false;
                    });
            },

            /** 停止检查：未完成的检查不能用于发布（服务端同样不认）。 */
            cancelPublishCheck() {
                if (!this.publishCheckBusy) return;
                this.publishCheckSeq++;
                this.publishCheckBusy = false;
                this.publishCheck = Object.assign({}, this.publishCheck || {}, { status: 'cancelled' });
            },

            // ---- 第四轮：影响范围预览（只读、分页；与发布检查同一份文档与条件） ----

            impactText(key, params) {
                var text = (this.impactTexts || {})[key] || '';
                Object.keys(params || {}).forEach(function (name) { text = text.split(':' + name).join(String(params[name])); });
                return text;
            },

            impactIsStale() {
                return this.impactPreview !== null && !this.impactBusy && this.impactKey !== this.publishCheckKeyFor();
            },

            impactStatus() {
                if (this.impactBusy) return 'running';
                if (this.impactError) return 'error';
                var preview = this.impactPreview;
                if (!preview) return '';
                if (preview.cancelled) return 'cancelled';
                if (preview.complete && preview.total === 0) return 'empty';
                return preview.complete ? 'complete' : 'partial';
            },

            impactSummary() {
                var preview = this.impactPreview;
                if (!preview) return '';
                if (preview.complete && preview.total === 0) return this.impactText('empty');
                return this.impactText(preview.complete ? 'complete' : 'partial', { scanned: preview.scanned || 0, total: preview.total || 0 });
            },

            /** 检查完全部内容才写"共 N 条"；否则明确是"已检查部分中 N 条"，不给伪精确总数。 */
            impactCountText(group) {
                var preview = this.impactPreview;
                if (!preview) return '';
                var count = (preview.counts || {})[group] || 0;
                return this.impactText(preview.complete ? 'count_exact' : 'count_scanned', { count: count });
            },

            impactCheckedAtText() {
                var at = this.impactPreview && this.impactPreview.checked_at;
                return at ? this.impactText('checked_at', { time: new Date(at * 1000).toLocaleString() }) : '';
            },

            /** 开始（more=false）或从上一页之后继续（more=true）。非法条件不发请求；请求可停止、有超时，晚到响应按序号丢弃。 */
            runImpactPreview(more) {
                var self = this;
                var condition = this.conditionSubmission();
                if (condition.state === 'invalid') {
                    this.blockForInvalidConditions();
                    return Promise.resolve(null);
                }
                if (this.conditionContentType === '' || this.conditionTemplateId <= 0) return Promise.resolve(null);
                var previous = this.impactPreview;
                var continuing = more === true && previous && !previous.complete && previous.next > 0 && !this.impactIsStale();
                var seq = ++this.impactSeq;
                if (this.impactAbort) this.impactAbort.abort();
                var controller = typeof AbortController === 'function' ? new AbortController() : null;
                this.impactAbort = controller;
                var timedOut = false;
                var timer = controller ? setTimeout(function () { timedOut = true; controller.abort(); }, 20000) : null;
                this.impactBusy = true;
                this.impactError = '';
                this.impactKey = this.publishCheckKeyFor(condition);
                var body = this.publishCheckBody(this.documentData(), condition);
                body.set('action', 'preview_impact');
                if (continuing) {
                    body.set('cursor', String(previous.next));
                    body.set('fingerprint', previous.fingerprint || '');
                }
                return fetch('/admin/blox_template_api.php', {
                    method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    signal: controller ? controller.signal : undefined,
                })
                    .then(function (r) { return r.json().catch(function () { return { code: 1 }; }); })
                    .then(function (res) {
                        if (seq !== self.impactSeq) return null;
                        if (Number(res.code) !== 0 || !res.data || !res.data.preview) {
                            self.impactError = (res && res.msg) || self.impactText('failed');
                            return null;
                        }
                        var page = res.data.preview;
                        self.impactPreview = self.mergeImpactPage(continuing && !page.restarted ? previous : null, page);
                        return self.impactPreview;
                    })
                    .catch(function () {
                        if (seq !== self.impactSeq) return null;
                        self.impactError = self.impactText(timedOut ? 'timeout' : 'failed');
                        return null;
                    })
                    .finally(function () {
                        if (timer) clearTimeout(timer);
                        if (seq === self.impactSeq) {
                            self.impactBusy = false;
                            self.impactAbort = null;
                        }
                    });
            },

            /** 分页累计：计数相加，每组实例最多 5 条；服务端说指纹变了（restarted）就不带入旧计数。 */
            mergeImpactPage(state, page) {
                var merged = {
                    fingerprint: page.fingerprint,
                    total: page.total || 0,
                    next: page.next || 0,
                    complete: page.complete === true,
                    scanned: (state ? state.scanned : 0) + (page.scanned || 0),
                    lang: page.lang || '',
                    checked_at: page.checked_at || 0,
                    restarted: page.restarted === true,
                    counts: {},
                    samples: {},
                };
                ['won', 'conflicted', 'lost', 'excluded'].forEach(function (group) {
                    merged.counts[group] = (state && state.counts ? (state.counts[group] || 0) : 0) + ((page.counts || {})[group] || 0);
                    merged.samples[group] = ((state && state.samples ? state.samples[group] : null) || [])
                        .concat((page.samples || {})[group] || []).slice(0, 5);
                });
                return merged;
            },

            /** 停止：已统计的部分保留并标明"只代表已检查部分"，不影响保存。 */
            cancelImpactPreview() {
                if (!this.impactBusy) return;
                this.impactSeq++;
                if (this.impactAbort) this.impactAbort.abort();
                this.impactAbort = null;
                this.impactBusy = false;
                this.impactPreview = Object.assign(
                    { total: 0, scanned: 0, next: 0, complete: false, counts: {}, samples: {} },
                    this.impactPreview || {},
                    { cancelled: true }
                );
            },
