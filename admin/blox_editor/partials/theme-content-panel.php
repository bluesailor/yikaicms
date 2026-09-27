<?php
/**
 * 右侧结构面板顶部：主题给页头/页尾声明的文案（content-fields.json 里 area: header/footer）。
 * 主题页头页尾不是 Blox 文档，这里保存即全站生效；画布点主题默认页头/页尾会展开到对应一组。
 */

declare(strict_types=1);
?>
<template x-if="themeContent.fields && themeContent.fields.length">
    <details class="mt-2 rounded-md border border-gray-200 bg-white" data-testid="blox-theme-content"
             :open="themeContentOpen" @toggle="themeContentOpen = $el.open">
        <summary class="flex cursor-pointer items-center gap-1.5 px-2.5 py-2 text-xs font-medium text-gray-700">
            <i class="ti ti-palette text-sm text-gray-400" aria-hidden="true"></i>
            <span class="min-w-0 flex-1 truncate"><?= e(__('blox_theme_content_title')) ?></span>
            <span class="shrink-0 text-[10px] text-gray-400" x-text="themeContent.language_label"></span>
        </summary>
        <div class="space-y-3 border-t border-gray-100 px-2.5 py-2.5">
            <?php foreach (['header' => 'site_design_area_header', 'footer' => 'site_design_area_footer'] as $themeContentArea => $themeContentAreaLabel): ?>
            <div x-show="themeContentFields('<?= e($themeContentArea) ?>').length" class="space-y-2"
                 data-testid="blox-theme-content-<?= e($themeContentArea) ?>">
                <p class="text-[10px] font-semibold uppercase text-gray-400"><?= e(__($themeContentAreaLabel)) ?></p>
                <p x-show="themeContent.overridden && themeContent.overridden['<?= e($themeContentArea) ?>']"
                   class="text-[10px] text-amber-600"><?= e(__('blox_theme_content_overridden')) ?></p>
                <template x-for="field in themeContentFields('<?= e($themeContentArea) ?>')" :key="field.key">
                    <label class="block">
                        <span class="mb-1 block text-[11px] font-medium text-gray-600" x-text="field.label"></span>
                        <template x-if="field.type === 'textarea'">
                            <textarea rows="3" x-model="themeContent.values[field.key]" @input="themeContentChanged = true"
                                      :readonly="!themeContent.can_edit"
                                      class="w-full rounded-md border border-gray-200 px-2 py-1.5 text-xs focus:border-blue-400 focus:outline-none"></textarea>
                        </template>
                        <template x-if="field.type === 'toggle'">
                            <input type="checkbox" :checked="themeContent.values[field.key] === '1'" :disabled="!themeContent.can_edit"
                                   @change="themeContent.values[field.key] = $event.target.checked ? '1' : '0'; themeContentChanged = true"
                                   class="h-4 w-4 rounded border-gray-300">
                        </template>
                        <template x-if="field.type !== 'textarea' && field.type !== 'toggle'">
                            <input type="text" x-model="themeContent.values[field.key]" @input="themeContentChanged = true"
                                   @keydown.enter.prevent="saveThemeContent()"
                                   :readonly="!themeContent.can_edit"
                                   class="h-8 w-full rounded-md border border-gray-200 px-2 text-xs focus:border-blue-400 focus:outline-none">
                        </template>
                        <span x-show="field.hint" class="mt-1 block text-[10px] text-gray-400" x-text="field.hint"></span>
                    </label>
                </template>
            </div>
            <?php endforeach; ?>
            <div x-show="themeContent.can_edit" class="flex items-center justify-between gap-2 border-t border-gray-100 pt-2">
                <span class="text-[10px] text-amber-600"><?= e(__('blox_theme_content_live_note')) ?></span>
                <button type="button" @click="saveThemeContent()" data-testid="blox-theme-content-save"
                        :disabled="!themeContentChanged || themeContentSaving"
                        class="h-7 shrink-0 rounded-md bg-blue-600 px-2.5 text-xs font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-50">
                    <?= e(__('blox_theme_content_save')) ?>
                </button>
            </div>
            <p x-show="!themeContent.can_edit" class="text-[10px] text-gray-400"><?= e(__('blox_theme_content_readonly')) ?></p>
        </div>
    </details>
</template>
