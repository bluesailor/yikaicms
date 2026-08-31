<?php

declare(strict_types=1);
?>
<template x-if="BloxHomeContentPanel.isImage(selEl, ctrl.key)">
    <div class="space-y-2" :data-testid="selEl.data.block_type === 'cta' ? 'blox-cta-background-control' : 'blox-about-image-control'">
        <div class="relative aspect-[3/2] rounded overflow-hidden border border-gray-200 bg-gray-100">
            <template x-if="selEl.data[ctrl.key]">
                <img :src="selEl.data[ctrl.key]" class="w-full h-full object-cover" alt="">
            </template>
            <template x-if="!selEl.data[ctrl.key]">
                <div class="absolute inset-0 flex flex-col items-center justify-center text-gray-600">
                    <i class="ti ti-photo text-2xl" aria-hidden="true"></i>
                    <span class="mt-1 text-xs" x-text="selEl.data.block_type === 'about' ? <?= e($jt('blox_home_panel_image_inherited')) ?> : homeDynamicText.noImage"></span>
                </div>
            </template>
            <button type="button" @click="replaceHomeContentImage(ctrl.key)"
                    :data-testid="selEl.data.block_type === 'cta' ? 'blox-cta-background-media' : 'blox-about-image-media'"
                    class="absolute inset-x-2 bottom-2 h-8 rounded bg-gray-900/80 hover:bg-blue-600 text-white text-xs inline-flex items-center justify-center gap-1.5 transition">
                <i class="ti ti-photo-edit text-sm" aria-hidden="true"></i><span x-text="homeDynamicText.replaceImage"></span>
            </button>
        </div>
        <details class="rounded border border-gray-200 bg-gray-50">
            <summary class="px-2.5 py-1.5 text-xs text-gray-600 cursor-pointer" x-text="homeDynamicText.imageUrl"></summary>
            <div class="px-2 pb-2">
                <input type="text" x-model="selEl.data[ctrl.key]" placeholder="/uploads/images/xx.jpg" :aria-label="ctrl.label"
                       :data-testid="selEl.data.block_type === 'cta' ? 'blox-cta-background-url' : 'blox-about-image-url'"
                       class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs bg-white">
            </div>
        </details>
    </div>
</template>
