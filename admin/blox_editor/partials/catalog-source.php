<?php
declare(strict_types=1);

if (!$isProductBlox && !$isContentListBlox) {
    return;
}
$catalogKind = $isProductBlox ? 'product' : 'article';
?>
<template x-if="selEl && selEl.type === '<?= $isProductBlox ? 'product-catalog' : 'content-catalog' ?>'">
    <div x-data="BloxCatalogSource.create(<?= (int) $id ?>, csrf, '<?= e($catalogKind) ?>')" class="pt-2" data-testid="blox-catalog-source">
        <button type="button" @click="toggle()" :aria-expanded="expanded" aria-controls="blox-catalog-items"
                class="flex items-center gap-1 w-full min-h-8 text-left text-xs font-medium text-gray-800">
            <i class="ti shrink-0" :class="expanded ? 'ti-chevron-down' : 'ti-chevron-right'" aria-hidden="true"></i>
            <?= e(__($isProductBlox ? 'blox_catalog_products' : 'blox_catalog_articles')) ?>
        </button>
        <div x-show="expanded" id="blox-catalog-items" class="space-y-2 pb-2">
            <button type="button" @click="load(page); refreshPreview()" :disabled="loading || previewLoading"
                    :aria-busy="loading || previewLoading" data-testid="blox-catalog-refresh"
                    class="flex items-center justify-center gap-2 w-full min-h-8 px-2 py-1 border border-gray-300 rounded text-xs text-gray-800 hover:bg-gray-100 disabled:opacity-50 disabled:cursor-wait">
                <i class="ti ti-refresh shrink-0" aria-hidden="true"></i>
                <span class="min-w-0 break-words"><?= e(__('blox_catalog_refresh_preview')) ?></span>
            </button>
            <form @submit.prevent="load(1)" class="flex items-center gap-1">
                <input type="search" x-model="keyword" maxlength="120" class="min-w-0 w-full border border-gray-300 rounded px-2 h-8 text-xs"
                       aria-label="<?= e(__('admin_search')) ?>" placeholder="<?= e(__('admin_search')) ?>">
                <button type="submit" class="shrink-0 w-8 h-8 rounded border border-gray-300 hover:bg-gray-100"
                        title="<?= e(__('admin_search')) ?>" aria-label="<?= e(__('admin_search')) ?>">
                    <i class="ti ti-search" aria-hidden="true"></i>
                </button>
            </form>
            <p class="text-xs text-gray-600"><?= e(__('blox_catalog_published_items')) ?></p>
            <div role="status" aria-live="polite" class="text-xs text-gray-700">
                <span x-show="loading"><?= e(__('admin_loading')) ?></span>
                <span x-show="!loading && !failed && items.length === 0"><?= e(__('blox_catalog_empty')) ?></span>
                <span x-show="failed"><?= e(__('blox_catalog_load_failed')) ?></span>
            </div>
            <button type="button" x-show="failed" @click="load(1)" class="text-xs text-blue-700 hover:underline">
                <?= e(__('blox_catalog_retry')) ?>
            </button>
            <ul class="divide-y divide-gray-200">
                <template x-for="item in items" :key="item.id">
                    <li>
                        <a :href="editUrl(item)" target="_blank" rel="noopener" data-testid="blox-catalog-item"
                           class="flex items-center gap-2 py-2 text-xs text-gray-800 hover:text-blue-700"
                           title="<?= e(__('blox_source_new_tab')) ?>">
                            <span class="w-9 h-9 shrink-0 bg-gray-100 rounded flex items-center justify-center overflow-hidden" x-data="{ imageFailed: false }">
                                <img x-show="item.cover && !imageFailed" :src="item.cover || null" @error="imageFailed = true" alt="" class="w-full h-full object-cover">
                                <i x-show="!item.cover || imageFailed" class="ti ti-file-text" aria-hidden="true"></i>
                            </span>
                            <span class="min-w-0 flex-1 break-words" x-text="item.title"></span>
                            <i class="ti ti-external-link shrink-0" aria-hidden="true"></i>
                        </a>
                    </li>
                </template>
            </ul>
            <div x-show="!loading && !failed && (page > 1 || hasMore)" class="flex items-center justify-between text-xs">
                <button type="button" @click="load(page - 1)" :disabled="page <= 1" class="w-8 h-8 border rounded disabled:opacity-40 hover:bg-gray-100"
                        title="<?= e(__('blox_catalog_previous')) ?>" aria-label="<?= e(__('blox_catalog_previous')) ?>">
                    <i class="ti ti-chevron-left" aria-hidden="true"></i>
                </button>
                <span x-text="page"></span>
                <button type="button" @click="load(page + 1)" :disabled="!hasMore" class="w-8 h-8 border rounded disabled:opacity-40 hover:bg-gray-100"
                        title="<?= e(__('blox_catalog_next')) ?>" aria-label="<?= e(__('blox_catalog_next')) ?>">
                    <i class="ti ti-chevron-right" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </div>
</template>
