<?php

declare(strict_types=1);
?>
<template x-if="isPartnersBlock() && panelTab === 'content'">
    <div data-testid="blox-partners-manager" class="space-y-3">
        <fieldset class="space-y-2 text-xs text-gray-700">
            <legend class="font-semibold mb-2"><?= e(__('blox_partners_source')) ?></legend>
            <label class="flex items-center gap-2">
                <input type="radio" name="partners-source" :checked="!selEl.data.partners_custom"
                       @change="setPartnersMode(false)" data-testid="blox-partners-dynamic">
                <?= e(__('blox_partners_dynamic')) ?>
            </label>
            <label class="flex items-center gap-2">
                <input type="radio" name="partners-source" :checked="!!selEl.data.partners_custom"
                       @change="setPartnersMode(true)" data-testid="blox-partners-custom">
                <?= e(__('blox_partners_local')) ?>
            </label>
        </fieldset>
        <template x-if="!selEl.data.partners_custom">
            <p class="text-xs text-gray-600" x-text="partnerItems().map(item => item.name).join(' / ') || <?= e($jt('blox_partners_empty')) ?>"></p>
        </template>
        <template x-if="selEl.data.partners_custom">
            <div class="space-y-3">
                <template x-for="(item, index) in partnerItems()" :key="index">
                    <div data-testid="blox-partner-row" class="border-b border-gray-200 pb-3 space-y-2">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-semibold text-gray-700" x-text="index + 1"></span>
                            <div class="flex gap-1">
                                <?php foreach (['up' => ['arrow-up', 'blox_ctx_move_up'], 'down' => ['arrow-down', 'blox_ctx_move_down'], 'remove' => ['trash', 'blox_batch_delete']] as $action => [$icon, $label]): ?>
                                <button type="button" @click="changePartner('<?= e($action) ?>', index)"
                                        <?php if ($action !== 'remove'): ?>:disabled="<?= $action === 'up' ? 'index === 0' : 'index === partnerItems().length - 1' ?>"<?php endif; ?>
                                        data-testid="blox-partner-<?= e($action) ?>" title="<?= e(__($label)) ?>" aria-label="<?= e(__($label)) ?>"
                                        class="w-8 h-8 inline-flex items-center justify-center rounded text-gray-600 hover:bg-gray-100 disabled:opacity-40">
                                    <i class="ti ti-<?= e($icon) ?>" aria-hidden="true"></i>
                                </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php foreach (['name' => 'link_name', 'url' => 'link_url', 'logo' => 'link_logo'] as $key => $label): ?>
                        <label class="block text-xs text-gray-600">
                            <span class="block mb-1"><?= e(__($label)) ?></span>
                            <input type="text" :value="item.<?= e($key) ?>" @input="setPartnerField(index, '<?= e($key) ?>', $event.target.value)"
                                   data-testid="blox-partner-<?= e($key) ?>" class="w-full min-w-0 rounded border border-gray-200 px-2 py-1.5 text-gray-800">
                        </label>
                        <?php endforeach; ?>
                        <button type="button" @click="replacePartnerLogo(index)" data-testid="blox-partner-media"
                                class="inline-flex items-center gap-1 text-xs text-blue-700 rounded px-2 py-1 hover:bg-blue-50">
                            <i class="ti ti-photo" aria-hidden="true"></i><?= e(__('blox_partners_logo')) ?>
                        </button>
                    </div>
                </template>
                <div class="flex flex-wrap gap-2">
                    <button type="button" @click="changePartner('add')" :disabled="partnerItems().length >= 12"
                            data-testid="blox-partners-add" class="inline-flex items-center gap-1 rounded border border-gray-200 px-2 py-2 text-xs text-blue-700 disabled:opacity-40">
                        <i class="ti ti-plus" aria-hidden="true"></i><?= e(__('blox_partners_add')) ?>
                    </button>
                    <button type="button" x-show="partnerItems().length === 0" @click="copySharedPartners()"
                            data-testid="blox-partners-copy" class="inline-flex items-center gap-1 rounded border border-gray-200 px-2 py-2 text-xs text-gray-700">
                        <i class="ti ti-copy" aria-hidden="true"></i><?= e(__('blox_partners_copy')) ?>
                    </button>
                </div>
            </div>
        </template>
    </div>
</template>
