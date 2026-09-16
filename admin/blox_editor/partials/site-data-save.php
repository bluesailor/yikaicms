<?php

declare(strict_types=1);
/** @var string $siteDataSaveTestId 版权面板与备案面板共用的站点资料保存行 */
?>
<div x-show="siteCopyright.can_edit" class="flex items-center justify-between gap-2">
    <span class="text-[10px] text-amber-600"><?= e(__('blox_site_copyright_live_note')) ?></span>
    <button type="button" @click="saveSiteCopyright()"
            :disabled="!siteCopyrightChanged || siteCopyrightSaving"
            data-testid="<?= e($siteDataSaveTestId) ?>"
            class="h-8 shrink-0 inline-flex items-center gap-1.5 rounded bg-blue-600 px-2.5 text-[11px] font-medium text-white hover:bg-blue-700 disabled:bg-gray-200 disabled:text-gray-400 transition">
        <i class="ti text-sm" :class="siteCopyrightSaving ? 'ti-loader-2 animate-spin' : 'ti-device-floppy'" aria-hidden="true"></i>
        <span><?= e(__('blox_site_copyright_save')) ?></span>
    </button>
</div>
