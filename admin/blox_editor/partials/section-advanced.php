<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}
/**
 * 区块「高级」配置（V2.0.0，建站人员）：ID（即区块锚点 anchor_id，与内容页签同一个字段）、
 * CSS 类、自定义 CSS。写在 sel.settings._css_classes / _custom_css，随页面保存、可撤销；
 * 自定义 CSS 需要「全站设计」权限，服务端保存时再把关（BloxCustomCode）。
 */
?>
<details x-show="selLayer === 'sec'" class="rounded border border-gray-200 bg-white" data-testid="blox-section-advanced"
         :open="sectionAdvancedHasValues()" x-data="{ cssDraft: null }" x-init="$watch('sel', () => { cssDraft = null })">
    <summary class="flex cursor-pointer items-center justify-between gap-2 px-3 py-2 text-xs font-semibold text-gray-600">
        <span class="inline-flex items-center gap-1.5"><i class="ti ti-code text-sm text-blue-500" aria-hidden="true"></i><?= e(__('blox_section_advanced')) ?></span>
        <span x-show="sectionAdvancedHasValues()" class="h-1.5 w-1.5 rounded-full bg-blue-500" aria-hidden="true"></span>
    </summary>
    <div class="space-y-3 border-t border-gray-100 px-3 py-3">
        <label class="block">
            <span class="mb-1 block text-[11px] text-gray-500"><?= e(__('blox_advanced_html_id')) ?></span>
            <span class="flex items-center rounded border border-gray-200 focus-within:border-blue-400">
                <span class="pl-2 font-mono text-xs text-gray-400">#</span>
                <input type="text" maxlength="64" spellcheck="false" data-testid="blox-section-advanced-id"
                       :value="sel.settings.anchor_id || ''"
                       @change="sel.settings.anchor_id = $event.target.value.trim()"
                       placeholder="features"
                       class="w-full min-w-0 border-0 bg-transparent px-1 py-1.5 font-mono text-xs outline-none">
            </span>
            <span x-show="sel.settings.anchor_id && !anchorIdValid(sel.settings.anchor_id)" class="mt-1 block text-[10px] text-red-600"><?= e(__('blox_section_anchor_invalid')) ?></span>
            <span class="mt-1 block text-[10px] leading-relaxed text-gray-400"><?= e(__('blox_section_advanced_id_hint')) ?></span>
        </label>

        <label class="block">
            <span class="mb-1 block text-[11px] text-gray-500"><?= e(__('blox_advanced_css_classes')) ?></span>
            <input type="text" spellcheck="false" data-testid="blox-section-advanced-classes"
                   :value="sel.settings._css_classes || ''"
                   @change="setSectionAdvancedValue('_css_classes', $event.target.value); $event.target.value = sel.settings._css_classes || ''"
                   placeholder="band-dark full-bleed"
                   class="w-full rounded border border-gray-200 px-2 py-1.5 font-mono text-xs focus:border-blue-400 focus:outline-none">
            <span class="mt-1 block text-[10px] leading-relaxed text-gray-400"><?= e(__('blox_section_advanced_classes_hint')) ?></span>
        </label>

        <div>
            <span class="mb-1 flex items-center justify-between text-[11px] text-gray-500">
                <span><?= e(__('blox_advanced_custom_css')) ?></span>
                <span x-show="!canManageDesign" class="text-amber-700"><?= e(__('blox_custom_css_readonly')) ?></span>
            </span>
            <textarea rows="6" spellcheck="false" data-testid="blox-section-advanced-css"
                      :readonly="!canManageDesign"
                      :value="cssDraft === null ? (sel.settings._custom_css || '') : cssDraft"
                      @input="cssDraft = $event.target.value"
                      @change="if (!customCssError(cssDraft)) { setSectionAdvancedValue('_custom_css', cssDraft); cssDraft = null }"
                      placeholder="%root% { border-top: 1px solid rgb(0 0 0 / .08) }&#10;%root% h2 { letter-spacing: .02em }"
                      class="w-full rounded border px-2 py-1.5 font-mono text-[11px] leading-relaxed focus:outline-none"
                      :class="customCssError(cssDraft === null ? (sel.settings._custom_css || '') : cssDraft) ? 'border-red-400 bg-red-50' : 'border-gray-200 focus:border-blue-400'"></textarea>
            <p x-show="customCssError(cssDraft === null ? (sel.settings._custom_css || '') : cssDraft)" class="text-[10px] text-red-600" data-testid="blox-section-advanced-css-error"
               x-text="customCssErrorText(cssDraft === null ? (sel.settings._custom_css || '') : cssDraft)"></p>
            <p class="text-[10px] leading-relaxed text-gray-400"><?= e(__('blox_section_advanced_css_hint')) ?></p>
        </div>
    </div>
</details>
