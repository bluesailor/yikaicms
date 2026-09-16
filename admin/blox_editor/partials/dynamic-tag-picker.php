<?php declare(strict_types=1); ?>
<div class="relative" @click.outside="siteTagTarget = siteTagTarget === selEl.id + ':' + <?= e($dynamicTagKey) ?> ? '' : siteTagTarget"
     @keydown.escape.stop="siteTagTarget = ''">
    <button type="button" @click="openSiteTags(<?= e($dynamicTagKey) ?>)" data-testid="blox-dynamic-insert"
            :aria-expanded="siteTagTarget === selEl.id + ':' + <?= e($dynamicTagKey) ?>"
            title="<?= e(__('blox_dynamic_insert')) ?>" aria-label="<?= e(__('blox_dynamic_insert')) ?>"
            class="inline-flex h-7 w-7 items-center justify-center rounded text-gray-500 hover:bg-blue-50 hover:text-blue-600">
        <i class="ti ti-bolt" aria-hidden="true"></i>
    </button>
    <div x-show="siteTagTarget === selEl.id + ':' + <?= e($dynamicTagKey) ?>" x-cloak
         class="absolute right-0 top-full z-30 w-64 max-w-[80vw] rounded border border-gray-200 bg-white p-2 shadow-lg">
        <input type="search" x-model="siteTagSearch" placeholder="<?= e(__('blox_dynamic_search')) ?>"
               aria-label="<?= e(__('blox_dynamic_search')) ?>" class="mb-1 w-full rounded border border-gray-200 px-2 py-1.5 text-xs">
        <div class="max-h-56 overflow-y-auto">
            <template x-for="(label, tag) in siteDynamicOptions(<?= e($dynamicTagLinks) ?>)" :key="tag">
                <button type="button" x-show="(label + tag).toLowerCase().includes(siteTagSearch.toLowerCase())"
                        @click="insertSiteTag(<?= e($dynamicTagKey) ?>, tag)"
                        class="flex w-full items-center justify-between gap-2 rounded px-2 py-2 text-left text-xs text-gray-700 hover:bg-blue-50">
                    <span x-text="label"></span><code class="text-[10px] text-gray-400" x-text="tag"></code>
                </button>
            </template>
        </div>
    </div>
</div>
