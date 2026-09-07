<?php

declare(strict_types=1);
/** @var string $sourceField */
/** @var string $sourceFallback */
?>
<div class="flex items-center justify-between gap-1 mb-1.5 text-[10px] text-gray-400" :data-section-style-source="'<?= e($sourceField) ?>:' + sectionStyleSource('<?= e($sourceField) ?>', '<?= e($sourceFallback) ?>').source">
    <span x-text="styleSourceText[sectionStyleSource('<?= e($sourceField) ?>', '<?= e($sourceFallback) ?>').source]"></span>
    <button type="button" x-show="sectionStyleSource('<?= e($sourceField) ?>', '<?= e($sourceFallback) ?>').reset" @click="restoreSectionStyle('<?= e($sourceField) ?>', '<?= e($sourceFallback) ?>')" class="text-blue-600"><?= e(__('blox_exp_restore')) ?></button>
</div>
