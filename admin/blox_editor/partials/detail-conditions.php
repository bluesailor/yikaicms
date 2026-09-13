<?php
/**
 * 完整条件面板（TASK-006 第一批）。
 *
 * 编辑的是 v2 作用域的多条规则：include 的 all/item/category（含子级）与 exclude（沿用 resolver
 * 允许的类型，即不含 all）。保存时经 `conditions_json` 完整通道提交，与旧 `ui_scope` 互斥；
 * 命中语义一律由服务端 DetailTemplateResolver 判定，本面板不做客户端匹配。
 */
declare(strict_types=1);
?>
<template x-if="conditionContentType !== ''">
    <div class="border-t border-gray-200 mt-3 pt-3" data-testid="blox-detail-conditions" x-init="conditionEnsure()">
        <p class="text-xs font-medium text-gray-700 mb-2"><?= e(__('blox_cond_title')) ?></p>
        <?php // TASK-007：优先级不是"必定胜出"——更具体的规则先决定命中，这里只在具体度相同时比较 ?>
        <div class="flex flex-wrap items-center gap-2 mb-3">
            <label class="inline-flex items-center gap-1 text-xs text-gray-600">
                <span><?= e(__('blox_cond_priority')) ?></span>
                <input type="number" min="0" :max="conditionMaxPriority" step="1"
                       x-model="conditionPriority" @input="conditionPriorityChanged($event.target.value)"
                       data-testid="blox-cond-priority"
                       class="w-20 border border-gray-300 rounded px-2 py-1 text-xs">
            </label>
            <span class="text-[11px] leading-relaxed text-gray-500" data-testid="blox-cond-priority-hint"><?= e(__('blox_cond_priority_hint')) ?></span>
        </div>
        <?php foreach (['include' => 'blox_cond_include', 'exclude' => 'blox_cond_exclude'] as $side => $sideLabel): ?>
        <div class="mb-3">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
                <span class="text-xs text-gray-600"><?= e(__($sideLabel)) ?></span>
                <span class="flex flex-wrap gap-1">
                    <?php if ($side === 'include'): ?>
                    <button type="button" @click="conditionAdd('include', 'all')" data-testid="blox-cond-add-include-all"
                            class="text-[11px] px-2 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50"><?= e(__('blox_article_scope_all')) ?></button>
                    <?php endif; ?>
                    <button type="button" @click="conditionAdd('<?= e($side) ?>', 'item')" data-testid="blox-cond-add-<?= e($side) ?>-item"
                            class="text-[11px] px-2 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50"><?= e(__('blox_cond_add_item')) ?></button>
                    <button type="button" @click="conditionAdd('<?= e($side) ?>', 'category')" data-testid="blox-cond-add-<?= e($side) ?>-category"
                            class="text-[11px] px-2 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50"><?= e(__('blox_cond_add_category')) ?></button>
                </span>
            </div>
            <template x-for="(row, index) in (conditionRows ? conditionRows['<?= e($side) ?>'] : [])" :key="'<?= e($side) ?>-' + index">
                <div class="flex flex-wrap items-center gap-2 py-1" :data-testid="'blox-cond-<?= e($side) ?>-row-' + index">
                    <?php // 每次编辑都要同步进文档设置：否则"只改条件"不会点亮全局未保存态（R01 保存状态项） ?>
                    <select x-model="row.kind" @change="conditionKindChanged('<?= e($side) ?>', index)" :data-testid="'blox-cond-<?= e($side) ?>-kind-' + index"
                            class="border border-gray-300 rounded px-2 py-1 text-xs">
                        <?php if ($side === 'include'): ?>
                        <option value="all"><?= e(__('blox_article_scope_all')) ?></option>
                        <?php endif; ?>
                        <option value="item"><?= e(__('blox_cond_kind_item')) ?></option>
                        <option value="category"><?= e(__('blox_cond_kind_category')) ?></option>
                    </select>
                    <template x-if="row.kind !== 'all'">
                        <select multiple x-model="row.ids" @change="syncConditionDocument()" :data-testid="'blox-cond-<?= e($side) ?>-target-' + index"
                                class="border border-gray-300 rounded px-2 py-1 text-xs min-w-[12rem] max-h-24">
                            <?php // 选项由 x-for 后建，x-model 的首次同步可能早于选项存在——那样重开时会"看着没选中"。
                                  // 所以每条 option 自己按 row.ids 绑定 selected，不依赖先后顺序。 ?>
                            <template x-for="opt in (row.kind === 'category' ? conditionCategories : conditionItems)" :key="opt.id">
                                <option :value="String(opt.id)" x-text="opt.name"
                                        :selected="(row.ids || []).map(String).includes(String(opt.id))"></option>
                            </template>
                        </select>
                    </template>
                    <label x-show="row.kind === 'category'" class="inline-flex items-center gap-1 text-[11px] text-gray-600">
                        <input type="checkbox" x-model="row.include_children" @change="syncConditionDocument()" :data-testid="'blox-cond-<?= e($side) ?>-children-' + index">
                        <span><?= e(__('blox_scope_children')) ?></span>
                    </label>
                    <button type="button" @click="conditionRemove('<?= e($side) ?>', index)" :data-testid="'blox-cond-remove-<?= e($side) ?>-' + index"
                            class="text-[11px] px-2 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50"><?= e(__('blox_cond_remove')) ?></button>
                </div>
            </template>
        </div>
        <?php endforeach; ?>
        <?php // TASK-008：只读诊断——拿当前条件去问同一个 resolver "这条内容会命谁"，不写库、不改草稿 ?>
        <div class="mt-3 border-t border-gray-200 pt-2" data-testid="blox-cond-diagnose">
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" @click="conditionDiagnose()" :disabled="conditionDiagnosisBusy"
                        data-testid="blox-diagnose-run"
                        class="text-[11px] px-2 py-1 rounded border border-gray-300 text-gray-700 hover:bg-gray-50 disabled:opacity-60">
                    <span x-show="!conditionDiagnosisBusy"><?= e(__('blox_diag_button')) ?></span>
                    <span x-show="conditionDiagnosisBusy" x-cloak><?= e(__('blox_diag_running')) ?></span>
                </button>
            </div>
            <p x-show="conditionDiagnosisError" x-cloak data-testid="blox-diagnose-error"
               class="mt-1 text-[11px] text-red-600" x-text="conditionDiagnosisError"></p>
            <template x-if="conditionDiagnosis">
                <div class="mt-2 rounded border border-gray-200 bg-gray-50 px-2 py-2 text-[11px] leading-relaxed text-gray-700"
                     data-testid="blox-diagnose-result">
                    <p data-testid="blox-diagnose-verdict" class="font-medium text-gray-900" x-text="conditionDiagnosisVerdictText()"></p>
                    <p x-show="conditionDiagnosisIsStale()" x-cloak data-testid="blox-diagnose-stale" class="mt-1 text-amber-600"><?= e(__('blox_diag_stale')) ?></p>
                    <p x-show="conditionDiagnosisMatchText()" x-cloak data-testid="blox-diagnose-match" class="mt-1" x-text="conditionDiagnosisMatchText()"></p>
                    <p x-show="conditionDiagnosis && conditionDiagnosis.others_conflicted" x-cloak data-testid="blox-diagnose-others-conflicted" class="mt-1 text-amber-600"><?= e(__('blox_diag_others_conflicted')) ?></p>
                    <p x-show="conditionDiagnosisMissingText()" x-cloak data-testid="blox-diagnose-missing" class="mt-1 text-amber-600" x-text="conditionDiagnosisMissingText()"></p>
                    <p x-show="conditionDiagnosis && conditionDiagnosis.content && conditionDiagnosis.content.published === false" x-cloak data-testid="blox-diagnose-unpublished" class="mt-1 text-gray-500"><?= e(__('blox_diag_unpublished')) ?></p>
                    <dl class="mt-1 grid grid-cols-[auto_1fr] gap-x-2">
                        <dt class="text-gray-500"><?= e(__('blox_diag_content_label')) ?></dt>
                        <dd data-testid="blox-diagnose-content"
                            x-text="conditionDiagnosis && conditionDiagnosis.content ? ('#' + conditionDiagnosis.content.id + ' · ' + conditionDiagnosis.content.lang) : ''"></dd>
                        <dt class="text-gray-500"><?= e(__('blox_diag_winner_label')) ?></dt>
                        <dd data-testid="blox-diagnose-winner">
                            <?php // 两个 span 各有 testid：隐藏的"无"不会污染命中模板 ID 的断言 ?>
                            <span data-testid="blox-diagnose-winner-id"
                                  x-show="conditionDiagnosisDeciderId() > 0"
                                  x-text="conditionDiagnosisDeciderId() > 0 ? conditionDiagnosisDeciderId() : ''"></span>
                            <span data-testid="blox-diagnose-winner-none"
                                  x-show="conditionDiagnosis && conditionDiagnosis.winner && conditionDiagnosisDeciderId() === 0"><?= e(__('blox_diag_none')) ?></span>
                        </dd>
                        <dt class="text-gray-500"><?= e(__('blox_diag_reason_label')) ?></dt>
                        <dd data-testid="blox-diagnose-reason" x-text="conditionDiagnosisReasonText()"></dd>
                        <dt class="text-gray-500"><?= e(__('blox_diag_specificity_label')) ?></dt>
                        <dd data-testid="blox-diagnose-specificity"
                            x-text="conditionDiagnosis && conditionDiagnosis.winner ? (conditionDiagnosis.winner.specificity.level + ' / ' + conditionDiagnosis.winner.specificity.detail) : ''"></dd>
                        <dt class="text-gray-500"><?= e(__('blox_diag_priority_label')) ?></dt>
                        <dd data-testid="blox-diagnose-priority" x-text="conditionDiagnosis && conditionDiagnosis.draft ? conditionDiagnosis.draft.priority : ''"></dd>
                        <template x-if="conditionDiagnosis && conditionDiagnosis.conflicts && conditionDiagnosis.conflicts.length">
                            <dt class="text-gray-500"><?= e(__('blox_diag_conflicts_label')) ?></dt>
                        </template>
                        <template x-if="conditionDiagnosis && conditionDiagnosis.conflicts && conditionDiagnosis.conflicts.length">
                            <dd data-testid="blox-diagnose-conflicts"
                                x-text="conditionDiagnosis.conflicts.map(function (c) { return c.template_id; }).join(', ')"></dd>
                        </template>
                    </dl>
                    <?php // 单条内容的结果不能当成全站结论（任务书明确要求） ?>
                    <p class="mt-1 text-gray-500"><?= e(__('blox_diag_single_only')) ?></p>
                </div>
            </template>
        </div>
        <?php // 第三轮：发布冲突检查。结论来自服务端对真实内容的前后对比；未完成、已停止都不等于通过 ?>
        <div class="mt-3 border-t border-gray-200 pt-2" data-testid="blox-publish-check">
            <p class="text-xs font-medium text-gray-700"><?= e(__('blox_pubcheck_title')) ?></p>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <button type="button" @click="runPublishCheckNow()" :disabled="publishCheckBusy" data-testid="blox-publish-check-run"
                        class="text-[11px] px-2 py-1 rounded border border-gray-300 text-gray-700 hover:bg-gray-50 disabled:opacity-60"><?= e(__('blox_pubcheck_run')) ?></button>
                <button type="button" x-show="publishCheckBusy" x-cloak @click="cancelPublishCheck()" data-testid="blox-publish-check-cancel"
                        class="text-[11px] px-2 py-1 rounded border border-gray-300 text-gray-700 hover:bg-gray-50"><?= e(__('blox_pubcheck_cancel')) ?></button>
            </div>
            <template x-if="publishCheck">
                <div class="mt-2 rounded border px-2 py-2 text-[11px] leading-relaxed"
                     :class="publishCheck.status === 'conflict' ? 'border-red-200 bg-red-50 text-red-700' : (publishCheck.status === 'clear' ? 'border-gray-200 bg-gray-50 text-gray-700' : 'border-amber-200 bg-amber-50 text-amber-700')"
                     data-testid="blox-publish-check-result" :data-status="publishCheckBusy ? 'running' : publishCheck.status">
                    <p data-testid="blox-publish-check-summary" x-text="publishCheckSummary()"></p>
                    <p x-show="publishCheckIsStale()" x-cloak data-testid="blox-publish-check-stale" class="mt-1"><?= e(__('blox_pubcheck_stale')) ?></p>
                    <ul x-show="publishCheck.conflicts && publishCheck.conflicts.length" class="mt-1 space-y-1">
                        <template x-for="item in (publishCheck.conflicts || [])" :key="item.content_id">
                            <li class="flex flex-wrap items-center gap-x-2 gap-y-1" :data-testid="'blox-publish-conflict-' + item.content_id">
                                <span class="font-medium" x-text="publishCheckText('item', { id: item.content_id, lang: item.lang }) + (item.published ? '' : ' ' + publishCheckText('unpublished'))"></span>
                                <span x-text="publishCheckText(item.kind === 'exposed' ? 'exposed' : 'member')"></span>
                                <span><?= e(__('blox_pubcheck_templates')) ?></span>
                                <template x-for="templateId in (item.template_ids || [])" :key="item.content_id + '-' + templateId">
                                    <a class="underline" :href="'/admin/blox_editor.php?template=' + templateId" target="_blank" rel="noopener"
                                       :data-testid="'blox-publish-conflict-template-' + templateId" x-text="publishCheckTemplateLabel(templateId)"></a>
                                </template>
                                <button type="button" @click="conditionDiagnose(item.content_id)" :data-testid="'blox-publish-conflict-diagnose-' + item.content_id"
                                        class="px-1.5 py-0.5 rounded border border-current"><?= e(__('blox_pubcheck_diagnose')) ?></button>
                            </li>
                        </template>
                    </ul>
                </div>
            </template>
        </div>
        <?php // 第四轮：影响范围预览。只读；没检查完的数字只代表已检查部分，不写成总数 ?>
        <div class="mt-3 border-t border-gray-200 pt-2" data-testid="blox-impact">
            <p class="text-xs font-medium text-gray-700"><?= e(__('blox_impact_title')) ?></p>
            <div class="mt-1 flex flex-wrap items-center gap-2">
                <button type="button" @click="runImpactPreview(false)" :disabled="impactBusy" data-testid="blox-impact-run"
                        class="text-[11px] px-2 py-1 rounded border border-gray-300 text-gray-700 hover:bg-gray-50 disabled:opacity-60"><?= e(__('blox_impact_run')) ?></button>
                <button type="button" x-show="impactPreview && !impactPreview.complete && impactPreview.next > 0 && !impactBusy && !impactIsStale()" x-cloak
                        @click="runImpactPreview(true)" data-testid="blox-impact-more"
                        class="text-[11px] px-2 py-1 rounded border border-gray-300 text-gray-700 hover:bg-gray-50"><?= e(__('blox_impact_more')) ?></button>
                <button type="button" x-show="impactBusy" x-cloak @click="cancelImpactPreview()" data-testid="blox-impact-cancel"
                        class="text-[11px] px-2 py-1 rounded border border-gray-300 text-gray-700 hover:bg-gray-50"><?= e(__('blox_impact_cancel')) ?></button>
            </div>
            <p x-show="impactError" x-cloak data-testid="blox-impact-error" class="mt-1 text-[11px] text-red-600" x-text="impactError"></p>
            <template x-if="impactPreview">
                <div class="mt-2 rounded border border-gray-200 bg-gray-50 px-2 py-2 text-[11px] leading-relaxed text-gray-700"
                     data-testid="blox-impact-result" :data-status="impactStatus()">
                    <p data-testid="blox-impact-summary" x-text="impactSummary()"></p>
                    <p x-show="impactPreview.cancelled" x-cloak data-testid="blox-impact-cancelled" class="mt-1 text-amber-700"><?= e(__('blox_impact_cancelled')) ?></p>
                    <p x-show="impactIsStale()" x-cloak data-testid="blox-impact-stale" class="mt-1 text-amber-700"><?= e(__('blox_impact_stale')) ?></p>
                    <p x-show="impactCheckedAtText()" class="mt-1 text-gray-500" data-testid="blox-impact-checked-at" x-text="impactCheckedAtText()"></p>
                    <div class="mt-2" data-testid="blox-impact-group-won">
                        <p><span class="font-medium"><?= e(__('blox_impact_group_won')) ?></span> <span data-testid="blox-impact-count-won" x-text="impactCountText('won')"></span></p>
                        <ul class="mt-1 space-y-1">
                            <template x-for="item in ((impactPreview.samples || {})['won'] || [])" :key="'won-' + item.id">
                                <li :data-testid="'blox-impact-sample-won-' + item.id">
                                    <template x-if="item.url">
                                        <a class="underline" :href="item.url" target="_blank" rel="noopener" :data-testid="'blox-impact-link-' + item.id" x-text="'#' + item.id + ' ' + item.title + ' · ' + item.lang"></a>
                                    </template>
                                    <template x-if="!item.url">
                                        <span x-text="'#' + item.id + ' ' + item.title + ' · ' + item.lang + ' ' + impactText('unpublished')"></span>
                                    </template>
                                </li>
                            </template>
                        </ul>
                    </div>
                    <div class="mt-2" data-testid="blox-impact-group-conflicted">
                        <p><span class="font-medium"><?= e(__('blox_impact_group_conflicted')) ?></span> <span data-testid="blox-impact-count-conflicted" x-text="impactCountText('conflicted')"></span></p>
                        <ul class="mt-1 space-y-1">
                            <template x-for="item in ((impactPreview.samples || {})['conflicted'] || [])" :key="'conflicted-' + item.id">
                                <li :data-testid="'blox-impact-sample-conflicted-' + item.id">
                                    <template x-if="item.url">
                                        <a class="underline" :href="item.url" target="_blank" rel="noopener" :data-testid="'blox-impact-link-' + item.id" x-text="'#' + item.id + ' ' + item.title + ' · ' + item.lang"></a>
                                    </template>
                                    <template x-if="!item.url">
                                        <span x-text="'#' + item.id + ' ' + item.title + ' · ' + item.lang + ' ' + impactText('unpublished')"></span>
                                    </template>
                                </li>
                            </template>
                        </ul>
                    </div>
                    <div class="mt-2" data-testid="blox-impact-group-lost">
                        <p><span class="font-medium"><?= e(__('blox_impact_group_lost')) ?></span> <span data-testid="blox-impact-count-lost" x-text="impactCountText('lost')"></span></p>
                        <ul class="mt-1 space-y-1">
                            <template x-for="item in ((impactPreview.samples || {})['lost'] || [])" :key="'lost-' + item.id">
                                <li :data-testid="'blox-impact-sample-lost-' + item.id">
                                    <template x-if="item.url">
                                        <a class="underline" :href="item.url" target="_blank" rel="noopener" :data-testid="'blox-impact-link-' + item.id" x-text="'#' + item.id + ' ' + item.title + ' · ' + item.lang"></a>
                                    </template>
                                    <template x-if="!item.url">
                                        <span x-text="'#' + item.id + ' ' + item.title + ' · ' + item.lang + ' ' + impactText('unpublished')"></span>
                                    </template>
                                </li>
                            </template>
                        </ul>
                    </div>
                    <div class="mt-2" data-testid="blox-impact-group-excluded">
                        <p><span class="font-medium"><?= e(__('blox_impact_group_excluded')) ?></span> <span data-testid="blox-impact-count-excluded" x-text="impactCountText('excluded')"></span></p>
                        <ul class="mt-1 space-y-1">
                            <template x-for="item in ((impactPreview.samples || {})['excluded'] || [])" :key="'excluded-' + item.id">
                                <li :data-testid="'blox-impact-sample-excluded-' + item.id">
                                    <template x-if="item.url">
                                        <a class="underline" :href="item.url" target="_blank" rel="noopener" :data-testid="'blox-impact-link-' + item.id" x-text="'#' + item.id + ' ' + item.title + ' · ' + item.lang"></a>
                                    </template>
                                    <template x-if="!item.url">
                                        <span x-text="'#' + item.id + ' ' + item.title + ' · ' + item.lang + ' ' + impactText('unpublished')"></span>
                                    </template>
                                </li>
                            </template>
                        </ul>
                    </div>
                    <p class="mt-2 text-gray-500"><?= e(__('blox_impact_note')) ?></p>
                </div>
            </template>
        </div>
        <?php // 说清"不应用"与"主题默认"的区别：前者是空 include，后者是 source=native ?>
        <p class="text-[11px] leading-relaxed text-gray-500" data-testid="blox-cond-notes"><?= e(__('blox_cond_notes')) ?></p>
        <p x-show="conditionDirty()" x-cloak data-testid="blox-cond-dirty" class="mt-1 text-[11px] text-amber-600"><?= e(__('blox_cond_dirty')) ?></p>
        <?php // 原因要具体：优先级越界与"规则没选目标"是两件事，分开说 ?>
        <p x-show="conditionHasProblem('missing_target')" x-cloak data-testid="blox-cond-problems" class="mt-1 text-[11px] text-red-600"><?= e(__('blox_cond_problem_target')) ?></p>
        <p x-show="conditionHasProblem('bad_priority')" x-cloak data-testid="blox-cond-problems-priority" class="mt-1 text-[11px] text-red-600"><?= e(__('blox_cond_problem_priority', ['max' => (string) DetailTemplateResolver::MAX_PRIORITY])) ?></p>
    </div>
</template>
