<?php
/**
 * YIKAI_BLOX_AI_ACCESS_NOTICE
 * AI-assisted reading or modification requires explicit task-scoped authorization.
 * Policy: docs/blox-commercialization/CORE-ACCESS.md
 * This collaboration notice is not access control and does not replace licenses.
 */
/* 组件库标签（v2.1）：已发布组件，搜索 / 分类 / 用量；点击插入实例（与元素同一插入目标规则）。 */
?>
<div x-show="libTab === 'components' && !componentMasterMode" x-cloak class="flex-1 flex flex-col min-h-0" data-testid="blox-component-library">
    <div class="p-2 border-b border-gray-100 shrink-0 flex items-center gap-1.5">
        <div class="relative flex-1 min-w-0">
            <i class="ti ti-search text-sm text-gray-300 absolute left-2 top-1/2 -translate-y-1/2" aria-hidden="true"></i>
            <input type="text" x-model="componentQuery" :placeholder="componentText.search" data-testid="blox-component-search"
                   class="w-full border border-gray-200 rounded pl-7 pr-2 py-1.5 text-xs">
        </div>
        <select x-model="componentCategory" :aria-label="componentText.category"
                class="w-24 shrink-0 border border-gray-200 rounded bg-white px-2 py-1.5 text-xs text-gray-600">
            <option value="all" x-text="componentText.allCategories"></option>
            <template x-for="cat in componentCategories" :key="cat.key">
                <option :value="cat.key" x-text="cat.label"></option>
            </template>
        </select>
    </div>
    <div class="flex-1 overflow-y-auto blox-scroll p-2 pb-24 space-y-1.5">
        <template x-if="componentLoading">
            <p class="text-xs text-gray-400 text-center py-6" x-text="componentText.loading"></p>
        </template>
        <template x-if="componentError">
            <p class="text-xs text-red-500 text-center py-4" x-text="componentError"></p>
        </template>
        <template x-for="card in filteredComponents()" :key="card.uuid">
            <button type="button" @click="insertComponent(card)" :data-testid="'blox-insert-component-' + card.uuid"
                    class="w-full text-left rounded-md border border-gray-200 px-2.5 py-2 hover:border-violet-400 hover:bg-violet-50/40 transition">
                <div class="flex items-center gap-2">
                    <i class="ti ti-components text-base text-violet-500" aria-hidden="true"></i>
                    <span class="min-w-0 flex-1 truncate text-xs font-medium text-gray-800" x-text="card.name"></span>
                    <span class="text-[10px] text-gray-400" x-text="'v' + card.version"></span>
                </div>
                <div class="mt-1 flex items-center gap-2 text-[10px] text-gray-400">
                    <span x-text="componentCategoryLabel(card.category)"></span>
                    <span x-text="componentText.usedTimes.replace(':n', card.refs)"></span>
                </div>
            </button>
        </template>
        <template x-if="componentLoaded && !componentLoading && filteredComponents().length === 0">
            <p class="text-xs text-gray-400 text-center py-8 leading-relaxed" x-text="componentList.length ? componentText.noMatch : componentText.empty"></p>
        </template>
    </div>
</div>
