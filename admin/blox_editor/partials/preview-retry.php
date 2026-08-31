<?php
declare(strict_types=1);
?>
<button type="button" x-show="previewFailed" x-cloak @click="refreshPreview()" :disabled="previewLoading"
        data-testid="<?= e($previewRetryId) ?>" title="<?= e(__('blox_preview_failed')) ?>" aria-label="<?= e(__('blox_preview_retry')) ?>"
        class="inline-flex items-center justify-center gap-1 rounded px-2 py-1.5 text-xs text-current hover:underline disabled:opacity-40">
    <i class="ti ti-refresh" aria-hidden="true"></i><span><?= e(__('blox_preview_retry')) ?></span>
</button>
