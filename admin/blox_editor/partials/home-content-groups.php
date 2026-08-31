<?php

declare(strict_types=1);
?>
<template x-if="BloxHomeContentPanel.supports(selEl) && panelTab === 'content' && !ctrlQuery.trim() && !modifiedOnly">
    <div role="group" aria-label="<?= e(__('blox_home_panel_groups')) ?>" data-testid="blox-home-content-groups"
         class="flex gap-1 border-b border-gray-200 pb-2">
        <?php foreach (['content', 'media', 'layout', 'more'] as $group): ?>
        <button type="button" @click="setHomeContentGroup('<?= e($group) ?>')"
                :aria-pressed="homeContentGroup === '<?= e($group) ?>'"
                data-testid="blox-home-group-<?= e($group) ?>"
                class="min-w-0 flex-1 h-8 rounded px-1 text-xs font-medium whitespace-nowrap"
                :class="homeContentGroup === '<?= e($group) ?>' ? 'bg-blue-50 text-blue-700' : 'text-gray-600 hover:bg-gray-100'">
            <?php if ($group === 'media'): ?>
            <span x-text="selEl.data.block_type === 'cta' ? <?= e($jt('blox_home_panel_background')) ?> : <?= e($jt('blox_home_panel_image')) ?>"></span>
            <?php else: ?>
            <?= e(__('blox_home_panel_' . $group)) ?>
            <?php endif; ?>
        </button>
        <?php endforeach; ?>
    </div>
</template>
