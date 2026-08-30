<?php

declare(strict_types=1);
?>
<template x-if="BloxBannerPanel.supports(selEl) && panelTab === 'content' && !ctrlQuery.trim() && !modifiedOnly">
    <div role="group" aria-label="<?= e(__('blox_banner_settings_groups')) ?>"
         data-testid="blox-banner-control-groups" class="flex gap-1 border-b border-gray-200 pb-2">
        <?php foreach (['common' => 'blox_banner_group_display', 'playback' => 'blox_banner_group_playback', 'motion' => 'blox_banner_group_motion'] as $group => $label): ?>
        <button type="button" @click="bannerPanelGroup = '<?= e($group) ?>'"
                :aria-pressed="bannerPanelGroup === '<?= e($group) ?>'"
                data-testid="blox-banner-group-<?= e($group) ?>"
                class="min-w-0 flex-1 rounded px-2 py-2 text-xs font-medium"
                :class="bannerPanelGroup === '<?= e($group) ?>' ? 'bg-blue-50 text-blue-700' : 'text-gray-600 hover:bg-gray-100'">
            <?= e(__($label)) ?>
        </button>
        <?php endforeach; ?>
    </div>
</template>
