<?php declare(strict_types=1); ?>
<?php if (!defined('ROOT_PATH')) exit('Access Denied'); ?>
<?php /* 容器 Loop（v1.25）：container/div 挂查询循环。_query 结构由服务端 BloxLoopQuery 归一。 */ ?>
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
                                                <label class="block text-[11px] text-gray-600" x-show="(globalQueries || []).length || loopQueryRefId()">
                                                    <span class="mb-1 block"><?= e(__('blox_gquery_select')) ?></span>
                                                    <select :value="loopQueryRefId()" @change="setLoopQueryRef($event.target.value)"
                                                            data-testid="blox-loop-global-query"
                                                            class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs bg-white">
                                                        <option value=""><?= e(__('blox_gquery_inline')) ?></option>
                                                        <template x-for="gq in (globalQueries || [])" :key="gq.query_id">
                                                            <option :value="gq.query_id" x-text="gq.name"></option>
                                                        </template>
                                                    </select>
                                                </label>
                                                <p x-show="loopQueryRefId()" class="text-[10px] text-gray-400"><?= e(__('blox_gquery_ref_hint')) ?></p>
                                                <div x-show="!loopQueryRefId()" class="space-y-2">
                                                <label class="block text-[11px] text-gray-600">
                                                    <span class="mb-1 block"><?= e(__('blox_dynamic_source')) ?></span>
                                                    <select :value="loopQueryField('source')" @change="setLoopQueryField('source', $event.target.value)"
                                                            data-testid="blox-loop-source"
                                                            class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs bg-white">
                                                        <?php foreach (ListDynamicElement::sourceOptions() as $sourceValue => $sourceLabel): ?>
                                                            <option value="<?= e((string) $sourceValue) ?>"><?= e((string) $sourceLabel) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <label class="block text-[11px] text-gray-600" x-show="String(loopQueryField('source')).indexOf('type:') === 0">
                                                    <span class="mb-1 block"><?= e(__('blox_dynamic_filter')) ?></span>
                                                    <input type="text" :value="loopQueryField('cat')" @change="setLoopQueryField('cat', $event.target.value)"
                                                           class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs">
                                                </label>
                                                <div class="grid grid-cols-2 gap-2">
                                                    <label class="block text-[11px] text-gray-600">
                                                        <span class="mb-1 block"><?= e(__('blox_dynamic_limit')) ?></span>
                                                        <input type="number" min="1" max="50" :value="loopQueryField('limit')"
                                                               @change="setLoopQueryField('limit', $event.target.value)"
                                                               data-testid="blox-loop-limit"
                                                               class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs">
                                                    </label>
                                                    <label class="block text-[11px] text-gray-600">
                                                        <span class="mb-1 block"><?= e(__('blox_dynamic_offset')) ?></span>
                                                        <input type="number" min="0" max="5000" :value="loopQueryField('offset')"
                                                               @change="setLoopQueryField('offset', $event.target.value)"
                                                               class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs">
                                                    </label>
                                                </div>
                                                <label class="block text-[11px] text-gray-600">
                                                    <span class="mb-1 block"><?= e(__('blox_dynamic_keyword')) ?></span>
                                                    <input type="text" :value="loopQueryField('keyword')" @change="setLoopQueryField('keyword', $event.target.value)"
                                                           class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs">
                                                </label>
                                                <div class="flex items-center gap-3 text-[11px] text-gray-600">
                                                    <?php foreach (['recommend' => 'blox_dynamic_recommend', 'hot' => 'blox_dynamic_hot', 'top' => 'blox_dynamic_top'] as $flagKey => $flagLabel): ?>
                                                    <label class="inline-flex items-center gap-1">
                                                        <input type="checkbox" :checked="!!loopQueryField('<?= e($flagKey) ?>')"
                                                               @change="setLoopQueryField('<?= e($flagKey) ?>', $event.target.checked)"
                                                               class="h-3.5 w-3.5 accent-violet-600"><?= e(__($flagLabel)) ?>
                                                    </label>
                                                    <?php endforeach; ?>
                                                </div>
                                                <label class="block text-[11px] text-gray-600" x-show="loopQueryField('source') === 'type:product'">
                                                    <span class="mb-1 block"><?= e(__('blox_dynamic_order')) ?></span>
                                                    <select :value="loopQueryField('order') || 'default'" @change="setLoopQueryField('order', $event.target.value)"
                                                            class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs bg-white">
                                                        <?php foreach (['default' => __('blox_dynamic_order_default'), 'newest' => __('blox_dynamic_order_newest'), 'updated' => __('blox_dynamic_order_updated'), 'views' => __('blox_dynamic_order_views'), 'price_asc' => __('blox_dynamic_order_price_asc'), 'price_desc' => __('blox_dynamic_order_price_desc')] as $orderValue => $orderLabel): ?>
                                                            <option value="<?= e((string) $orderValue) ?>"><?= e((string) $orderLabel) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </label>
                                                <?php /* 自定义字段过滤（v1.25）：AND 平铺 ≤5 条，匹配扩展字段（metas）。
                                                         行内容以服务端 BloxLoopQuery::normalizeFilters 归一为准。 */ ?>
                                                <div class="space-y-1.5" data-testid="blox-loop-filters">
                                                    <div class="flex items-center justify-between">
                                                        <span class="text-[11px] text-gray-600"><?= e(__('blox_loop_filters')) ?></span>
                                                        <button type="button" @click="addLoopQueryFilter()" x-show="loopQueryFilters().length < 5"
                                                                data-testid="blox-loop-filter-add"
                                                                class="text-[11px] text-violet-600 hover:underline">
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
                                                    <p x-show="loopQueryFilters().length" class="text-[10px] text-gray-400"><?= e(__('blox_loop_filters_hint')) ?></p>
                                                </div>
                                                <div class="grid grid-cols-2 gap-2">
                                                    <label class="block text-[11px] text-gray-600">
                                                        <span class="mb-1 block"><?= e(__('blox_dynamic_pagination_mode')) ?></span>
                                                        <select :value="loopQueryField('pagination')" @change="setLoopQueryField('pagination', $event.target.value)"
                                                                data-testid="blox-loop-pagination"
                                                                class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs bg-white">
                                                            <option value="none"><?= e(__('blox_dynamic_pagination_none')) ?></option>
                                                            <option value="numbers"><?= e(__('blox_dynamic_pagination_numbers')) ?></option>
                                                        </select>
                                                    </label>
                                                    <label class="block text-[11px] text-gray-600">
                                                        <span class="mb-1 block"><?= e(__('blox_dynamic_empty_mode')) ?></span>
                                                        <select :value="loopQueryField('empty_mode')" @change="setLoopQueryField('empty_mode', $event.target.value)"
                                                                class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs bg-white">
                                                            <option value="message"><?= e(__('blox_dynamic_empty_message')) ?></option>
                                                            <option value="hidden"><?= e(__('blox_dynamic_empty_hidden')) ?></option>
                                                        </select>
                                                    </label>
                                                </div>
                                                <label class="block text-[11px] text-gray-600" x-show="loopQueryField('empty_mode') !== 'hidden'">
                                                    <span class="mb-1 block"><?= e(__('blox_dynamic_empty')) ?></span>
                                                    <input type="text" :value="loopQueryField('empty')" @change="setLoopQueryField('empty', $event.target.value)"
                                                           placeholder="<?= e(__('blox_dynamic_empty_default')) ?>"
                                                           class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs">
                                                </label>
                                                <button type="button" @click="saveLoopQueryAsGlobal()" data-testid="blox-loop-save-global"
                                                        x-show="canManageDesign"
                                                        class="text-[11px] text-violet-600 hover:underline">
                                                    <i class="ti ti-device-floppy" aria-hidden="true"></i> <?= e(__('blox_gquery_save_as')) ?>
                                                </button>
                                                </div>
                                                <p class="text-[10px] text-gray-400"><?= e(__('blox_loop_hint')) ?></p>
                                            </div>
                                        </template>
                                    </div>
