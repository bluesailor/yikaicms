<?php

declare(strict_types=1);
?>
<template x-if="isHomeBannerHost(selTopEl) && panelTab === 'content'">
    <div class="border-y border-gray-200 py-3 space-y-2.5" data-testid="blox-banner-manager">
        <div class="flex items-center justify-between gap-2">
            <span class="text-xs font-semibold text-gray-700 inline-flex items-center gap-1.5">
                <i class="ti ti-carousel-horizontal text-sm"></i>
                <?php echo e(__('blox_home_banner_items')); ?>
            </span>
            <span class="inline-flex items-center gap-1">
                <span class="text-xs text-gray-600" x-text="homeBannerItemCount() + ' ' + homeDynamicText.items"></span>
                <?php foreach (['undo' => 'arrow-back-up', 'redo' => 'arrow-forward-up'] as $historyAction => $historyIcon): ?>
                <button type="button" @click="<?= e($historyAction) ?>()" :disabled="!can<?= $historyAction === 'undo' ? 'Undo' : 'Redo' ?>()"
                        data-testid="blox-banner-<?= e($historyAction) ?>" title="<?= e(__('blox_' . $historyAction)) ?>" aria-label="<?= e(__('blox_' . $historyAction)) ?>"
                        class="h-7 w-7 rounded text-gray-600 hover:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed">
                    <i class="ti ti-<?= e($historyIcon) ?> text-sm" aria-hidden="true"></i>
                </button>
                <?php endforeach; ?>
            </span>
        </div>
        <div class="text-xs text-gray-600" role="status" data-testid="blox-banner-source"
             x-text="hasCustomBannerItems() ? homeDynamicText.customItems : <?= e($jt('blox_home_banner_source_live')) ?>"></div>
        <template x-if="bannerPreviewItems().length">
            <div data-banner-manager>
                <div class="grid grid-cols-3 gap-1.5">
                    <template x-for="(item, bi) in bannerPreviewItems()" :key="item.id">
                        <div class="relative min-w-0 group/banner" data-banner-thumb>
                            <button type="button" @click="selectBannerItem(bi)"
                                    :aria-pressed="selectedSubEi === bi"
                                    :aria-label="homeDynamicText.editSlide + ' ' + (bi + 1)"
                                    :title="(item.data || {}).title || (homeDynamicText.editSlide + ' ' + (bi + 1))"
                                    class="w-full overflow-hidden rounded border-2 bg-white text-left transition"
                                    :class="selectedSubEi === bi ? 'border-blue-500 ring-2 ring-blue-100' : 'border-gray-200 hover:border-blue-400'">
                                <span class="block aspect-[16/10] bg-gray-100">
                                    <template x-if="item.data && item.data.image">
                                        <img :src="item.data.image" class="w-full h-full object-cover" alt="">
                                    </template>
                                    <template x-if="!item.data || !item.data.image">
                                        <span class="w-full h-full flex items-center justify-center text-gray-300"><i class="ti ti-photo-off text-lg"></i></span>
                                    </template>
                                </span>
                                <span class="block px-1.5 py-1 text-xs text-gray-700 truncate"
                                      x-text="(item.data || {}).title || (homeDynamicText.slide + ' ' + (bi + 1))"></span>
                            </button>
                            <button type="button" @click.stop="replaceBannerImage(bi)" data-banner-replace
                                    :title="homeDynamicText.replaceImage"
                                    :aria-label="homeDynamicText.replaceImage + ' ' + (bi + 1)"
                                    class="absolute top-1 right-1 w-6 h-6 rounded bg-white/95 shadow text-blue-600 hover:bg-blue-600 hover:text-white inline-flex items-center justify-center transition">
                                <i class="ti ti-photo-edit text-sm"></i>
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </template>
        <template x-if="!bannerPreviewItems().length">
            <div class="py-3 text-center text-xs text-gray-600"><?php echo e(__('blox_home_banner_empty')); ?></div>
        </template>
        <template x-if="hasCustomBannerItems() && selectedSubEi >= 0 && bannerItems()[selectedSubEi]">
            <div class="flex items-center gap-1" role="group" aria-label="<?= e(__('blox_banner_slide_actions')) ?>" data-testid="blox-banner-slide-actions">
                <?php foreach ([
                    'previous' => ['arrow-left', 'moveBannerItem(selectedSubEi, -1)', 'selectedSubEi === 0'],
                    'next' => ['arrow-right', 'moveBannerItem(selectedSubEi, 1)', 'selectedSubEi === bannerItems().length - 1'],
                    'duplicate' => ['copy', 'duplicateBannerItem(selectedSubEi)', 'false'],
                    'delete' => ['trash', 'deleteBannerItem(selectedSubEi)', 'false'],
                ] as $action => [$icon, $handler, $disabled]): ?>
                <button type="button" @click="<?= e($handler) ?>" :disabled="<?= e($disabled) ?>"
                        title="<?= e(__('blox_banner_action_' . $action)) ?>" aria-label="<?= e(__('blox_banner_action_' . $action)) ?>"
                        data-testid="blox-banner-action-<?= e($action) ?>"
                        class="h-8 flex-1 rounded border border-gray-200 bg-white text-gray-600 hover:border-gray-400 disabled:opacity-40 disabled:cursor-not-allowed <?= $action === 'delete' ? 'hover:text-red-600' : 'hover:text-blue-600' ?>">
                    <i class="ti ti-<?= e($icon) ?> text-base" aria-hidden="true"></i>
                </button>
                <?php endforeach; ?>
            </div>
        </template>
        <button type="button" @click="addBannerItem()" data-testid="blox-banner-add"
                class="w-full min-h-8 rounded border border-gray-200 bg-white px-2 py-1.5 text-gray-700 hover:border-blue-400 hover:text-blue-600 text-xs inline-flex items-center justify-center gap-1">
            <i class="ti ti-plus text-sm"></i><?php echo e(__('blox_home_banner_add')); ?>
        </button>
        <template x-if="hasCustomBannerItems()">
            <button type="button" @click="restoreBannerSource()" data-testid="blox-banner-restore"
                    class="w-full min-h-8 px-2 py-1.5 text-gray-600 hover:text-red-600 text-xs inline-flex items-center justify-center gap-1">
                <i class="ti ti-database text-sm shrink-0"></i><?php echo e(__('blox_home_banner_restore')); ?>
            </button>
        </template>
    </div>
</template>
