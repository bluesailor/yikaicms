<?php
declare(strict_types=1);
// 独立片段保留直接访问保护；正常包含时入口已定义 ROOT_PATH。
/** @psalm-suppress ParadoxicalCondition */
if (!defined('ROOT_PATH')) exit('Access Denied');
$faviconCanPreview = (bool) ($__imageCanPreview ?? false);
?>
<div data-testid="favicon-preview" id="faviconPreview" class="mt-2"
     data-initial-preview="<?= $faviconCanPreview ? '1' : '0' ?>"
     data-shape-message="<?= e(__('usability_favicon_shape')) ?>"
     data-small-message="<?= e(__('usability_favicon_small')) ?>"
     data-error-message="<?= e(__('usability_favicon_error')) ?>"
     data-size-message="<?= e(__('usability_favicon_size')) ?>">
    <p class="text-sm text-gray-600" id="faviconPreviewHelp"><?= e(__('usability_favicon_help')) ?></p>
    <div data-favicon-samples class="flex items-end gap-4 mt-2" hidden>
        <?php foreach ([16, 32, 48] as $faviconSize): ?>
        <figure class="text-center">
            <div class="border rounded p-2 bg-white inline-flex">
                <img data-favicon-sample alt="" width="<?= $faviconSize ?>" height="<?= $faviconSize ?>" style="width:<?= $faviconSize ?>px;height:<?= $faviconSize ?>px;object-fit:contain">
            </div>
            <figcaption class="text-xs text-gray-500"><?= $faviconSize ?> × <?= $faviconSize ?></figcaption>
        </figure>
        <?php endforeach; ?>
    </div>
    <p data-favicon-dimensions class="text-xs text-gray-500 mt-2"></p>
    <p data-favicon-warning role="status" aria-live="polite" class="text-sm text-amber-700 mt-2" hidden></p>
</div>
