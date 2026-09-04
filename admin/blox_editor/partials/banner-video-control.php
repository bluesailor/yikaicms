<?php

declare(strict_types=1);
?>
<template x-if="selEl && selEl.type === 'home-banner-item' && ctrl.key === 'video'">
    <div class="space-y-2" data-banner-video-control>
        <div class="relative aspect-[16/7] overflow-hidden rounded border border-gray-200 bg-gray-950">
            <template x-if="selEl.data.video">
                <video :src="selEl.data.video" :poster="selEl.data.image || ''"
                       class="h-full w-full object-cover" muted loop playsinline autoplay preload="metadata"></video>
            </template>
            <template x-if="!selEl.data.video">
                <div class="absolute inset-0 flex flex-col items-center justify-center text-gray-400">
                    <i class="ti ti-video-off text-2xl" aria-hidden="true"></i>
                    <span class="mt-1 text-xs"><?= e(__('blox_banner_no_video')) ?></span>
                </div>
            </template>
            <button type="button" @click="replaceBannerControlVideo()"
                    data-testid="blox-banner-replace-video"
                    class="absolute inset-x-2 bottom-2 h-8 rounded bg-gray-900/85 text-xs text-white hover:bg-gray-900 inline-flex items-center justify-center gap-1.5">
                <i class="ti ti-library-plus text-sm" aria-hidden="true"></i><?= e(__('blox_pick_media')) ?>
            </button>
        </div>
        <details class="border-t border-gray-200">
            <summary class="cursor-pointer py-1.5 text-xs text-gray-600"><?= e(__('blox_banner_video_url_advanced')) ?></summary>
            <input type="url" x-model.trim="selEl.data.video" aria-label="<?= e(__('blox_banner_video_url_advanced')) ?>"
                   placeholder="/uploads/videos/banner.mp4"
                   class="w-full rounded border border-gray-200 bg-white px-2 py-1.5 text-xs">
        </details>
    </div>
</template>
