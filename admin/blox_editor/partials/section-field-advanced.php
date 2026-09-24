<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}
/**
 * 区块标题 / 副标题的「高级」配置（V2.0.0，建站人员）：与元素同样的 ID、CSS 类、自定义属性、自定义 CSS。
 * 写在 sel.settings._title_* / _subtitle_*，随页面保存、可撤销；
 * 自定义 CSS 需要「全站设计」权限，服务端保存时再把关（BloxCustomCode）。
 */
?>
<details x-show="panelTab === 'style'" class="rounded border border-gray-200 bg-white" data-testid="blox-section-field-advanced"
         :open="sectionFieldAdvancedHasValues()" x-data="{ cssDraft: null }"
         x-init="$watch('selectedSectionField', () => { cssDraft = null }); $watch('sel', () => { cssDraft = null })">
    <summary class="flex cursor-pointer items-center justify-between gap-2 px-3 py-2 text-xs font-semibold text-gray-600">
        <span class="inline-flex items-center gap-1.5"><i class="ti ti-code text-sm text-blue-500" aria-hidden="true"></i><?= e(__('blox_advanced')) ?></span>
        <span x-show="sectionFieldAdvancedHasValues()" class="h-1.5 w-1.5 rounded-full bg-blue-500" aria-hidden="true"></span>
    </summary>
    <div class="space-y-3 border-t border-gray-100 px-3 py-3">
        <label class="block">
            <span class="mb-1 block text-[11px] text-gray-500"><?= e(__('blox_advanced_html_id')) ?></span>
            <span class="flex items-center rounded border border-gray-200 focus-within:border-blue-400">
                <span class="pl-2 font-mono text-xs text-gray-400">#</span>
                <input type="text" maxlength="64" spellcheck="false" data-testid="blox-section-field-advanced-id"
                       :value="sectionFieldAdvancedValue('html_id')"
                       @change="setSectionFieldAdvancedValue('html_id', $event.target.value)"
                       placeholder="faq-title"
                       class="w-full min-w-0 border-0 bg-transparent px-1 py-1.5 font-mono text-xs outline-none">
            </span>
            <span x-show="sectionFieldAdvancedIdInvalid()" class="mt-1 block text-[10px] text-red-600"><?= e(__('blox_advanced_html_id_hint')) ?></span>
        </label>

        <label class="block">
            <span class="mb-1 block text-[11px] text-gray-500"><?= e(__('blox_advanced_css_classes')) ?></span>
            <input type="text" spellcheck="false" data-testid="blox-section-field-advanced-classes"
                   :value="sectionFieldAdvancedValue('css_classes')"
                   @change="setSectionFieldAdvancedValue('css_classes', $event.target.value); $event.target.value = sectionFieldAdvancedValue('css_classes')"
                   placeholder="title-gradient"
                   class="w-full rounded border border-gray-200 px-2 py-1.5 font-mono text-xs focus:border-blue-400 focus:outline-none">
            <span class="mt-1 block text-[10px] leading-relaxed text-gray-400"><?= e(__('blox_section_field_advanced_classes_hint')) ?></span>
        </label>

        <div>
            <div class="mb-1 flex items-center justify-between">
                <span class="text-[11px] text-gray-500"><?= e(__('blox_advanced_attributes')) ?></span>
                <button type="button" @click="addSectionFieldAttribute()" x-show="sectionFieldAttributes().length < 10"
                        data-testid="blox-section-field-advanced-attribute-add"
                        class="inline-flex items-center gap-0.5 text-[11px] text-blue-600 hover:text-blue-800">
                    <i class="ti ti-plus text-[10px]" aria-hidden="true"></i><?= e(__('blox_advanced_attribute_add')) ?>
                </button>
            </div>
            <template x-for="(attribute, index) in sectionFieldAttributes()" :key="'sf-attr-' + index">
                <div class="mb-1 flex items-center gap-1" data-testid="blox-section-field-advanced-attribute">
                    <input type="text" spellcheck="false" :value="attribute.name" placeholder="data-track"
                           @change="setSectionFieldAttribute(index, 'name', $event.target.value)"
                           :aria-label="<?= e($jt('blox_advanced_attribute_name')) ?>"
                           class="w-2/5 min-w-0 rounded border border-gray-200 px-1.5 py-1 font-mono text-[11px] focus:border-blue-400 focus:outline-none">
                    <input type="text" :value="attribute.value" placeholder="value"
                           @change="setSectionFieldAttribute(index, 'value', $event.target.value)"
                           :aria-label="<?= e($jt('blox_advanced_attribute_value')) ?>"
                           class="min-w-0 flex-1 rounded border border-gray-200 px-1.5 py-1 font-mono text-[11px] focus:border-blue-400 focus:outline-none">
                    <button type="button" @click="removeSectionFieldAttribute(index)"
                            class="shrink-0 px-1 text-gray-400 hover:text-red-500"
                            title="<?= e(__('delete')) ?>" aria-label="<?= e(__('delete')) ?>"><i class="ti ti-x text-xs"></i></button>
                </div>
            </template>
            <p class="text-[10px] leading-relaxed text-gray-400"><?= e(__('blox_advanced_attributes_hint')) ?></p>
        </div>

        <div>
            <span class="mb-1 flex items-center justify-between text-[11px] text-gray-500">
                <span><?= e(__('blox_advanced_custom_css')) ?></span>
                <span x-show="!canManageDesign" class="text-amber-700"><?= e(__('blox_custom_css_readonly')) ?></span>
            </span>
            <textarea rows="6" spellcheck="false" data-testid="blox-section-field-advanced-css"
                      :readonly="!canManageDesign"
                      :value="cssDraft === null ? sectionFieldAdvancedValue('custom_css') : cssDraft"
                      @input="cssDraft = $event.target.value"
                      @change="if (!customCssError(cssDraft)) { setSectionFieldAdvancedValue('custom_css', cssDraft); cssDraft = null }"
                      placeholder="letter-spacing: .04em;&#10;%root%::after { content: ''; display: block }"
                      class="w-full rounded border px-2 py-1.5 font-mono text-[11px] leading-relaxed focus:outline-none"
                      :class="customCssError(cssDraft === null ? sectionFieldAdvancedValue('custom_css') : cssDraft) ? 'border-red-400 bg-red-50' : 'border-gray-200 focus:border-blue-400'"></textarea>
            <p x-show="customCssError(cssDraft === null ? sectionFieldAdvancedValue('custom_css') : cssDraft)" class="text-[10px] text-red-600" data-testid="blox-section-field-advanced-css-error"
               x-text="customCssErrorText(cssDraft === null ? sectionFieldAdvancedValue('custom_css') : cssDraft)"></p>
            <p class="text-[10px] leading-relaxed text-gray-400"><?= e(__('blox_section_field_advanced_css_hint')) ?></p>
        </div>
    </div>
</details>
