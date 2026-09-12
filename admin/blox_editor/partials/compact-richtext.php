<?php declare(strict_types=1);
$compactIsFaq = !empty($compactRichtextFaq);
$compactRead = $compactIsFaq
    ? '() => accordionAnswer(index)'
    : "() => selEl ? ({ id: selEl.id, text: selEl.data[ctrl.key], format: selEl.data[ctrl.format_key], allowLinks: selEl.type !== 'card' || !selEl.data.link }) : ({ id: null })";
$compactWrite = $compactIsFaq
    ? '(text, format) => setAccordionAnswer(index, text, format)'
    : '(text, format) => { selEl.data[ctrl.key] = text; if (format === undefined) delete selEl.data[ctrl.format_key]; else selEl.data[ctrl.format_key] = format; }';
$compactLabel = $compactIsFaq ? '(ctrl.body_label || homeDynamicText.faqAnswer)' : 'ctrl.label';
if (!empty($compactRichtextHomeFaq)) {
    $compactRead = '() => selectedHomeFaqAnswer()';
    $compactWrite = '(text, format) => setSelectedHomeFaqAnswer(text, format)';
    $compactLabel = 'homeDynamicText.faqAnswer';
}
?>
<div class="blox-description-control" data-testid="blox-description-control" x-id="['blox-description']"
     x-data="BloxCompactRichText.create(
        <?= e($compactRead) ?>,
        <?= e($compactWrite) ?>,
        { link: <?= e($jt('blox_desc_link')) ?> })">
    <div class="blox-description-heading">
        <label :for="$id('blox-description')" x-text="<?= e($compactLabel) ?>"></label>
        <button type="button" class="blox-description-source-toggle" data-testid="blox-description-source-toggle"
                @click="toggleSource()" :aria-pressed="sourceMode"
                :title="sourceMode ? <?= e($jt('blox_desc_visual')) ?> : <?= e($jt('blox_desc_html')) ?>"
                :aria-label="sourceMode ? <?= e($jt('blox_desc_visual')) ?> : <?= e($jt('blox_desc_html')) ?>">
            <i class="ti" :class="sourceMode ? 'ti-eye' : 'ti-code'" aria-hidden="true"></i>
        </button>
    </div>
    <div x-show="!sourceMode" x-ref="editor" :id="$id('blox-description')"
         class="blox-description-editor yk-description" data-testid="blox-description-editor"
         role="textbox" :aria-label="<?= e($compactLabel) ?>" aria-multiline="true" :aria-busy="!ready"></div>
    <textarea x-show="sourceMode" x-cloak x-ref="source" :value="sourceValue" @input="sourceInput($event.target.value)"
              class="blox-description-source" data-testid="blox-description-source" rows="5" maxlength="20000"
              aria-label="<?= e(__('blox_desc_html')) ?>" spellcheck="false"></textarea>
    <p x-show="failed" x-cloak class="mt-1 text-xs text-amber-700" role="status"><?= e(__('blox_desc_load_failed')) ?></p>
</div>
