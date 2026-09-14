<?php
declare(strict_types=1);

if (!defined('ROOT_PATH') || !isset($importReview, $importJson)) {
    exit('Access Denied');
}
$diagnostic = $importReview['design_diagnostics'];
$designCatalog = BloxDesignSystem::snapshot();
?>
<section id="blox-import-review" class="border-y border-gray-200 bg-white" data-testid="blox-import-review">
    <div class="px-5 py-4 border-b border-gray-200">
        <h2 class="break-words font-semibold text-gray-900"><?= e(__('blox_import_review')) ?>: <?= e($importReview['name']) ?></h2>
        <p class="mt-1 text-sm text-gray-600"><?= e(__('blox_import_design_hint')) ?></p>
    </div>
    <form method="post" class="min-w-0 p-5 space-y-4" x-data="{ styleMode: 'keep' }">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="import_confirm">
        <textarea name="template_json" hidden><?= e($importJson) ?></textarea>
        <?php foreach (['missing', 'archived', 'conflicting', 'same_name', 'unverified'] as $issue): ?>
            <?php foreach (['tokens', 'styles'] as $kind): ?>
                <?php if (($diagnostic[$issue . '_' . $kind] ?? []) !== []): ?>
                    <p class="break-words text-sm text-amber-700" data-testid="blox-import-<?= e($issue . '-' . $kind) ?>">
                        <?= e(__('blox_import_' . $issue)) ?> · <?= e(__('blox_import_' . $kind)) ?>:
                        <?= e(implode(', ', $diagnostic[$issue . '_' . $kind])) ?>
                    </p>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <fieldset class="min-w-0">
            <legend class="mb-2 text-sm font-medium text-gray-900"><?= e(__('blox_import_styles')) ?></legend>
            <label class="mr-4 inline-flex items-center gap-2 text-sm"><input type="radio" name="style_mode" value="keep" x-model="styleMode" checked><?= e(__('blox_import_keep')) ?></label>
            <label class="inline-flex items-center gap-2 text-sm"><input type="radio" name="style_mode" value="detach" x-model="styleMode"><?= e(__('blox_import_detach')) ?></label>
        </fieldset>
        <?php foreach (['tokens', 'styles'] as $kind): ?>
            <?php $references = $importReview['requirements']['design_' . $kind] ?? []; ?>
            <?php if ($references !== []): ?>
                <fieldset class="min-w-0 space-y-2" <?php if ($kind === 'styles'): ?>x-show="styleMode !== 'detach'" :disabled="styleMode === 'detach'"<?php endif; ?>>
                    <legend class="mb-2 text-sm font-medium text-gray-900"><?= e(__('blox_import_map')) ?> · <?= e(__('blox_import_' . $kind)) ?></legend>
                    <?php foreach ($references as $reference): ?>
                        <?php if (preg_match('/^[a-z][a-z0-9_-]{0,47}$/D', $reference) !== 1) { continue; } ?>
                        <label class="flex flex-wrap items-center gap-3 text-sm">
                            <span><?= e($reference) ?></span>
                            <select name="design_<?= e($kind) ?>[<?= e($reference) ?>]" class="max-w-full border border-gray-300 bg-white px-3 py-2">
                                <option value=""><?= e(__('blox_import_unchanged')) ?></option>
                                <?php foreach ($designCatalog[$kind] as $item): ?>
                                    <?php if (($item['status'] ?? '') === 'archived') { continue; } ?>
                                    <option value="<?= e($item['id']) ?>"><?= e($item['name'] . ' (' . $item['id'] . ')') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            <?php endif; ?>
        <?php endforeach; ?>
        <div class="flex flex-wrap items-center gap-4">
            <button type="submit" class="inline-flex h-10 items-center justify-center gap-2 bg-blue-600 px-5 text-sm font-medium text-white hover:bg-blue-700" data-testid="blox-import-confirm"><i class="ti ti-file-import" aria-hidden="true"></i><?= e(__('blox_import_confirm')) ?></button>
            <a href="/admin/blox_templates.php" class="text-sm text-gray-600"><?= e(__('blox_import_cancel')) ?></a>
        </div>
    </form>
</section>
