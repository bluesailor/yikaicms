<?php
declare(strict_types=1);

// Expressions below are supplied only by the editor's static PHP partials.
$videoArgs = "'" . $videoControl['scope'] . "', " . $videoControl['key'];
$videoValue = 'videoControlValue(' . $videoArgs . ')';
?>
<div class="space-y-2" data-testid="<?= e($videoControl['id']) ?>-control">
    <div class="flex items-center gap-2">
        <button type="button" @click="pickVideoControl(<?= e($videoArgs) ?>)"
                data-testid="<?= e($videoControl['id']) ?>-media"
                class="min-w-0 flex-1 h-8 rounded border border-gray-300 bg-white text-xs text-gray-700 hover:bg-gray-50 inline-flex items-center justify-center gap-1">
            <i class="ti ti-video-plus" aria-hidden="true"></i><span><?= e(__('blox_bg_video_choose')) ?></span>
        </button>
        <button type="button" @click="setVideoControl(<?= e($videoArgs) ?>, '')" :disabled="!<?= e($videoValue) ?>"
                data-testid="<?= e($videoControl['id']) ?>-clear" title="<?= e(__('blox_clear')) ?>" aria-label="<?= e(__('blox_clear')) ?>"
                class="w-8 h-8 shrink-0 rounded border border-gray-300 text-gray-600 hover:bg-red-50 hover:text-red-700 disabled:opacity-30 disabled:cursor-not-allowed inline-flex items-center justify-center">
            <i class="ti ti-x" aria-hidden="true"></i>
        </button>
    </div>
    <input id="<?= e($videoControl['urlId']) ?>" type="text" :value="<?= e($videoValue) ?>"
           @input="setVideoControl(<?= e($videoArgs) ?>, $event.target.value, false)" @change="flushHistory(true)"
           aria-label="<?= e(__('blox_bg_video')) ?>" placeholder="/uploads/videos/xx.mp4"
           data-testid="<?= e($videoControl['urlId']) ?>"
           class="w-full min-w-0 border border-gray-200 rounded px-2 py-1.5 text-xs bg-white">
</div>
