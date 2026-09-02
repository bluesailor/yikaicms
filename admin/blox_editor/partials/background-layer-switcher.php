<?php

declare(strict_types=1);

$backgroundSwitcherMode = ($backgroundSwitcherMode ?? '') === 'content' ? 'content' : 'style';
$backgroundSwitcherTestId = $backgroundSwitcherMode === 'content'
    ? 'blox-background-summary'
    : 'blox-background-layer-switcher';
?>
<div data-testid="<?= e($backgroundSwitcherTestId) ?>"
     class="space-y-2 <?= $backgroundSwitcherMode === 'content' ? 'border-t border-gray-100 pt-4' : 'border-b border-gray-100 pb-4' ?>">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            <p class="text-xs font-medium text-gray-700"><?= e(__('blox_background_settings')) ?></p>
            <p class="mt-0.5 text-[10px] leading-relaxed text-gray-500"><?= e(__('blox_background_scope_hint')) ?></p>
        </div>
        <?php if ($backgroundSwitcherMode === 'content'): ?>
        <button type="button" @click="openPreferredBackgroundLayer()"
                data-testid="blox-background-open"
                class="h-8 shrink-0 rounded border border-gray-200 bg-white px-2.5 text-xs font-medium text-gray-700 hover:border-blue-300 hover:text-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-100 inline-flex items-center gap-1.5">
            <i class="ti ti-palette text-sm" aria-hidden="true"></i>
            <span><?= e(__('blox_background_edit')) ?></span>
        </button>
        <?php endif; ?>
    </div>
    <div class="grid grid-cols-2 gap-1.5" role="group" aria-label="<?= e(__('blox_background_scope')) ?>">
        <?php foreach ([
            'section' => ['label' => __('blox_background_full_width'), 'icon' => 'arrows-horizontal'],
            'container' => ['label' => __('blox_background_content'), 'icon' => 'container'],
        ] as $layer => $meta): ?>
        <button type="button" @click="selectBackgroundLayer('<?= e($layer) ?>')"
                :aria-pressed="selLayer === '<?= $layer === 'section' ? 'sec' : 'con' ?>'"
                :title="backgroundLayerState('<?= e($layer) ?>').value || <?= e($jt('blox_background_none')) ?>"
                data-testid="blox-background-layer-<?= e($layer) ?>"
                class="min-w-0 rounded border px-2 py-2 text-left transition focus:outline-none focus:ring-2 focus:ring-blue-100"
                :class="selLayer === '<?= $layer === 'section' ? 'sec' : 'con' ?>' ? 'border-blue-400 bg-blue-50 text-blue-700' : 'border-gray-200 bg-white text-blue-950/70 hover:border-blue-200'">
            <span class="flex items-center gap-1.5 text-xs font-medium">
                <i class="ti ti-<?= e($meta['icon']) ?> shrink-0 text-sm" aria-hidden="true"></i>
                <span class="truncate"><?= e($meta['label']) ?></span>
            </span>
            <span class="mt-1 block truncate text-[10px] opacity-75"
                  x-text="backgroundLayerState('<?= e($layer) ?>').value || <?= e($jt('blox_background_none')) ?>"></span>
        </button>
        <?php endforeach; ?>
    </div>
</div>
