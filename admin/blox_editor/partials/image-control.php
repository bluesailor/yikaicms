<?php
declare(strict_types=1);

// Expressions below are supplied only by the editor's static PHP partials.
$imageArgs = "'" . $imageControl['scope'] . "', " . $imageControl['key'];
$imageValue = 'imageControlValue(' . $imageArgs . ')';
?>
<div class="space-y-2" data-testid="<?= e($imageControl['id']) ?>-control">
    <div class="relative h-28 overflow-hidden rounded border border-gray-200 bg-gray-50">
        <template x-for="imageUrl in (<?= e($imageValue) ?> ? [<?= e($imageValue) ?>] : [])" :key="imageUrl">
            <div class="h-full" x-data="{ failed: false }">
                <img :src="imageUrl" x-show="!failed" @error="failed = true" alt="" class="w-full h-full object-contain">
                <span x-show="failed" class="absolute inset-0 flex items-center justify-center px-2 text-center text-xs text-gray-600" role="status"><?= e(__('blox_image_load_failed')) ?></span>
            </div>
        </template>
        <span x-show="!<?= e($imageValue) ?>" class="absolute inset-0 flex items-center justify-center text-xs text-gray-500"><?= e(__('blox_home_banner_no_image')) ?></span>
    </div>
    <div class="flex items-center gap-2">
        <button type="button" @click="pickImageControl(<?= e($imageArgs) ?>)" data-testid="<?= e($imageControl['id']) ?>-media"
                class="min-w-0 flex-1 h-8 rounded border border-gray-300 bg-white text-xs text-gray-700 hover:bg-gray-50 inline-flex items-center justify-center gap-1">
            <i class="ti ti-photo-edit" aria-hidden="true"></i><span><?= e(__('blox_home_banner_replace_image')) ?></span>
        </button>
        <button type="button" @click="setImageControl(<?= e($imageArgs) ?>, '')" :disabled="!<?= e($imageValue) ?>"
                data-testid="<?= e($imageControl['id']) ?>-clear" title="<?= e(__('blox_clear')) ?>" aria-label="<?= e(__('blox_clear')) ?>"
                class="w-8 h-8 shrink-0 rounded border border-gray-300 text-gray-600 hover:bg-red-50 hover:text-red-700 disabled:opacity-30 disabled:cursor-not-allowed inline-flex items-center justify-center">
            <i class="ti ti-x" aria-hidden="true"></i>
        </button>
    </div>
    <input type="text" :value="<?= e($imageValue) ?>" @input="setImageControl(<?= e($imageArgs) ?>, $event.target.value, false)" @change="flushHistory(true)"
           aria-label="<?= e(__('blox_image_url')) ?>" placeholder="/uploads/images/xx.jpg" data-testid="<?= e($imageControl['urlId']) ?>"
           class="w-full min-w-0 border border-gray-200 rounded px-2 py-1.5 text-xs bg-white">
</div>
