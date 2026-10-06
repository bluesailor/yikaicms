<?php
/**
 * YIKAI_BLOX_AI_ACCESS_NOTICE
 * AI-assisted reading or modification requires explicit task-scoped authorization.
 * Policy: docs/blox-commercialization/CORE-ACCESS.md
 * This collaboration notice is not access control and does not replace licenses.
 */
/* 组件实例面板（v2.1）：属性覆盖 / 重置、循环自动绑定、编辑母版、脱离；以及「存为组件」。逻辑在 assets/js/yikay-components.js */
?>
<template x-if="selEl && selEl.type === 'component' && panelTab === 'content' && !ctrlQuery.trim() && !modifiedOnly">
    <div class="space-y-3 border-b border-gray-100 pb-3" data-testid="blox-component-instance">
        <template x-if="componentCard(selEl.data.component) && !componentCard(selEl.data.component).missing">
            <div class="flex items-center gap-2">
                <i class="ti ti-components text-base text-violet-500" aria-hidden="true"></i>
                <div class="min-w-0 flex-1">
                    <div class="truncate text-xs font-semibold text-gray-800" x-text="componentCard(selEl.data.component).name"></div>
                    <div class="text-[10px] text-gray-400" x-text="componentText.version.replace(':v', componentCard(selEl.data.component).version)"></div>
                </div>
            </div>
        </template>
        <template x-if="componentCard(selEl.data.component) && componentCard(selEl.data.component).missing">
            <p class="rounded bg-amber-50 px-2 py-1.5 text-[11px] text-amber-700"><?= e(__('blox_component_missing')) ?></p>
        </template>

        <template x-if="selectedInsideLoop() && Object.keys(loopBindingSuggestions()).length">
            <button type="button" @click="applyLoopBindings()" data-testid="blox-component-autobind"
                    class="w-full rounded border border-blue-200 bg-blue-50 px-2 py-1.5 text-[11px] text-blue-700 hover:bg-blue-100 inline-flex items-center justify-center gap-1">
                <i class="ti ti-link text-sm" aria-hidden="true"></i>
                <span x-text="componentText.autobind.replace(':n', Object.keys(loopBindingSuggestions()).length)"></span>
            </button>
        </template>

        <template x-for="prop in instanceProps()" :key="prop.key">
            <div :data-testid="'blox-component-prop-' + prop.key">
                <div class="mb-1 flex items-center justify-between gap-2">
                    <label class="text-[11px] font-semibold text-gray-700">
                        <span x-text="prop.label"></span>
                        <span x-show="instanceLangSlot(prop)" class="ml-1 rounded bg-sky-50 px-1 text-[10px] font-normal text-sky-700" x-text="instanceLangSlot(prop)"></span>
                    </label>
                    <span x-show="!instanceOverridden(prop.key)" class="text-[10px] text-gray-400" x-text="componentText.followsMaster"></span>
                    <button type="button" x-show="instanceOverridden(prop.key)" @click="resetInstanceValue(prop)"
                            :data-testid="'blox-component-reset-' + prop.key"
                            class="text-[10px] text-blue-500 hover:text-blue-700 inline-flex items-center gap-0.5">
                        <i class="ti ti-arrow-back-up text-xs" aria-hidden="true"></i><span x-text="componentText.reset"></span>
                    </button>
                </div>
                <template x-if="prop.type === 'boolean'">
                    <label class="inline-flex items-center gap-2 text-xs text-gray-600">
                        <input type="checkbox" :checked="String(instanceValue(prop)) === '1'"
                               @change="setInstanceValue(prop, $event.target.checked ? '1' : '0')">
                        <span x-text="prop.label"></span>
                    </label>
                </template>
                <template x-if="prop.type === 'select'">
                    <select class="w-full rounded border border-gray-200 px-2 py-1.5 text-xs"
                            :value="instanceValue(prop)" @change="setInstanceValue(prop, $event.target.value)">
                        <template x-for="(label, value) in prop.options" :key="value">
                            <option :value="value" x-text="label" :selected="String(instanceValue(prop)) === String(value)"></option>
                        </template>
                    </select>
                </template>
                <template x-if="prop.type === 'richtext'">
                    <textarea rows="4" class="w-full rounded border border-gray-200 px-2 py-1.5 text-xs font-mono"
                              :value="instanceValue(prop)" @change="setInstanceValue(prop, $event.target.value)"></textarea>
                </template>
                <template x-if="prop.type === 'color'">
                    <input type="text" class="w-full rounded border border-gray-200 px-2 py-1.5 text-xs" placeholder="#000000"
                           :value="instanceValue(prop)" @change="setInstanceValue(prop, $event.target.value)">
                </template>
                <template x-if="prop.type === 'number'">
                    <input type="number" class="w-full rounded border border-gray-200 px-2 py-1.5 text-xs"
                           :value="instanceValue(prop)" @change="setInstanceValue(prop, $event.target.value)">
                </template>
                <template x-if="['text', 'url', 'image', 'icon'].indexOf(prop.type) !== -1">
                    <input type="text" class="w-full rounded border border-gray-200 px-2 py-1.5 text-xs"
                           :data-testid="'blox-component-input-' + prop.key"
                           :placeholder="prop.type === 'url' ? componentText.urlPlaceholder : ''"
                           :value="instanceValue(prop)" @change="setInstanceValue(prop, $event.target.value)">
                </template>
            </div>
        </template>
        <template x-if="componentCard(selEl.data.component) && !componentCard(selEl.data.component).missing && instanceProps().length === 0">
            <p class="text-[10px] text-gray-400" x-text="componentText.noProps"></p>
        </template>

        <div class="flex gap-1.5 pt-1">
            <button type="button" x-show="componentCanManage" @click="editComponentMaster()" data-testid="blox-component-edit-master"
                    class="flex-1 rounded border border-gray-200 px-2 py-1.5 text-[11px] text-gray-600 hover:border-violet-300 hover:text-violet-600 inline-flex items-center justify-center gap-1">
                <i class="ti ti-pencil text-sm" aria-hidden="true"></i><span x-text="componentText.editMaster"></span>
            </button>
            <button type="button" @click="detachSelectedComponent()" :disabled="componentBusy" data-testid="blox-component-detach"
                    class="flex-1 rounded border border-gray-200 px-2 py-1.5 text-[11px] text-gray-600 hover:border-amber-300 hover:text-amber-600 inline-flex items-center justify-center gap-1 disabled:opacity-50">
                <i class="ti ti-unlink text-sm" aria-hidden="true"></i><span x-text="componentText.detach"></span>
            </button>
        </div>
    </div>
