<?php
/**
 * 区块边界预设控件（V2.0.1 A）。由 workspace.php 的区块样式页签 require。
 *
 * 作用域继承自 workspace.php：可用 $jt()（JSON 化的译文）与 sel.settings。
 * 所有值都直接写在 sel.settings 上，与其它区块设置同一套保存/撤销路径。
 */
declare(strict_types=1);
?>
<div class="blox-property-span-full">
    <label class="block text-xs font-medium text-gray-600 mb-1.5"><?= e(__('blox_section_divider')) ?></label>
    <p class="mb-2 text-[10px] leading-relaxed text-gray-400"><?= e(__('blox_section_divider_hint')) ?></p>

    <template x-for="edge in [
        {key:'top', label:<?= e($jt('blox_section_divider_top')) ?>},
        {key:'bottom', label:<?= e($jt('blox_section_divider_bottom')) ?>}
    ]" :key="edge.key">
        <div class="mb-3 rounded border border-gray-200 p-2 last:mb-0">
            <div class="mb-1.5 text-[11px] font-medium text-gray-500" x-text="edge.label"></div>

            <select :value="sel.settings['divider_' + edge.key] || ''"
                    @change="sel.settings['divider_' + edge.key] = $event.target.value"
                    :data-testid="'blox-section-divider-' + edge.key"
                    class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm bg-white">
                <option value=""><?= e(__('blox_divider_none')) ?></option>
                <option value="arc"><?= e(__('blox_divider_arc')) ?></option>
                <option value="wave"><?= e(__('blox_divider_wave')) ?></option>
                <option value="slant"><?= e(__('blox_divider_slant')) ?></option>
            </select>

            <div x-show="sel.settings['divider_' + edge.key]" class="mt-2 space-y-2">
                <?php /* 形状预览：与前台同一组路径，作者点选即可看到形状 */ ?>
                <div class="rounded border border-gray-100 bg-gray-50 p-1">
                    <svg viewBox="0 0 1200 100" preserveAspectRatio="none" aria-hidden="true" focusable="false"
                         class="block h-8 w-full"
                         :style="'transform:' + (edge.key === 'top'
                             ? (sel.settings['divider_' + edge.key + '_flip'] ? 'scale(-1,-1)' : 'scaleY(-1)')
                             : (sel.settings['divider_' + edge.key + '_flip'] ? 'scaleX(-1)' : 'none'))">
                        <path :d="bloxDividerPath(sel.settings['divider_' + edge.key])" fill="#94a3b8"></path>
                    </svg>
                </div>

                <label class="flex items-center gap-2 text-xs text-gray-600">
                    <input type="checkbox"
                           :checked="!!sel.settings['divider_' + edge.key + '_flip']"
                           @change="sel.settings['divider_' + edge.key + '_flip'] = $event.target.checked"
                           :data-testid="'blox-section-divider-flip-' + edge.key"
                           class="rounded border-gray-300">
                    <?= e(__('blox_divider_flip')) ?>
                </label>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-[10px] text-gray-400 mb-1"><?= e(__('blox_divider_height')) ?></label>
                        <input type="number" min="<?= (int) BloxSectionDivider::HEIGHT_MIN ?>"
                               max="<?= (int) BloxSectionDivider::HEIGHT_MAX ?>" step="<?= (int) BloxSectionDivider::HEIGHT_STEP ?>"
                               :value="sel.settings['divider_' + edge.key + '_height'] ?? <?= (int) BloxSectionDivider::HEIGHT_DEFAULT ?>"
                               @change="sel.settings['divider_' + edge.key + '_height'] = Number($event.target.value)"
                               :data-testid="'blox-section-divider-height-' + edge.key"
                               class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm bg-white">
                    </div>
                    <div>
                        <label class="block text-[10px] text-gray-400 mb-1"><?= e(__('blox_divider_height_mobile')) ?></label>
                        <input type="number" min="0" max="<?= (int) BloxSectionDivider::HEIGHT_MAX ?>"
                               step="<?= (int) BloxSectionDivider::HEIGHT_STEP ?>"
                               :value="sel.settings['divider_' + edge.key + '_height_m'] ?? <?= (int) BloxSectionDivider::HEIGHT_DEFAULT ?>"
                               @change="sel.settings['divider_' + edge.key + '_height_m'] = Number($event.target.value)"
                               :data-testid="'blox-section-divider-height-m-' + edge.key"
                               class="w-full border border-gray-200 rounded px-2 py-1.5 text-sm bg-white">
                        <p class="mt-1 text-[10px] leading-relaxed text-gray-400"><?= e(__('blox_divider_height_mobile_hint')) ?></p>
                    </div>
                </div>

                <div>
                    <label class="block text-[10px] text-gray-400 mb-1"><?= e(__('blox_divider_color')) ?></label>
                    <button type="button"
                            @click="openEditorColorPicker($event, 'section-divider-' + edge.key, <?= e($jt('blox_divider_color')) ?>, sel.settings['divider_' + edge.key + '_color'], '#ffffff', true, value => sel.settings['divider_' + edge.key + '_color'] = value)"
                            :data-testid="'blox-section-divider-color-' + edge.key"
                            class="flex h-10 w-full items-center gap-2 rounded border border-gray-200 bg-white px-2 text-left hover:border-blue-300 focus:outline-none focus:ring-2 focus:ring-blue-100">
                        <span class="h-7 w-9 shrink-0 rounded border border-black/10"
                              :style="'background:' + colorFieldPreview(sel.settings['divider_' + edge.key + '_color'], '#ffffff')"></span>
                        <span class="min-w-0 flex-1 truncate text-sm text-gray-700"
                              x-text="colorFieldLabel(sel.settings['divider_' + edge.key + '_color'], <?= e($jt('blox_divider_color_auto')) ?>)"></span>
                        <i class="ti ti-chevron-down text-sm text-gray-400"></i>
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>
