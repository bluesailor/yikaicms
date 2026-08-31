<?php

declare(strict_types=1);
?>
<template x-if="panelTab === 'content' && homeContentSource()">
    <div class="py-2 border-b border-gray-200 space-y-1" data-testid="blox-content-source">
        <a :href="homeContentSource().url" target="_blank" rel="noopener"
           class="inline-flex items-center gap-1 text-xs text-blue-700 hover:underline"
           title="<?= e(__('blox_source_new_tab')) ?>">
            <i class="ti ti-external-link" aria-hidden="true"></i>
            <span x-text="homeContentSource().label"></span>
        </a>
        <p class="text-xs text-gray-600" x-text="homeContentSource().scope"
           <?php if ($isProductBlox || $isContentListBlox): ?>
               x-show="selEl && selEl.type !== '<?= $isProductBlox ? 'product-catalog' : 'content-catalog' ?>'"
           <?php endif; ?>></p>
        <?php require __DIR__ . '/catalog-source.php'; ?>
    </div>
</template>
