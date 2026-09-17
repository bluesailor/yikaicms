<?php

declare(strict_types=1);
/** 通用条目编辑器（ctrl.type === 'items_repeater'），由 workspace 控件循环 require；方法见 assets/js/blox-items-control.js。 */
$itemsText = json_encode([
    'add' => __('blox_items_add'),
    'delete' => __('blox_items_delete'),
    'pick' => __('blox_items_pick_image'),
    'clear' => __('blox_items_clear_image'),
    'up' => __('blox_ctx_move_up'),
    'down' => __('blox_ctx_move_down'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<template x-if="ctrl.type === 'items_repeater'">
    <div class="space-y-2" :data-testid="'blox-items-' + ctrl.key" x-data="{ it: <?= $itemsText ?> }">
        <template x-for="(item, index) in repeaterItems(ctrl, selEl)" :key="ctrl.key + '-' + index">
            <div data-testid="blox-items-item" class="rounded border border-gray-200 bg-gray-50/70 p-2.5 space-y-2">
                <div class="flex items-center gap-1.5">
                    <template x-if="ctrl.thumb_field && item[ctrl.thumb_field]">
                        <img :src="item[ctrl.thumb_field]" alt="" class="h-6 w-6 shrink-0 rounded object-cover border border-gray-200 bg-white">
                    </template>
                    <span class="min-w-0 flex-1 truncate text-[10px] font-semibold text-gray-500"
                          x-text="(index + 1) + ' · ' + (item[ctrl.title_field] || ctrl.item_label || '')"></span>
                    <button type="button" @click.stop="moveRepeaterItem(ctrl, index, -1)" :disabled="index === 0" :title="it.up" :aria-label="it.up"
                            class="w-6 h-6 rounded text-gray-400 hover:bg-white hover:text-blue-600 disabled:text-gray-200 inline-flex items-center justify-center">
                        <i class="ti ti-arrow-up text-xs"></i>
                    </button>
                    <button type="button" @click.stop="moveRepeaterItem(ctrl, index, 1)" :disabled="index === repeaterItems(ctrl, selEl).length - 1" :title="it.down" :aria-label="it.down"
                            class="w-6 h-6 rounded text-gray-400 hover:bg-white hover:text-blue-600 disabled:text-gray-200 inline-flex items-center justify-center">
                        <i class="ti ti-arrow-down text-xs"></i>
                    </button>
                    <button type="button" @click.stop="deleteRepeaterItem(ctrl, index)" :disabled="repeaterItems(ctrl, selEl).length <= 1" :title="it.delete" :aria-label="it.delete"
                            class="w-6 h-6 rounded text-gray-400 hover:bg-white hover:text-red-500 disabled:text-gray-200 inline-flex items-center justify-center">
                        <i class="ti ti-trash text-xs"></i>
                    </button>
                </div>
                <template x-for="field in (ctrl.fields || [])" :key="ctrl.key + '-' + index + '-' + field.key">
                    <div>
                        <span class="block text-[10px] text-gray-500 mb-0.5" x-text="field.label"></span>
                        <template x-if="field.type === 'image'">
                            <div class="flex items-center gap-1.5">
                                <button type="button" @click="pickRepeaterImage(ctrl, index, field.key)"
                                        class="h-10 w-10 shrink-0 rounded border border-dashed border-gray-300 bg-white overflow-hidden inline-flex items-center justify-center text-gray-400 hover:border-blue-400 hover:text-blue-600"
                                        :title="it.pick" :aria-label="it.pick">
                                    <template x-if="item[field.key]"><img :src="item[field.key]" alt="" class="h-full w-full object-cover"></template>
                                    <template x-if="!item[field.key]"><i class="ti ti-photo-plus text-base"></i></template>
                                </button>
                                <input type="text" :value="item[field.key]" @change="setRepeaterItem(ctrl, index, field.key, $event.target.value)"
                                       :placeholder="field.placeholder || ''"
                                       class="min-w-0 flex-1 h-8 border border-gray-200 rounded px-2 text-xs bg-white">
                                <button type="button" x-show="item[field.key]" @click="setRepeaterItem(ctrl, index, field.key, '')" :title="it.clear" :aria-label="it.clear"
                                        class="w-6 h-6 rounded text-gray-400 hover:bg-white hover:text-red-500 inline-flex items-center justify-center">
                                    <i class="ti ti-x text-xs"></i>
                                </button>
                            </div>
                        </template>
                        <template x-if="field.type === 'textarea'">
                            <textarea rows="3" :value="item[field.key]" @input="setRepeaterItem(ctrl, index, field.key, $event.target.value)"
                                      :placeholder="field.placeholder || ''"
                                      class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs bg-white leading-relaxed"></textarea>
                        </template>
                        <template x-if="field.type === 'select'">
                            <select :value="item[field.key]" @change="setRepeaterItem(ctrl, index, field.key, $event.target.value)"
                                    class="w-full h-8 border border-gray-200 rounded px-2 text-xs bg-white">
                                <template x-for="option in (field.options || [])" :key="option.value">
                                    <option :value="option.value" :selected="String(item[field.key]) === String(option.value)" x-text="option.label"></option>
                                </template>
                            </select>
                        </template>
                        <template x-if="field.type !== 'image' && field.type !== 'textarea' && field.type !== 'select'">
                            <input type="text" :value="item[field.key]" @input="setRepeaterItem(ctrl, index, field.key, $event.target.value)"
                                   :placeholder="field.placeholder || ''"
                                   class="w-full h-8 border border-gray-200 rounded px-2 text-xs bg-white">
                        </template>
                    </div>
                </template>
            </div>
        </template>
        <button type="button" @click="addRepeaterItem(ctrl)" :disabled="!canAddRepeaterItem(ctrl)" data-testid="blox-items-add"
                class="w-full h-8 rounded border border-dashed border-gray-300 text-xs text-gray-600 hover:border-blue-400 hover:text-blue-700 disabled:opacity-40 inline-flex items-center justify-center gap-1">
            <i class="ti ti-plus text-sm"></i><span x-text="it.add"></span>
        </button>
    </div>
</template>
