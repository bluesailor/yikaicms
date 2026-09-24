<?php declare(strict_types=1); ?>
<details class="border-b border-gray-100 px-3 py-2 text-xs" data-testid="blox-professional-features"
         x-show="!selectedSectionField && (professionalRelevant('query_loop') || professionalRelevant('display_conditions') || professionalRelevant('style_presets') || professionalRelevant('global_classes') || professionalRelevant('table') || professionalRelevant('pricing'))"
         @toggle="professionalOpen = $el.open; if (!$el.open && (panelTab === 'professional' || panelTab === 'condition')) panelTab = 'content'">
    <?php // 角标只在标题上挂一次：已开通的项逐条挂 PRO 会让人以为还要另外付费 ?>
    <summary class="cursor-pointer text-gray-600 py-1"><span class="inline-flex items-center gap-1"><?= e(__('blox_professional_features')) ?><?php require __DIR__ . '/pro-badge.php'; ?></span></summary>
    <div class="space-y-3 pt-2 pb-1">
        <?php foreach (['query_loop' => 'blox_professional_dynamic', 'display_conditions' => 'blox_professional_conditions', 'style_presets' => 'blox_global_style', 'global_classes' => 'blox_global_classes', 'table' => 'blox_el_table', 'pricing' => 'blox_el_pricing_table'] as $feature => $label): ?>
        <div x-show="professionalRelevant('<?= e($feature) ?>') && professionalFeatures['<?= e($feature) ?>'].allowed"
             data-professional-feature="<?= e($feature) ?>">
            <button type="button" @click="openProfessionalFeature('<?= e($feature) ?>')"
                    class="inline-flex items-center gap-1 text-gray-700 hover:text-blue-600"
                    data-testid="<?= e($feature === 'display_conditions' ? 'blox-condition-tab' : 'blox-professional-' . $feature) ?>">
                <span><?= e(__($label)) ?></span><i class="ti ti-chevron-right" aria-hidden="true"></i>
            </button>
        </div>
        <?php endforeach; ?>
        <?php // 未解锁的功能按相同状态/跳转聚合为一条（避免同一句"尚未启用"重复 N 遍） ?>
        <template x-for="group in lockedProfessionalGroups()" :key="group.key">
            <div class="space-y-1 border-t border-gray-100 pt-2" data-testid="blox-professional-locked-group">
                <span class="inline-flex items-center gap-1 font-medium text-gray-700"><span x-text="group.names"></span><?php require __DIR__ . '/pro-badge.php'; ?></span>
                <p class="text-gray-600 leading-relaxed" x-text="group.message"></p>
                <a x-show="group.url" :href="group.url"
                   target="_blank" rel="noopener" class="inline-block text-blue-600 hover:underline"
                   x-text="group.action"></a>
            </div>
        </template>
    </div>
</details>
