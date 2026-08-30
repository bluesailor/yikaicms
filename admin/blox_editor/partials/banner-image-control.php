<?php

declare(strict_types=1);
?>
<template x-if="selEl && selEl.type === 'home-banner-item'">
    <div class="space-y-2" data-banner-image-control>
        <template x-if="ctrl.key === 'image_mobile'">
            <div role="status" class="text-xs text-gray-600" data-testid="blox-banner-mobile-source"
                 x-text="selEl.data.image_mobile ? <?= e($jt('blox_banner_mobile_image_custom')) ?> : <?= e($jt('blox_banner_mobile_image_default')) ?>"></div>
        </template>
        <div class="relative aspect-[16/7] rounded overflow-hidden border border-gray-200 bg-gray-100">
            <template x-if="bannerImageUrl(ctrl.key)">
                <img :src="bannerImageUrl(ctrl.key)" class="w-full h-full object-cover" alt="">
            </template>
            <template x-if="!bannerImageUrl(ctrl.key)">
                <div class="absolute inset-0 flex flex-col items-center justify-center text-gray-500">
                    <i class="ti ti-photo-off text-2xl" aria-hidden="true"></i>
                    <span class="text-xs mt-1" x-text="homeDynamicText.noImage"></span>
                </div>
            </template>
            <button type="button" @click="replaceBannerControlImage(ctrl.key)"
                    :data-testid="'blox-banner-replace-' + ctrl.key"
                    class="absolute inset-x-2 bottom-2 h-8 rounded bg-gray-900/80 hover:bg-gray-900 text-white text-xs inline-flex items-center justify-center gap-1.5">
                <i class="ti ti-photo-edit text-sm" aria-hidden="true"></i><span x-text="homeDynamicText.replaceImage"></span>
            </button>
        </div>
        <template x-if="ctrl.key === 'image_mobile' && selEl.data.image_mobile">
            <button type="button" @click="resetBannerMobileImage()" data-testid="blox-banner-mobile-reset"
                    class="inline-flex items-center gap-1 rounded border border-gray-200 px-2 py-1.5 text-xs text-gray-700 hover:border-blue-400">
                <i class="ti ti-arrow-back-up" aria-hidden="true"></i><?= e(__('blox_banner_mobile_image_reset')) ?>
            </button>
        </template>
        <details class="border-t border-gray-200">
            <summary class="py-1.5 text-xs text-gray-600 cursor-pointer" x-text="homeDynamicText.imageUrl"></summary>
            <input type="text" x-model="selEl.data[ctrl.key]" :aria-label="homeDynamicText.imageUrl"
                   placeholder="/uploads/images/xx.jpg" class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs bg-white">
        </details>
    </div>
</template>
