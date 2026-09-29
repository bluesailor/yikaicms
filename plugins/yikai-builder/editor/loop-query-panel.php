<?php declare(strict_types=1); ?>
<?php if (!defined('ROOT_PATH')) exit('Access Denied'); ?>
<?php /* 容器 Loop（v1.25，2.0.3 对标 Bricks 扩展）：container/div 挂查询循环。
         _query 结构以服务端 BloxQuerySpec 归一为准；这里只负责编辑与联动显示。 */ ?>
<?php
$loopInput = 'w-full border border-gray-200 rounded px-2 py-1.5 text-xs';
$loopSelect = $loopInput . ' bg-white';
$loopLabel = 'block text-[11px] text-gray-600';
// 2.0.3 核心提供分组来源；插件装在更老的核心上时回退到旧的平铺来源（新键会被老核心归一丢弃，无害）
$loopSources = class_exists('BloxLoopQuery') && method_exists('BloxLoopQuery', 'editorSources')
    ? BloxLoopQuery::editorSources()
    : [['label' => __('blox_dynamic_source'), 'options' => ['current' => __('blox_loop_source_current')] + ListDynamicElement::sourceOptions()]];
?>
                                    <div x-show="selEl && (selEl.type === 'container' || selEl.type === 'div') && professionalFeatures['query_loop'] && professionalFeatures['query_loop'].allowed"
                                         class="pb-3 border-b border-gray-200" data-testid="blox-loop-query-panel">
                                        <label class="flex items-center justify-between gap-2">
                                            <span class="text-xs font-semibold text-gray-600 inline-flex items-center gap-1.5">
                                                <i class="ti ti-repeat text-sm text-violet-500"></i><?= e(__('blox_loop_panel_title')) ?>
                                            </span>
                                            <input type="checkbox" :checked="loopQueryEnabled()"
                                                   @change="toggleLoopQuery($event.target.checked)"
                                                   data-testid="blox-loop-toggle" class="h-4 w-4 accent-violet-600">
                                        </label>
                                        <template x-if="loopQueryEnabled()">
                                            <div class="mt-2 space-y-2" data-testid="blox-loop-settings">
                                                <?php /* 全局查询引用（v1.25）：选中即 {ref}，一处修改全站生效；切回空值物化为内联可编辑 */ ?>
                                                <label class="<?= $loopLabel ?>" x-show="(globalQueries || []).length || loopQueryRefId()">
                                                    <span class="mb-1 block"><?= e(__('blox_gquery_select')) ?></span>
                                                    <select :value="loopQueryRefId()" @change="setLoopQueryRef($event.target.value)"
                                                            data-testid="blox-loop-global-query" class="<?= $loopSelect ?>">
                                                        <option value=""><?= e(__('blox_gquery_inline')) ?></option>
                                                        <template x-for="gq in (globalQueries || [])" :key="gq.query_id">
                                                            <option :value="gq.query_id" x-text="gq.name"></option>
                                                        </template>
                                                    </select>
                                                </label>
                                                <p x-show="loopQueryRefId()" class="text-[10px] text-gray-400"><?= e(__('blox_gquery_ref_hint')) ?></p>
                                                <?php /* 全局查询管理（2.0.3）：用量 / 就地编辑 / 重命名 / 删除 */ ?>
                                                <div x-show="loopQueryRefId() && canManageDesign" class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px]" data-testid="blox-loop-global-manage">
                                                    <button type="button" @click="editLoopGlobalQuery()" class="text-violet-600 hover:underline" data-testid="blox-loop-global-edit">
                                                        <i class="ti ti-pencil" aria-hidden="true"></i> <?= e(__('blox_gquery_edit')) ?>
                                                    </button>
                                                    <button type="button" @click="renameLoopGlobalQuery()" class="text-gray-600 hover:underline"><?= e(__('blox_gquery_rename')) ?></button>
                                                    <button type="button" @click="deleteLoopGlobalQuery()" class="text-red-600 hover:underline"><?= e(__('blox_gquery_delete')) ?></button>
                                                    <button type="button" x-show="!globalQueryUsage" @click="loadGlobalQueryUsage()" class="text-gray-500 hover:underline"><?= e(__('blox_gquery_show_usage')) ?></button>
                                                    <span x-show="globalQueryUsage" class="text-gray-500" x-text="loopGlobalUsageText()"></span>
                                                </div>
                                                <div x-show="loopGlobalEditingActive()" class="rounded border border-violet-200 bg-violet-50 px-2 py-1.5 text-[11px] text-violet-800" data-testid="blox-loop-global-editing">
                                                    <p x-text="String(loopText.editingGlobal || '').replace(':name', (loopGlobalQuery() || {}).name || '')"></p>
                                                    <div class="mt-1 flex gap-3">
                                                        <button type="button" @click="saveLoopGlobalQuery()" class="font-medium text-violet-700 hover:underline" data-testid="blox-loop-global-save"><?= e(__('blox_gquery_save_global')) ?></button>
                                                        <button type="button" @click="cancelLoopGlobalQuery()" class="text-gray-600 hover:underline"><?= e(__('blox_gquery_cancel_edit')) ?></button>
                                                    </div>
                                                </div>
                                                <div x-show="!loopQueryRefId()" class="space-y-2">

                                                <?php /* ── 来源 ── */ ?>
                                                <label class="<?= $loopLabel ?>">
                                                    <span class="mb-1 block"><?= e(__('blox_dynamic_source')) ?></span>
                                                    <select :value="loopQueryField('source')" @change="setLoopQueryField('source', $event.target.value)"
                                                            data-testid="blox-loop-source" class="<?= $loopSelect ?>">
                                                        <?php foreach ($loopSources as $sourceGroup): ?>
                                                            <optgroup label="<?= e((string) $sourceGroup['label']) ?>">
                                                                <?php foreach ($sourceGroup['options'] as $sourceValue => $sourceLabel): ?>
                                                                    <option value="<?= e((string) $sourceValue) ?>"><?= e((string) $sourceLabel) ?></option>
                                                                <?php endforeach; ?>
                                                            </optgroup>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <p x-show="loopQueryField('source') === 'current'" class="text-[10px] text-gray-400"><?= e(__('blox_loop_source_current_hint')) ?></p>
                                                <p x-show="loopQueryKind() === 'term'" class="text-[10px] text-gray-400"><?= e(__('blox_query_terms_hint')) ?></p>

                                                <?php /* ── 分类（多选包含 / 排除 / 含子类）：内容/产品/下载与分类循环 ── */ ?>
                                                <template x-if="loopQueryTaxonomy() && loopQueryField('source') !== 'current' && String(loopQueryField('source')).indexOf('channel:') !== 0">
                                                    <div class="space-y-2" data-testid="blox-loop-terms">
                                                        <template x-if="loopQueryKind() === 'term' && loopQueryTaxonomy() !== 'download'">
                                                            <label class="<?= $loopLabel ?>">
                                                                <span class="mb-1 block"><?= e(__('blox_query_parent_term')) ?></span>
                                                                <select :value="loopQueryField('parent_term')" @change="setLoopQueryField('parent_term', $event.target.value)"
                                                                        data-testid="blox-loop-parent-term" class="<?= $loopSelect ?>">
                                                                    <option value=""><?= e(__('blox_query_parent_any')) ?></option>
                                                                    <option value="0"><?= e(__('blox_query_parent_top')) ?></option>
                                                                    <template x-for="term in loopTermOptions()" :key="term.value">
                                                                        <option :value="String(term.value)" x-text="term.label"></option>
                                                                    </template>
                                                                </select>
                                                            </label>
                                                        </template>
                                                        <?php foreach (['cats' => 'blox_query_cats', 'cats_exclude' => 'blox_query_cats_exclude'] as $catKey => $catLabel): ?>
                                                        <details class="rounded border border-gray-200" :open="loopQueryList('<?= e($catKey) ?>').length > 0">
                                                            <summary class="flex cursor-pointer items-center justify-between px-2 py-1.5 text-[11px] text-gray-600">
                                                                <span><?= e(__($catLabel)) ?></span>
                                                                <span class="text-[10px] text-violet-600" x-show="loopQueryList('<?= e($catKey) ?>').length"
                                                                      x-text="loopQueryList('<?= e($catKey) ?>').length"></span>
                                                            </summary>
                                                            <div class="max-h-40 overflow-y-auto border-t border-gray-100 px-2 py-1.5 space-y-1" data-testid="blox-loop-<?= e(str_replace('_', '-', $catKey)) ?>">
                                                                <template x-for="term in loopTermOptions()" :key="term.value">
                                                                    <label class="flex items-center gap-1.5 text-[11px] text-gray-700">
                                                                        <input type="checkbox" class="h-3.5 w-3.5 accent-violet-600"
                                                                               :checked="loopQueryList('<?= e($catKey) ?>').indexOf(String(term.value)) !== -1"
                                                                               @change="toggleLoopQueryListItem('<?= e($catKey) ?>', String(term.value), $event.target.checked)">
                                                                        <span class="truncate" x-text="term.label"></span>
                                                                    </label>
                                                                </template>
                                                                <p x-show="!loopTermOptions().length" class="text-[10px] text-gray-400"><?= e(__('blox_query_no_terms')) ?></p>
                                                            </div>
                                                        </details>
                                                        <?php endforeach; ?>
                                                        <p x-show="loopQueryField('cat')" class="text-[10px] text-amber-600">
                                                            <?= e(__('blox_query_legacy_cat')) ?> <code x-text="loopQueryField('cat')"></code>
                                                        </p>
                                                        <label class="flex items-center gap-1.5 text-[11px] text-gray-600" x-show="loopQueryKind() !== 'term' && loopQueryTaxonomy() !== 'download'">
                                                            <input type="checkbox" class="h-3.5 w-3.5 accent-violet-600" :checked="loopQueryChildren()"
                                                                   @change="setLoopQueryField('children', $event.target.checked)" data-testid="blox-loop-children">
                                                            <?= e(__('blox_query_children')) ?>
                                                        </label>
                                                        <label class="flex items-center gap-1.5 text-[11px] text-gray-600" x-show="loopQueryKind() === 'term'">
                                                            <input type="checkbox" class="h-3.5 w-3.5 accent-violet-600" :checked="!!loopQueryField('hide_empty')"
                                                                   @change="setLoopQueryField('hide_empty', $event.target.checked)">
                                                            <?= e(__('blox_query_hide_empty')) ?>
                                                        </label>
                                                    </div>
                                                </template>

                                                <?php /* ── 上下文范围：相关内容 / 嵌套外层 ── */ ?>
                                                <label class="<?= $loopLabel ?>" x-show="loopQueryField('source') !== 'current'">
                                                    <span class="mb-1 block"><?= e(__('blox_query_scope')) ?></span>
                                                    <select :value="loopQueryField('scope')" @change="setLoopQueryField('scope', $event.target.value)"
                                                            data-testid="blox-loop-scope" class="<?= $loopSelect ?>">
                                                        <option value=""><?= e(__('blox_query_scope_none')) ?></option>
                                                        <option value="related" x-show="loopQueryKind() !== 'term'"><?= e(__('blox_query_scope_related')) ?></option>
                                                        <option value="parent"><?= e(__('blox_query_scope_parent')) ?></option>
                                                    </select>
                                                </label>
                                                <p x-show="loopQueryField('scope')" class="text-[10px] text-gray-400"
                                                   x-text="loopQueryField('scope') === 'related' ? loopText.scopeRelatedHint : loopText.scopeParentHint"></p>

                                                <div class="grid grid-cols-2 gap-2">
                                                    <label class="<?= $loopLabel ?>">
                                                        <span class="mb-1 block"><?= e(__('blox_dynamic_limit')) ?></span>
                                                        <input type="number" min="1" max="50" :value="loopQueryField('limit')"
                                                               @change="setLoopQueryField('limit', $event.target.value)"
                                                               data-testid="blox-loop-limit" class="<?= $loopInput ?>">
                                                    </label>
                                                    <label class="<?= $loopLabel ?>">
                                                        <span class="mb-1 block"><?= e(__('blox_dynamic_offset')) ?></span>
                                                        <input type="number" min="0" max="5000" :value="loopQueryField('offset')"
                                                               @change="setLoopQueryField('offset', $event.target.value)" class="<?= $loopInput ?>">
                                                    </label>
                                                </div>

                                                <?php /* ── 排序 ── */ ?>
                                                <label class="<?= $loopLabel ?>">
                                                    <span class="mb-1 block"><?= e(__('blox_dynamic_order')) ?></span>
                                                    <select :value="loopQueryField('order') || 'default'" @change="setLoopQueryField('order', $event.target.value)"
                                                            data-testid="blox-loop-order" class="<?= $loopSelect ?>">
                                                        <template x-for="option in loopQueryOrderOptions()" :key="option.value">
                                                            <option :value="option.value" x-text="option.label" :selected="(loopQueryField('order') || 'default') === option.value"></option>
                                                        </template>
                                                    </select>
                                                </label>
                                                <p x-show="loopQueryField('order') === 'random'" class="text-[10px] text-gray-400"><?= e(__('blox_query_random_hint')) ?></p>

                                                <?php /* ── 条目筛选（分类循环不适用） ── */ ?>
                                                <template x-if="loopQueryKind() !== 'term'">
                                                    <div class="space-y-2">
                                                        <label class="<?= $loopLabel ?>">
                                                            <span class="mb-1 block"><?= e(__('blox_dynamic_keyword')) ?></span>
                                                            <input type="text" :value="loopQueryField('keyword')" @change="setLoopQueryField('keyword', $event.target.value)" class="<?= $loopInput ?>">
                                                        </label>
                                                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-gray-600">
                                                            <?php foreach (['recommend' => 'blox_dynamic_recommend', 'hot' => 'blox_dynamic_hot', 'top' => 'blox_dynamic_top'] as $flagKey => $flagLabel): ?>
                                                            <label class="inline-flex items-center gap-1" x-show="loopQueryKind() !== 'download' && (loopQueryKind() !== 'job' || '<?= e($flagKey) ?>' === 'top')">
                                                                <input type="checkbox" :checked="!!loopQueryField('<?= e($flagKey) ?>')"
                                                                       @change="setLoopQueryField('<?= e($flagKey) ?>', $event.target.checked)"
                                                                       class="h-3.5 w-3.5 accent-violet-600"><?= e(__($flagLabel)) ?>
                                                            </label>
                                                            <?php endforeach; ?>
                                                            <label class="inline-flex items-center gap-1" x-show="loopQueryKind() === 'product'">
                                                                <input type="checkbox" :checked="!!loopQueryField('new')" @change="setLoopQueryField('new', $event.target.checked)"
                                                                       class="h-3.5 w-3.5 accent-violet-600"><?= e(__('blox_query_flag_new')) ?>
                                                            </label>
                                                        </div>
                                                        <div class="grid grid-cols-2 gap-2">
                                                            <label class="<?= $loopLabel ?>">
                                                                <span class="mb-1 block"><?= e(__('blox_query_ids')) ?></span>
                                                                <input type="text" :value="loopQueryIds('ids')" @change="setLoopQueryField('ids', $event.target.value)"
                                                                       placeholder="3, 7, 9" data-testid="blox-loop-ids" class="<?= $loopInput ?>">
                                                            </label>
                                                            <label class="<?= $loopLabel ?>">
                                                                <span class="mb-1 block"><?= e(__('blox_query_exclude_ids')) ?></span>
                                                                <input type="text" :value="loopQueryIds('exclude_ids')" @change="setLoopQueryField('exclude_ids', $event.target.value)"
                                                                       placeholder="12, 15" class="<?= $loopInput ?>">
                                                            </label>
                                                        </div>
                                                        <label class="flex items-center gap-1.5 text-[11px] text-gray-600">
                                                            <input type="checkbox" class="h-3.5 w-3.5 accent-violet-600" :checked="!!loopQueryField('exclude_current')"
                                                                   @change="setLoopQueryField('exclude_current', $event.target.checked)" data-testid="blox-loop-exclude-current">
                                                            <?= e(__('blox_query_exclude_current')) ?>
                                                        </label>
                                                        <label class="<?= $loopLabel ?>">
                                                            <span class="mb-1 block"><?= e(__('blox_query_date')) ?></span>
                                                            <select :value="String(loopQueryField('date_within') || '')" @change="setLoopQueryField('date_within', $event.target.value)"
                                                                    data-testid="blox-loop-date-within" class="<?= $loopSelect ?>">
                                                                <option value=""><?= e(__('blox_query_date_any')) ?></option>
                                                                <?php foreach ([7, 30, 90, 180, 365] as $days): ?>
                                                                    <option value="<?= $days ?>"><?= e(str_replace(':days', (string) $days, __('blox_query_date_within'))) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </label>
                                                        <div class="grid grid-cols-2 gap-2">
                                                            <label class="<?= $loopLabel ?>">
                                                                <span class="mb-1 block"><?= e(__('blox_query_date_from')) ?></span>
                                                                <input type="date" :value="loopQueryField('date_from')" @change="setLoopQueryField('date_from', $event.target.value)" class="<?= $loopInput ?>">
                                                            </label>
                                                            <label class="<?= $loopLabel ?>">
                                                                <span class="mb-1 block"><?= e(__('blox_query_date_to')) ?></span>
                                                                <input type="date" :value="loopQueryField('date_to')" @change="setLoopQueryField('date_to', $event.target.value)" class="<?= $loopInput ?>">
                                                            </label>
                                                        </div>
                                                        <div class="grid grid-cols-2 gap-2" x-show="loopQueryKind() === 'product'">
                                                            <label class="<?= $loopLabel ?>">
                                                                <span class="mb-1 block"><?= e(__('blox_query_price_min')) ?></span>
                                                                <input type="number" min="0" step="0.01" :value="loopQueryField('price_min')" @change="setLoopQueryField('price_min', $event.target.value)" class="<?= $loopInput ?>">
                                                            </label>
                                                            <label class="<?= $loopLabel ?>">
                                                                <span class="mb-1 block"><?= e(__('blox_query_price_max')) ?></span>
                                                                <input type="number" min="0" step="0.01" :value="loopQueryField('price_max')" @change="setLoopQueryField('price_max', $event.target.value)" class="<?= $loopInput ?>">
                                                            </label>
                                                        </div>

                                                        <?php /* 自定义字段过滤（v1.25，2.0.3 起可选「满足任一」）：≤5 条，匹配扩展字段（metas） */ ?>
                                                        <div class="space-y-1.5" data-testid="blox-loop-filters" x-show="loopQueryKind() === 'content' || loopQueryKind() === 'product' || loopQueryField('source') === 'current'">
                                                            <div class="flex items-center justify-between">
                                                                <span class="text-[11px] text-gray-600"><?= e(__('blox_loop_filters')) ?></span>
                                                                <button type="button" @click="addLoopQueryFilter()" x-show="loopQueryFilters().length < 5"
                                                                        data-testid="blox-loop-filter-add" class="text-[11px] text-violet-600 hover:underline">
                                                                    <i class="ti ti-plus" aria-hidden="true"></i> <?= e(__('blox_loop_filter_add')) ?>
                                                                </button>
                                                            </div>
                                                            <template x-for="(loopFilter, loopFilterIndex) in loopQueryFilters()" :key="loopFilterIndex">
                                                                <div class="flex items-center gap-1" data-testid="blox-loop-filter-row">
                                                                    <input type="text" :value="loopFilter.field" placeholder="<?= e(__('blox_loop_filter_field')) ?>"
                                                                           @change="setLoopQueryFilter(loopFilterIndex, 'field', $event.target.value)"
                                                                           class="w-24 border border-gray-200 rounded px-1.5 py-1 text-[11px]">
                                                                    <select :value="loopFilter.op" @change="setLoopQueryFilter(loopFilterIndex, 'op', $event.target.value)"
                                                                            class="border border-gray-200 rounded px-1 py-1 text-[11px] bg-white">
                                                                        <?php foreach (['=' => '=', '!=' => '≠', '>' => '>', '>=' => '≥', '<' => '<', '<=' => '≤',
                                                                            'like' => __('blox_loop_op_like'), 'in' => __('blox_loop_op_in'),
                                                                            'between' => __('blox_loop_op_between'), 'empty' => __('blox_loop_op_empty')] as $opValue => $opLabel): ?>
                                                                            <option value="<?= e((string) $opValue) ?>"><?= e((string) $opLabel) ?></option>
                                                                        <?php endforeach; ?>
                                                                    </select>
                                                                    <input type="text" x-show="loopFilter.op !== 'empty'" :value="loopFilter.value"
                                                                           placeholder="<?= e(__('blox_loop_filter_value')) ?>"
                                                                           @change="setLoopQueryFilter(loopFilterIndex, 'value', $event.target.value)"
                                                                           class="flex-1 min-w-0 border border-gray-200 rounded px-1.5 py-1 text-[11px]">
                                                                    <button type="button" @click="removeLoopQueryFilter(loopFilterIndex)"
                                                                            class="text-gray-400 hover:text-red-500" aria-label="remove">
                                                                        <i class="ti ti-x text-xs" aria-hidden="true"></i>
                                                                    </button>
                                                                </div>
                                                            </template>
                                                            <label class="flex items-center gap-1.5 text-[11px] text-gray-600" x-show="loopQueryFilters().length > 1">
                                                                <input type="checkbox" class="h-3.5 w-3.5 accent-violet-600" :checked="loopQueryField('filter_relation') === 'or'"
                                                                       @change="setLoopQueryField('filter_relation', $event.target.checked ? 'or' : '')" data-testid="blox-loop-filter-or">
                                                                <?= e(__('blox_query_filter_or')) ?>
                                                            </label>
                                                            <p x-show="loopQueryFilters().length" class="text-[10px] text-gray-400"><?= e(__('blox_loop_filters_hint')) ?></p>
                                                        </div>
                                                    </div>
                                                </template>

                                                <?php /* ── 分页与空结果 ── */ ?>
                                                <div class="grid grid-cols-2 gap-2">
                                                    <label class="<?= $loopLabel ?>">
                                                        <span class="mb-1 block"><?= e(__('blox_dynamic_pagination_mode')) ?></span>
                                                        <select :value="loopQueryField('pagination')" @change="setLoopQueryField('pagination', $event.target.value)"
                                                                :disabled="loopQueryField('order') === 'random' || loopQueryField('scope') === 'parent'"
                                                                data-testid="blox-loop-pagination" class="<?= $loopSelect ?>">
                                                            <option value="none"><?= e(__('blox_dynamic_pagination_none')) ?></option>
                                                            <option value="numbers"><?= e(__('blox_dynamic_pagination_numbers')) ?></option>
                                                            <option value="ajax"><?= e(__('blox_query_pagination_ajax')) ?></option>
                                                            <option value="load_more"><?= e(__('blox_query_pagination_load_more')) ?></option>
                                                            <option value="infinite"><?= e(__('blox_query_pagination_infinite')) ?></option>
                                                        </select>
                                                    </label>
                                                    <label class="<?= $loopLabel ?>">
                                                        <span class="mb-1 block"><?= e(__('blox_dynamic_empty_mode')) ?></span>
                                                        <select :value="loopQueryField('empty_mode')" @change="setLoopQueryField('empty_mode', $event.target.value)" class="<?= $loopSelect ?>">
                                                            <option value="message"><?= e(__('blox_dynamic_empty_message')) ?></option>
                                                            <option value="hidden"><?= e(__('blox_dynamic_empty_hidden')) ?></option>
                                                        </select>
                                                    </label>
                                                </div>
                                                <label class="<?= $loopLabel ?>" x-show="loopQueryField('pagination') === 'load_more'">
                                                    <span class="mb-1 block"><?= e(__('blox_query_load_more_text')) ?></span>
                                                    <input type="text" maxlength="40" :value="loopQueryField('load_more_text')" @change="setLoopQueryField('load_more_text', $event.target.value)"
                                                           placeholder="<?= e(__('blox_query_load_more_default')) ?>" class="<?= $loopInput ?>">
                                                </label>
                                                <label class="<?= $loopLabel ?>" x-show="loopQueryField('empty_mode') !== 'hidden'">
                                                    <span class="mb-1 block"><?= e(__('blox_dynamic_empty')) ?></span>
                                                    <input type="text" :value="loopQueryField('empty')" @change="setLoopQueryField('empty', $event.target.value)"
                                                           placeholder="<?= e(__('blox_dynamic_empty_default')) ?>" class="<?= $loopInput ?>">
                                                </label>
                                                <button type="button" @click="saveLoopQueryAsGlobal()" data-testid="blox-loop-save-global"
                                                        x-show="canManageDesign && !loopGlobalEditingActive()" class="text-[11px] text-violet-600 hover:underline">
                                                    <i class="ti ti-device-floppy" aria-hidden="true"></i> <?= e(__('blox_gquery_save_as')) ?>
                                                </button>
                                                </div>
                                                <p class="text-[10px] text-gray-400"><?= e(__('blox_loop_hint')) ?></p>
                                            </div>
                                        </template>
                                    </div>
