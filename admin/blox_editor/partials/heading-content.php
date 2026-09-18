<?php declare(strict_types=1); ?>
<template x-if="headingPanelVisible()">
    <div class="blox-heading-panel" data-testid="blox-heading-panel">
        <?php $headingField = 'text'; require __DIR__ . '/heading-binding-field.php'; ?>
        <div class="blox-heading-setting">
            <label for="blox-heading-level"><?= e(__('blox_ctl_level')) ?></label>
            <select id="blox-heading-level" :value="controlValue(headingControl('level'))"
                    @change="selEl.data.level = $event.target.value" data-testid="blox-heading-level">
                <template x-for="(label, value) in headingControl('level').options" :key="value">
                    <option :value="value" :selected="controlValue(headingControl('level')) === value" x-text="label"></option>
                </template>
            </select>
        </div>
        <?php $headingField = 'url'; require __DIR__ . '/heading-binding-field.php'; ?>
        <label class="blox-heading-setting blox-heading-new-tab">
            <span><?= e(__('blox_new_tab')) ?></span>
            <input type="checkbox" role="switch" class="blox-heading-toggle" data-testid="blox-heading-new-tab"
                   :checked="selEl.data.new_tab === true || selEl.data.new_tab === '1' || selEl.data.new_tab === 1"
                   :disabled="!selEl.data.url && !headingBound('url')" @change="selEl.data.new_tab = $event.target.checked">
        </label>
        <div x-show="!isLoopTemplateChild()" class="blox-heading-anchor">
            <div class="blox-heading-setting">
                <label for="blox-heading-id" title="<?= e(__('blox_heading_anchor_hint')) ?>"><?= e(__('blox_heading_anchor')) ?></label>
                <input id="blox-heading-id" type="text" x-model="selEl.data.html_id" maxlength="64" placeholder="services"
                       data-testid="blox-heading-id" spellcheck="false"
                       :aria-invalid="!!selEl.data.html_id && (!anchorIdValid(selEl.data.html_id) || headingIdDuplicate())">
            </div>
            <p class="blox-heading-warning" x-show="selEl.data.html_id && !anchorIdValid(selEl.data.html_id)"><?= e(__('blox_section_anchor_invalid')) ?></p>
            <p class="blox-heading-warning" x-show="headingIdDuplicate()"><?= e(__('blox_section_anchor_duplicate')) ?></p>
        </div>
    </div>
</template>
