<?php declare(strict_types=1);
$headingIsText = $headingField === 'text';
$headingSlot = $headingIsText ? 'text' : 'url';
?>
<div class="blox-heading-field" x-data="{ bindingOpen: false }"
     @click.outside="bindingOpen = false" @keydown.escape.stop="bindingOpen = false">
    <div class="blox-heading-field-label">
        <label for="blox-heading-<?= e($headingSlot) ?>"><?= e(__($headingIsText ? 'blox_field_title_short' : 'blox_ctl_link')) ?></label>
        <div x-show="!headingBound('<?= e($headingSlot) ?>')">
            <?php $dynamicTagKey = "'" . $headingSlot . "'"; $dynamicTagLinks = $headingIsText ? 'false' : 'true'; require __DIR__ . '/dynamic-tag-picker.php'; ?>
        </div>
        <button type="button" class="blox-heading-binding-button" @click="bindingOpen = !bindingOpen"
                x-show="professionalControlAccessible(headingBindingKey('<?= e($headingSlot) ?>'))"
                :class="headingBound('<?= e($headingSlot) ?>') ? 'is-bound' : ''" :aria-expanded="bindingOpen"
                data-testid="blox-heading-<?= e($headingSlot) ?>-binding"
                title="<?= e(__('blox_heading_dynamic')) ?>" aria-label="<?= e(__('blox_heading_dynamic')) ?>">
            <i class="ti ti-database" aria-hidden="true"></i>
        </button>
    </div>
    <div x-show="headingBound('<?= e($headingSlot) ?>')" class="blox-heading-bound-value">
        <button type="button" @click="bindingOpen = !bindingOpen" :disabled="!professionalControlAccessible(headingBindingKey('<?= e($headingSlot) ?>'))">
            <i class="ti ti-database" aria-hidden="true"></i>
            <span x-text="headingBindingLabel('<?= e($headingSlot) ?>')"></span>
        </button>
        <button type="button" class="blox-heading-unbind" @click="selEl.data[headingBindingKey('<?= e($headingSlot) ?>')] = 'none'; bindingOpen = false"
                x-show="professionalControlAccessible(headingBindingKey('<?= e($headingSlot) ?>'))"
                title="<?= e(__('blox_heading_unbind')) ?>" aria-label="<?= e(__('blox_heading_unbind')) ?>">
            <i class="ti ti-x" aria-hidden="true"></i>
        </button>
    </div>
    <?php if ($headingIsText): ?>
        <textarea id="blox-heading-text" x-show="!headingBound('text')" x-model="selEl.data.text" rows="3" maxlength="2000"
                  data-dynamic-key="text" @input="siteTagInput($event, 'text')"
                  placeholder="<?= e(__('blox_heading_ph')) ?>" data-testid="blox-heading-text"></textarea>
    <?php else: ?>
        <input id="blox-heading-url" type="text" x-show="!headingBound('url')" x-model="selEl.data.url"
               data-dynamic-key="url" @input="siteTagInput($event, 'url')"
               placeholder="<?= e(__('blox_heading_link_ph')) ?>" data-testid="blox-heading-url">
    <?php endif; ?>
    <div x-show="bindingOpen && professionalControlAccessible(headingBindingKey('<?= e($headingSlot) ?>'))" x-cloak class="blox-heading-binding-options">
        <label for="blox-heading-<?= e($headingSlot) ?>-source"><?= e(__('blox_heading_dynamic')) ?></label>
        <select id="blox-heading-<?= e($headingSlot) ?>-source"
                :value="controlValue(headingControl(headingBindingKey('<?= e($headingSlot) ?>')))"
                @change="selEl.data[headingBindingKey('<?= e($headingSlot) ?>')] = $event.target.value"
                data-testid="blox-heading-<?= e($headingSlot) ?>-source">
            <template x-for="(label, value) in controlOptions(headingControl(headingBindingKey('<?= e($headingSlot) ?>')))" :key="value">
                <option :value="value" :selected="controlValue(headingControl(headingBindingKey('<?= e($headingSlot) ?>'))) === value" x-text="label"></option>
            </template>
        </select>
        <?php if ($headingIsText): ?>
        <label x-show="headingBound('text')" class="blox-heading-fallback">
            <span><?= e(__('blox_dynamic_fallback')) ?></span>
            <input type="text" x-model="selEl.data[isLoopTemplateChild() ? 'loop_fallback' : 'site_fallback']" maxlength="2000">
        </label>
        <?php endif; ?>
    </div>
</div>
