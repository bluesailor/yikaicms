<?php
/** Compact, keyboard-native language disclosure shared by page, home and area editors. */
declare(strict_types=1);

$currentLanguage = $languageItems[0] ?? ['short' => '', 'label' => ''];
foreach ($languageItems as $languageItem) {
    if ($languageItem['current']) $currentLanguage = $languageItem;
}
$orderedLanguageItems = array_merge(
    array_filter($languageItems, static fn(array $item): bool => $item['current']),
    array_filter($languageItems, static fn(array $item): bool => !$item['current'])
);
?>
<details class="blox-header-languages" data-testid="<?= e($languageTestId) ?>"
         @click.outside="$el.open = false"
         @keydown.escape.stop.prevent="$el.open = false; $el.querySelector('summary').focus()">
    <summary data-testid="<?= e($languageTestId . '-toggle') ?>"
             title="<?= e($currentLanguage['label']) ?>"
             aria-label="<?= e(__('lse_versions') . ' ' . $currentLanguage['short'] . ' · ' . $currentLanguage['label']) ?>">
        <i class="ti ti-language" aria-hidden="true"></i>
        <span><?= e($currentLanguage['short']) ?></span>
        <i class="ti ti-chevron-down" aria-hidden="true"></i>
    </summary>
    <div class="blox-language-menu">
        <?php foreach ($orderedLanguageItems as $languageItem): ?>
        <a href="<?= e($languageItem['url']) ?>" @click="requestEditorNavigation($event)"
           data-testid="<?= e($languageItem['testid']) ?>"
           <?php if (isset($languageItem['state'])): ?>data-area-language-state="<?= e($languageItem['state']) ?>"<?php endif; ?>
           <?php if ($languageItem['current']): ?>aria-current="page"<?php endif; ?>
           title="<?= e($languageItem['title']) ?>">
            <span class="blox-language-code"><?= e($languageItem['short']) ?></span>
            <span class="blox-language-label"><?= e($languageItem['label']) ?>
                <?php if ($languageItem['note'] !== ''): ?><small><?= e($languageItem['note']) ?></small><?php endif; ?>
            </span>
            <?php if ($languageItem['current']): ?><i class="ti ti-check" aria-hidden="true"></i><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
</details>
