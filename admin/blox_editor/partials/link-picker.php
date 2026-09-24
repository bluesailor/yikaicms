<?php
declare(strict_types=1);
/**
 * 「选择页面」按钮 + 下拉：URL 框旁边点选站内页面/栏目/文章/产品，不用手写地址。
 *
 * 调用前设置：
 *   $linkPickerId  —— JS 表达式，同一选中元素内区分不同字段（如 'ctrl.key'）
 *   $linkPickerSet —— JS 函数表达式，接收 url 写回字段（如 'url => selEl.data[ctrl.key] = url'）
 */
?>
<div class="relative" @click.outside="linkPickerOpen(<?= e($linkPickerId) ?>) && (linkPickerTarget = '')"
     @keydown.escape.stop="linkPickerTarget = ''">
    <button type="button" @click="openLinkPicker(<?= e($linkPickerId) ?>)" data-testid="blox-link-picker"
            :aria-expanded="linkPickerOpen(<?= e($linkPickerId) ?>)"
            title="<?= e(__('blox_link_pick')) ?>"
            class="inline-flex h-7 items-center gap-1 rounded px-1.5 text-[11px] font-medium text-gray-500 hover:bg-blue-50 hover:text-blue-600">
        <i class="ti ti-link" aria-hidden="true"></i><span><?= e(__('blox_link_pick')) ?></span>
    </button>
    <div x-show="linkPickerOpen(<?= e($linkPickerId) ?>)" x-cloak data-testid="blox-link-picker-menu"
         class="absolute right-0 top-full z-30 w-72 max-w-[80vw] rounded border border-gray-200 bg-white p-2 shadow-lg">
        <input type="search" x-model="linkPickerSearch" placeholder="<?= e(__('blox_link_search')) ?>"
               aria-label="<?= e(__('blox_link_search')) ?>" class="mb-1 w-full rounded border border-gray-200 px-2 py-1.5 text-xs">
        <div class="max-h-72 overflow-y-auto">
            <template x-for="group in linkPickerGroups()" :key="group.key">
                <div class="py-1">
                    <p class="px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-gray-400" x-text="group.label"></p>
                    <template x-for="item in group.items" :key="group.key + item.url + item.title">
                        <button type="button" @click="pickLink(<?= e($linkPickerSet) ?>, item.url)"
                                class="flex w-full items-center justify-between gap-2 rounded px-2 py-1.5 text-left text-xs text-gray-700 hover:bg-blue-50">
                            <span class="min-w-0 truncate" x-text="item.title"></span>
                            <code class="shrink-0 max-w-[45%] truncate text-[10px] text-gray-400" x-text="item.url"></code>
                        </button>
                    </template>
                </div>
            </template>
            <p x-show="!linkPickerGroups().length" class="px-2 py-3 text-center text-xs text-gray-400"><?= e(__('blox_link_empty')) ?></p>
        </div>
    </div>
</div>
