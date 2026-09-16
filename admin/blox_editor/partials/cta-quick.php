<?php

declare(strict_types=1);
?>
<template x-if="ctaQuickTarget() && panelTab === 'content' && !ctrlQuery.trim() && !modifiedOnly">
    <div class="space-y-3 mb-4" data-testid="blox-cta-quick">
        <?php foreach (['title' => 'blox_field_title_short', 'text' => 'blox_ctl_subtext', 'btn_text' => 'blox_ctl_btn_text', 'btn_url' => 'blox_ctl_btn_url'] as $field => $label): ?>
        <label class="block text-xs font-medium text-gray-600">
            <?= e(__($label)) ?>
            <?php if ($field === 'text'): ?>
            <textarea rows="3" :value="ctaQuickValue('<?= e($field) ?>')" @input="setCtaQuickValue('<?= e($field) ?>', $event.target.value)" data-testid="blox-cta-<?= e($field) ?>" class="mt-1 w-full border border-gray-200 rounded px-2 py-2 text-sm"></textarea>
            <?php else: ?>
            <input type="text" :value="ctaQuickValue('<?= e($field) ?>')" @input="setCtaQuickValue('<?= e($field) ?>', $event.target.value)" data-testid="blox-cta-<?= e($field) ?>" class="mt-1 w-full h-9 border border-gray-200 rounded px-2 text-sm">
            <?php endif; ?>
        </label>
        <?php endforeach; ?>
        <label class="block text-xs font-medium text-gray-600">
            <?= e(__('blox_bg_image')) ?>
            <input type="text" :value="ctaQuickBackground().bg_image || ''" @change="setCtaQuickBackground($event.target.value)" data-testid="blox-cta-bg" class="mt-1 w-full h-9 border border-gray-200 rounded px-2 text-sm">
        </label>
        <button type="button" @click="replaceCtaQuickBackground()" class="text-xs text-blue-600"><?= e(__('blox_exp_choose_background')) ?></button>
        <button type="button" @click="openCtaQuickBackground()" class="block text-xs text-blue-600"><?= e(__('blox_exp_background_details')) ?></button>
        <button type="button" @click="ctaQuickDetails = !ctaQuickDetails" :aria-expanded="ctaQuickDetails" data-testid="blox-cta-details" class="block w-full border-t border-gray-200 pt-3 text-left text-xs text-gray-600">
            <i class="ti" :class="ctaQuickDetails ? 'ti-chevron-up' : 'ti-chevron-down'"></i> <?= e(__('blox_exp_layout_details')) ?>
        </button>
    </div>
</template>
