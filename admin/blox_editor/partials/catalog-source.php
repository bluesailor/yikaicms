<?php
declare(strict_types=1);

if (!$isProductBlox && !$isContentListBlox) {
    return;
}
$catalogKind = $isProductBlox ? 'product' : 'article';
$catalogLanguage = (string) ($page['lang'] ?? siteLang());
$catalogLanguageLabel = isMultiLangEnabled($isProductBlox ? 'products' : 'contents')
    ? (availableLanguages()[$catalogLanguage] ?? $catalogLanguage) : __('blox_cond_all_languages');
?>
<template x-if="selEl && selEl.type === '<?= $isProductBlox ? 'product-catalog' : 'content-catalog' ?>'">
    <div x-data="BloxCatalogSource.create(<?= (int) $id ?>, csrf, '<?= e($catalogKind) ?>')" class="pt-2" data-testid="blox-catalog-source">
        <button type="button" @click="toggle()" :aria-expanded="expanded" aria-controls="blox-catalog-items"
                class="flex items-center gap-1 w-full min-h-8 text-left text-xs font-medium text-gray-800">
            <i class="ti shrink-0" :class="expanded ? 'ti-chevron-down' : 'ti-chevron-right'" aria-hidden="true"></i>
            <?= e(__($isProductBlox ? 'blox_catalog_products' : 'blox_catalog_articles')) ?>
        </button>
        <div x-show="expanded" id="blox-catalog-items" class="space-y-2 pb-2">
            <dl class="space-y-1 text-xs text-gray-700" data-testid="blox-catalog-scope">
                <div class="flex items-start gap-2">
                    <dt class="shrink-0"><?= e(__('blox_cond_language')) ?></dt>
                    <dd class="min-w-0 break-words" data-testid="blox-catalog-language" data-language="<?= e($catalogLanguage) ?>"><?= e($catalogLanguageLabel) ?></dd>
                </div>
                <div class="flex items-start gap-2">
                    <dt class="shrink-0"><?= e(__('blox_catalog_scope_label')) ?></dt>
                    <dd class="min-w-0 break-words" data-testid="blox-catalog-range"><?= e($isProductBlox
                        ? __('blox_catalog_scope_all_products')
                        : __('blox_catalog_scope_descendants', ['name' => (string) ($page['name'] ?? '')])) ?></dd>
                </div>
            </dl>
            <button type="button" @click="load(requestPage, requestKeyword); refreshPreview()" :disabled="loading || previewLoading"
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
                <span x-show="emptyState === 'unpublished'" data-testid="blox-catalog-unpublished"><?= e(__($isProductBlox ? 'blox_catalog_no_products' : 'blox_catalog_no_articles')) ?></span>
                <span x-show="emptyState === 'search'" data-testid="blox-catalog-no-match"><?= e(__('blox_catalog_empty')) ?></span>
                <span x-show="emptyState === 'page'" data-testid="blox-catalog-empty-page"><?= e(__('blox_catalog_page_empty')) ?></span>
                <span x-show="failed"><?= e(__('blox_catalog_load_failed')) ?></span>
            </div>
            <div x-show="emptyState === 'search' || emptyState === 'page'" class="flex flex-wrap items-center gap-2">
                <button type="button" x-show="emptyState === 'page'" @click="keyword = resultKeyword; load(1)"
                        data-testid="blox-catalog-first-page" class="inline-flex items-center gap-1 min-h-8 text-xs text-blue-700 hover:underline">
                    <i class="ti ti-arrow-bar-to-left shrink-0" aria-hidden="true"></i>
                    <span class="min-w-0 break-words"><?= e(__('blox_catalog_first_page')) ?></span>
                </button>
                <button type="button" x-show="resultKeyword !== ''" @click="keyword = ''; load(1)"
                        data-testid="blox-catalog-clear-search" class="inline-flex items-center gap-1 min-h-8 text-xs text-blue-700 hover:underline">
                    <i class="ti ti-x shrink-0" aria-hidden="true"></i>
                    <span class="min-w-0 break-words"><?= e(__('blox_catalog_clear_search')) ?></span>
                </button>
            </div>
            <button type="button" x-show="failed" @click="load(requestPage, requestKeyword)" class="text-xs text-blue-700 hover:underline">
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
                            <span class="min-w-0 flex-1 break-words">
                                <span class="block" x-text="item.title" data-testid="blox-catalog-item-title"></span>
                                <span class="block text-gray-600" x-show="item.source_label" x-text="item.source_label || ''" data-testid="blox-catalog-item-source"></span>
                            </span>
                            <i class="ti ti-external-link shrink-0" aria-hidden="true"></i>
                        </a>
                    </li>
                </template>
            </ul>
            <div x-show="!loading && !failed && (page > 1 || hasMore)" class="flex items-center justify-between text-xs">
                <button type="button" @click="load(page - 1, resultKeyword)" :disabled="page <= 1" class="w-8 h-8 border rounded disabled:opacity-40 hover:bg-gray-100"
                        title="<?= e(__('blox_catalog_previous')) ?>" aria-label="<?= e(__('blox_catalog_previous')) ?>">
                    <i class="ti ti-chevron-left" aria-hidden="true"></i>
                </button>
                <span x-text="page"></span>
                <button type="button" @click="load(page + 1, resultKeyword)" :disabled="!hasMore" class="w-8 h-8 border rounded disabled:opacity-40 hover:bg-gray-100"
                        title="<?= e(__('blox_catalog_next')) ?>" aria-label="<?= e(__('blox_catalog_next')) ?>">
                    <i class="ti ti-chevron-right" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </div>
</template>
