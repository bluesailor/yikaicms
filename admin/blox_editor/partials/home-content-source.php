<?php

declare(strict_types=1);
?>
<template x-if="homeContentField(ctrl.key)">
    <div class="flex items-center justify-between gap-2 mb-1.5 min-h-7"
         :data-testid="'blox-home-source-' + ctrl.key"
         :data-source="homeContentField(ctrl.key).inherited ? 'inherit' : 'override'">
        <span class="min-w-0 text-xs text-gray-600"
              x-text="homeContentField(ctrl.key).inherited ? <?= e($jt('blox_home_content_inherited')) ?> : <?= e($jt('blox_home_content_override')) ?>"></span>
        <button type="button" x-show="!homeContentField(ctrl.key).inherited"
                @click="inheritHomeContentField(ctrl.key)"
                :data-testid="'blox-home-inherit-' + ctrl.key"
                title="<?= e(__('blox_home_content_restore')) ?>" aria-label="<?= e(__('blox_home_content_restore')) ?>"
                class="h-7 w-7 shrink-0 rounded text-gray-600 hover:bg-gray-100 inline-flex items-center justify-center">
            <i class="ti ti-link text-sm" aria-hidden="true"></i>
        </button>
    </div>
</template>
