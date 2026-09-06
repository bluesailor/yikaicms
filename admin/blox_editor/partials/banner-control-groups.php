<?php

declare(strict_types=1);
?>
<template x-if="BloxBannerPanel.supports(selEl) && panelTab === 'content' && !ctrlQuery.trim() && !modifiedOnly">
    <div data-testid="blox-banner-control-groups">
    <div role="group" aria-label="<?= e(__('blox_banner_settings_groups')) ?>" class="flex flex-wrap gap-1 border-b border-gray-200 pb-2">
        <?php foreach (['common' => 'blox_banner_group_display', 'layout' => 'blox_banner_group_layout', 'playback' => 'blox_banner_group_playback', 'motion' => 'blox_banner_group_motion', 'mobile' => 'blox_banner_group_mobile'] as $group => $label): ?>
        <button type="button" @click="bannerPanelGroup = '<?= e($group) ?>'"
                :aria-pressed="bannerPanelGroup === '<?= e($group) ?>'"
                data-testid="blox-banner-group-<?= e($group) ?>"
                class="min-w-0 flex-1 rounded px-1 py-2 text-xs font-medium"
                :class="bannerPanelGroup === '<?= e($group) ?>' ? 'bg-blue-50 text-blue-700' : 'text-gray-600 hover:bg-gray-100'">
            <span x-text="selEl.type === 'home-banner-item' ? <?= e($jt(['common' => 'blox_banner_group_content', 'layout' => 'blox_banner_group_layout', 'playback' => 'blox_banner_group_links', 'motion' => 'blox_banner_group_motion', 'mobile' => 'blox_banner_group_mobile'][$group])) ?> : <?= e($jt($label)) ?>"></span>
        </button>
        <?php endforeach; ?>
    </div>
    <div x-show="bannerPanelGroup === 'mobile'" class="flex flex-wrap gap-2 pt-2">
        <?php foreach (['desktop' => 'device-desktop', 'mobile' => 'device-mobile'] as $device => $icon): ?>
        <button type="button" @click="previewBannerDevice('<?= e($device) ?>')"
                data-testid="blox-banner-preview-<?= e($device) ?>"
                class="inline-flex items-center gap-1 rounded border border-gray-200 px-2 py-1.5 text-xs text-gray-700 hover:border-blue-400">
            <i class="ti ti-<?= e($icon) ?>" aria-hidden="true"></i><?= e(__('blox_banner_preview_' . $device)) ?>
        </button>
        <?php endforeach; ?>
    </div>
    </div>
</template>
