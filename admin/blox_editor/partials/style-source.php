<?php
declare(strict_types=1);
// The parent supplies a trusted Alpine control expression, never request data.
$sourceLabels = json_encode([
    'element' => __('blox_source_element'), 'default' => __('blox_source_default'),
    'css' => __('blox_source_css'), 'unknown' => __('blox_source_unknown'),
    'unbound' => __('blox_style_binding_none'), 'live' => __('blox_source_live'),
    'archived' => __('blox_source_archived'), 'snapshot' => __('blox_source_snapshot'),
    'missing' => __('blox_source_missing'),
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<template x-if="window.BloxStyleSources && selEl && BloxStyleSources.supports(selEl.type, <?= e($styleSourceControl) ?>)">
    <div x-data="{ source: {}, labels: <?= e($sourceLabels) ?> }"
         x-effect="source = BloxStyleSources.describe(selEl.data, <?= e($styleSourceControl) ?>, designSystem)"
         :data-testid="'blox-style-source-' + (<?= e($styleSourceControl) ?>).key"
         :data-local-source="source.local" :data-shared-source="source.shared"
         class="my-2 text-xs text-gray-600 space-y-1">
        <div class="flex items-center justify-between gap-2">
            <span x-text="labels[source.local]"></span>
            <button type="button" x-show="source.resettable"
                    @click="flushHistory(true); delete selEl.data[(<?= e($styleSourceControl) ?>).key]"
                    :data-testid="'blox-style-source-reset-' + (<?= e($styleSourceControl) ?>).key"
                    class="w-6 h-6 inline-flex items-center justify-center rounded hover:bg-gray-100"
                    title="<?= e(__('blox_source_reset')) ?>" aria-label="<?= e(__('blox_source_reset')) ?>">
                <i class="ti ti-restore" aria-hidden="true"></i>
            </button>
        </div>
        <div x-show="source.localValue" class="break-all" x-text="source.localValue"></div>
        <div x-show="source.shared !== 'unbound'" class="space-y-1">
            <div x-text="labels[source.shared]"></div>
            <div class="break-all" x-show="source.sharedName" x-text="source.sharedName"></div>
            <div class="break-all" x-show="source.sharedValue" x-text="source.sharedValue"></div>
        </div>
    </div>
</template>
