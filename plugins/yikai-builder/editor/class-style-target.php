<?php declare(strict_types=1); ?>
<?php if (!defined('ROOT_PATH')) exit('Access Denied'); ?>
<?php
/**
 * V2.0.0 样式页签的编辑目标：此元素 / 已挂载的全局类。
 * 选中类时，核心的元素样式整体让位（elementStyleTab() 为假），这里的类表单读写该类 settings，
 * 经 blox_class_api 保存（revision 乐观并发、CSRF、blox_global 权限、作者端授权）。
 * 类修改是全站草稿，不进入页面撤销历史；挂类/摘类改 selEl.data._classes，随页面保存、可撤销。
 */
?>
<template x-if="selEl && panelTab === 'style' && globalClassesEnabled && supportsBoxStyles(selEl.type)">
    <div class="space-y-3" data-testid="blox-style-target">
        <div class="rounded border border-sky-200 bg-sky-50 p-2 space-y-2">
            <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-semibold text-gray-600 inline-flex items-center gap-1.5">
                    <i class="ti ti-tags text-sm text-sky-500"></i><?= e(__('blox_class_target')) ?>
                </span>
                <span x-show="!classStyleTarget() && elementClassIds().length" class="text-[10px] text-gray-400"><?= e(__('blox_global_classes')) ?></span>
            </div>
            <div class="flex flex-wrap items-center gap-1" role="group" aria-label="<?= e(__('blox_class_target')) ?>">
                <button type="button" @click="setStyleTarget('')" data-testid="blox-style-target-element"
                        :aria-pressed="classStyleTarget() ? 'false' : 'true'"
                        class="rounded border px-2 py-1 text-[11px] transition"
                        :class="classStyleTarget() ? 'border-gray-200 bg-white text-gray-500 hover:text-gray-700' : 'border-blue-500 bg-blue-600 text-white'">
                    <?= e(__('blox_class_target_element')) ?>
                </button>
                <template x-for="cid in elementClassIds()" :key="cid">
                    <span class="inline-flex items-center rounded border text-[11px] transition"
                          :class="classStyleTarget() === cid ? 'border-sky-600 bg-sky-600 text-white' : 'border-sky-200 bg-white text-sky-700'">
                        <button type="button" @click="setStyleTarget(cid)" :data-testid="'blox-style-target-class-' + globalClassLabel(cid)"
                                :aria-pressed="classStyleTarget() === cid ? 'true' : 'false'"
                                class="pl-2 pr-1 py-1 font-mono" x-text="'.yk-c-' + globalClassLabel(cid)"></button>
                        <span x-show="classDraftPending(cid)" class="w-1.5 h-1.5 rounded-full bg-amber-400" title="<?= e(__('blox_class_pending')) ?>"></span>
                        <button type="button" @click="removeElementClass(cid)" data-testid="blox-style-target-remove"
                                class="px-1 py-1 opacity-60 hover:opacity-100" title="<?= e(__('blox_class_remove')) ?>" aria-label="<?= e(__('blox_class_remove')) ?>">
                            <i class="ti ti-x text-[10px]"></i>
                        </button>
                    </span>
                </template>
                <button type="button" @click="classAddOpen = !classAddOpen; classQuery = ''" x-show="elementClassIds().length < 8"
                        data-testid="blox-style-target-add" :aria-expanded="classAddOpen ? 'true' : 'false'"
                        class="rounded border border-dashed border-sky-300 bg-white px-2 py-1 text-[11px] text-sky-700 hover:border-sky-500">
                    <i class="ti ti-plus text-[10px]"></i> <?= e(__('blox_class_add')) ?>
                </button>
            </div>
            <p x-show="elementClassIds().length >= 8" class="text-[10px] text-amber-700"><?= e(__('blox_class_element_limit', ['max' => 8])) ?></p>
            <div x-show="classAddOpen" class="space-y-1" data-testid="blox-style-target-finder">
                <input type="text" x-model="classQuery" maxlength="48" data-testid="blox-class-find"
                       @keydown.enter.prevent="classFinderEnter()" @keydown.escape.prevent="classAddOpen = false"
                       placeholder="<?= e(__('blox_class_find_placeholder')) ?>"
                       class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs font-mono bg-white">
                <div class="max-h-40 overflow-y-auto rounded border border-gray-100 bg-white">
                    <template x-for="cls in classFinderMatches()" :key="cls.class_id">
                        <button type="button" @click="attachClassFromFinder(cls.class_id)"
                                class="block w-full text-left px-2 py-1 text-xs font-mono text-gray-700 hover:bg-sky-50"
                                x-text="'.yk-c-' + cls.name"></button>
                    </template>
                    <button type="button" x-show="classFinderCanCreate()" @click="createGlobalClass(classQuery)" data-testid="blox-class-create"
                            class="block w-full text-left px-2 py-1 text-xs text-sky-700 hover:bg-sky-50"
                            x-text="classText.createNamed.replace(':name', classQueryName())"></button>
                    <p x-show="!classFinderMatches().length && !classFinderCanCreate()" class="px-2 py-1 text-[11px] text-gray-400"><?= e(__('blox_class_no_match')) ?></p>
                </div>
            </div>
            <template x-for="pending in pendingClassDrafts().filter(p => p.class_id !== classStyleTarget())" :key="'pending-' + pending.class_id">
                <div class="flex items-center justify-between gap-1 rounded bg-amber-50 border border-amber-200 px-2 py-1 text-[11px] text-amber-800">
                    <span><?= e(__('blox_class_pending')) ?> <span class="font-mono" x-text="'.yk-c-' + pending.name"></span></span>
                    <span class="flex gap-1 shrink-0">
                        <button type="button" @click="saveClassDraft(pending.class_id)" class="underline"><?= e(__('blox_class_save')) ?></button>
                        <button type="button" @click="discardClassDraft(pending.class_id)" class="underline"><?= e(__('blox_class_discard')) ?></button>
                    </span>
                </div>
            </template>
            <p x-show="!classStyleTarget()" class="text-[10px] leading-relaxed text-gray-500"><?= e(__('blox_class_target_hint')) ?></p>
        </div>

        <template x-if="classStyleTarget()">
            <div class="space-y-3" data-testid="blox-class-style-form">
                <template x-if="!globalClassRow(classStyleTarget())">
                    <p class="text-xs text-amber-700"><?= e(__('blox_class_missing')) ?></p>
                </template>
                <template x-if="globalClassRow(classStyleTarget())">
                    <div class="space-y-3">
                        <div class="rounded border border-gray-200 bg-gray-50 p-2 space-y-1">
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-xs font-semibold font-mono text-sky-700" x-text="'.yk-c-' + globalClassLabel(classStyleTarget())"></span>
                                <span class="text-[10px] text-gray-400" data-testid="blox-class-usage" x-text="classUsageText(classStyleTarget())"></span>
                            </div>
                            <p class="text-[10px] leading-relaxed text-gray-500"><?= e(__('blox_class_sitewide_note')) ?></p>
                            <p x-show="!canManageDesign" class="text-[10px] text-amber-700" data-testid="blox-class-readonly"><?= e(__('blox_class_readonly')) ?></p>
                            <p x-show="!classState" class="text-[10px] text-gray-500" x-text="classText.editingTier.replace(':device', responsiveDeviceRangeLabel(previewDevice))"></p>
                        </div>

                        <?php // 交互状态：基础 / 悬停 / 键盘聚焦。状态只存不分档的颜色、边框、圆角、字重 ?>
                        <div x-show="classStates.length" class="space-y-1" data-testid="blox-class-states">
                            <div class="grid gap-1" :class="'grid-cols-' + (classStates.length + 1)" role="group" aria-label="<?= e(__('blox_class_states')) ?>">
                                <button type="button" @click="setClassState('')" data-testid="blox-class-state-base"
                                        :aria-pressed="classState ? 'false' : 'true'"
                                        class="rounded border px-2 py-1 text-[11px] transition"
                                        :class="classState ? 'border-gray-200 bg-white text-gray-500 hover:text-gray-700' : 'border-sky-600 bg-sky-600 text-white'"><?= e(__('blox_class_state_base')) ?></button>
                                <template x-for="state in classStates" :key="'state-' + state.key">
                                    <button type="button" @click="setClassState(state.key)" :data-testid="'blox-class-state-' + state.key"
                                            :aria-pressed="classState === state.key ? 'true' : 'false'"
                                            class="rounded border px-2 py-1 text-[11px] transition"
                                            :class="classState === state.key ? 'border-sky-600 bg-sky-600 text-white' : 'border-gray-200 bg-white text-gray-500 hover:text-gray-700'"
                                            x-text="state.label"></button>
                                </template>
                            </div>
                            <p x-show="classState" class="text-[10px] leading-relaxed text-gray-500"><?= e(__('blox_class_state_hint')) ?></p>
                        </div>

                        <template x-for="conflict in classConflictList()" :key="'conflict-' + conflict.key">
                            <div class="flex items-start justify-between gap-2 rounded border border-amber-200 bg-amber-50 px-2 py-1.5 text-[11px] text-amber-800" data-testid="blox-class-conflict" :data-conflict-key="conflict.key" :data-conflict-by="conflict.by">
                                <span><span class="font-medium" x-text="classFieldLabel(conflict.key)"></span>：<span x-text="classConflictText(conflict)"></span></span>
                                <button type="button" x-show="conflict.by === 'element'" @click="clearClassLocalOverride(conflict)"
                                        data-testid="blox-class-clear-local" class="shrink-0 underline"><?= e(__('blox_class_clear_local')) ?></button>
                            </div>
                        </template>

                        <template x-for="group in classFieldGroups()" :key="'class-group-' + group.key">
                            <div class="rounded border border-gray-200 p-2 space-y-2" :data-class-group="group.key">
                                <div class="text-xs font-semibold text-gray-600" x-text="group.label"></div>
                                <div class="grid grid-cols-2 gap-2">
                                    <template x-for="field in group.fields" :key="'class-field-' + field.key">
                                        <label class="block min-w-0" :class="field.type === 'color' || field.type === 'enum' ? 'col-span-2' : ''" :data-class-field="field.key">
                                            <span class="flex items-center justify-between gap-1 text-[11px] text-gray-500 mb-0.5">
                                                <span class="truncate" :title="field.applies === 'flex' ? classText.flexOnly : ''">
                                                    <span x-text="field.label"></span><span x-show="field.applies === 'flex'" class="text-gray-300">*</span>
                                                </span>
                                                <button type="button" x-show="classFieldOwn(field) && canManageDesign" @click.prevent="setClassField(field, '')"
                                                        class="text-gray-400 hover:text-red-500" :title="classText.clear" :aria-label="classText.clear">
                                                    <i class="ti ti-x text-[10px]"></i>
                                                </button>
                                            </span>
                                            <template x-if="field.type === 'color'">
                                                <span class="flex items-center gap-1">
                                                    <input type="color" :value="classColorSwatch(field)" :disabled="!canManageDesign"
                                                           :class="classFieldValue(field) ? '' : 'opacity-25'"
                                                           @input="setClassField(field, $event.target.value)"
                                                           class="h-7 w-8 shrink-0 rounded border border-gray-200 bg-white p-0.5">
                                                    <input type="text" :value="classFieldValue(field)" :disabled="!canManageDesign"
                                                           @change="setClassField(field, $event.target.value)" placeholder="#000000"
                                                           :data-testid="'blox-class-input-' + field.key"
                                                           class="flex-1 min-w-0 border border-gray-200 rounded px-2 py-1 text-xs font-mono">
                                                </span>
                                            </template>
                                            <template x-if="field.type === 'enum'">
                                                <select :value="classFieldValue(field)" :disabled="!canManageDesign"
                                                        @change="setClassField(field, $event.target.value)"
                                                        :data-testid="'blox-class-input-' + field.key"
                                                        class="w-full border border-gray-200 rounded px-2 py-1 text-xs bg-white">
                                                    <option value="">—</option>
                                                    <template x-for="option in field.options" :key="field.key + option.value">
                                                        <option :value="option.value" x-text="option.label" :selected="String(classFieldValue(field)) === option.value"></option>
                                                    </template>
                                                </select>
                                            </template>
                                            <template x-if="field.type !== 'color' && field.type !== 'enum'">
                                                <span class="flex items-center rounded border bg-white"
                                                      :class="classFieldOwn(field) ? 'border-sky-300' : 'border-gray-200'">
                                                    <input type="number" :min="field.min" :max="field.max" :step="field.step"
                                                           :value="classFieldValue(field)" :placeholder="classFieldPlaceholder(field)"
                                                           :disabled="!canManageDesign"
                                                           @change="setClassField(field, $event.target.value)"
                                                           :data-testid="'blox-class-input-' + field.key"
                                                           class="w-full min-w-0 rounded border-0 px-2 py-1 text-xs">
                                                    <span class="px-1.5 text-[10px] text-gray-400" x-text="field.unit"></span>
                                                </span>
                                            </template>
                                        </label>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <div class="flex items-center gap-2" x-show="canManageDesign">
                            <button type="button" @click="saveClassDraft(classStyleTarget())" data-testid="blox-class-save"
                                    :disabled="!classDraftPending(classStyleTarget()) || classSaving"
                                    class="flex-1 rounded bg-sky-600 px-2 py-1.5 text-xs text-white hover:bg-sky-700 disabled:opacity-40 disabled:cursor-not-allowed"
                                    x-text="classSaving ? classText.saving : classText.save"></button>
                            <button type="button" @click="discardClassDraft(classStyleTarget())" data-testid="blox-class-discard"
                                    :disabled="!classDraftPending(classStyleTarget()) || classSaving"
                                    class="rounded border border-gray-200 px-2 py-1.5 text-xs text-gray-600 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed"><?= e(__('blox_class_discard')) ?></button>
                        </div>
                    </div>
                </template>
            </div>
        </template>
    </div>
</template>
