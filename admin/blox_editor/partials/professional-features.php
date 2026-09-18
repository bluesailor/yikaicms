<?php declare(strict_types=1); ?>
<details class="border-b border-gray-100 px-3 py-2 text-xs" data-testid="blox-professional-features"
         x-show="!selectedSectionField && (professionalRelevant('query_loop') || professionalRelevant('display_conditions') || professionalRelevant('style_presets') || professionalRelevant('table') || professionalRelevant('pricing'))"
         @toggle="professionalOpen = $el.open; if (!$el.open && (panelTab === 'professional' || panelTab === 'condition')) panelTab = 'content'">
    <summary class="cursor-pointer text-gray-600 py-1"><?= e(__('blox_professional_features')) ?></summary>
    <div class="space-y-3 pt-2 pb-1">
        <?php foreach (['query_loop' => 'blox_professional_dynamic', 'display_conditions' => 'blox_professional_conditions', 'style_presets' => 'blox_global_style', 'table' => 'blox_el_table', 'pricing' => 'blox_el_pricing_table'] as $feature => $label): ?>
        <div x-show="professionalRelevant('<?= e($feature) ?>')" data-professional-feature="<?= e($feature) ?>">
            <template x-if="professionalFeatures['<?= e($feature) ?>'].allowed">
                <button type="button" @click="openProfessionalFeature('<?= e($feature) ?>')"
                        class="inline-flex items-center gap-1 text-gray-700 hover:text-blue-600"
                        data-testid="<?= e($feature === 'display_conditions' ? 'blox-condition-tab' : 'blox-professional-' . $feature) ?>">
                    <span><?= e(__($label)) ?></span><i class="ti ti-chevron-right" aria-hidden="true"></i>
                </button>
            </template>
            <template x-if="!professionalFeatures['<?= e($feature) ?>'].allowed">
                <div class="space-y-1">
                    <span class="font-medium text-gray-700"><?= e(__($label)) ?></span>
                    <p class="text-gray-600 leading-relaxed" x-text="professionalFeatures['<?= e($feature) ?>'].message"></p>
                    <a x-show="professionalFeatures['<?= e($feature) ?>'].url" :href="professionalFeatures['<?= e($feature) ?>'].url"
                       target="_blank" rel="noopener" class="inline-block text-blue-600 hover:underline"
                       x-text="professionalFeatures['<?= e($feature) ?>'].action"></a>
                </div>
            </template>
        </div>
        <?php endforeach; ?>
    </div>
</details>