</template>

<template x-if="selEl && selEl.type !== 'component' && !componentMasterMode && componentCanManage && panelTab === 'content' && !ctrlQuery.trim() && !modifiedOnly">
    <div class="border-b border-gray-100 pb-3" data-testid="blox-component-save-as">
        <button type="button" @click="saveSelectionAsComponent()" :disabled="componentBusy"
                class="w-full rounded border border-violet-200 bg-violet-50 px-2 py-1.5 text-[11px] text-violet-700 hover:bg-violet-100 inline-flex items-center justify-center gap-1 disabled:opacity-50">
            <i class="ti ti-components text-sm" aria-hidden="true"></i><span x-text="componentText.saveAs"></span>
        </button>
        <p class="mt-1 text-[10px] leading-relaxed text-gray-400" x-text="componentText.saveAsHint"></p>
    </div>
</template>

<template x-if="componentMasterMode && selEl && panelTab === 'content' && !ctrlQuery.trim() && !modifiedOnly && componentExposableCtrls().length">
    <?php // 母版：选中元素的可导出字段一览（有些元素用自定义面板，控件标签旁没有导出图标，这里统一给开关） ?>
    <div class="space-y-1.5 border-b border-gray-100 pb-3" data-testid="blox-component-expose-list">
        <div class="flex items-center gap-1.5 text-[11px] font-medium text-violet-700">
            <i class="ti ti-components text-sm" aria-hidden="true"></i><span x-text="componentText.exposeTitle"></span>
        </div>
        <template x-for="ctrl in componentExposableCtrls()" :key="ctrl.key">
            <button type="button" @click="exposedProp(ctrl) ? unexposeControl(ctrl) : exposeControl(ctrl)"
                    :data-testid="'blox-component-expose-' + ctrl.key"
                    :aria-pressed="!!exposedProp(ctrl)"
                    class="w-full flex items-center justify-between gap-2 rounded border px-2 py-1 text-[11px] transition"
                    :class="exposedProp(ctrl) ? 'border-violet-300 bg-violet-50 text-violet-700' : 'border-gray-200 text-gray-600 hover:border-violet-200'">
                <span class="truncate" x-text="ctrl.label || ctrl.key"></span>
                <span class="shrink-0 font-mono text-[10px]" x-text="exposedProp(ctrl) ? exposedProp(ctrl).key : componentText.expose"></span>
            </button>
        </template>
    </div>
</template>
