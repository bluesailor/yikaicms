<?php
declare(strict_types=1);

// Select remains the storage contract; thumbnails only change its presentation.
?>
<div x-show="elementStyleTab() && effectiveStyleGroup() === 'animation'" class="mb-3 space-y-2">
    <p class="text-xs text-gray-500"><?= e(__('motion_title')) ?>：<?= e(__('motion_' . BloxMotion::level())) ?>。<?= e(__('motion_editor_hint')) ?></p>
    <button type="button" x-show="selEl && BloxControlRules.checkboxValue(selEl.data.animation_stagger)" @click="replayElementAnimation()" :disabled="previewLoading" class="blox-motion-replay" data-testid="blox-stagger-replay"><?= e(__('blox_anim_replay')) ?></button>
</div>
<template x-for="ctrl in visibleCtrls().filter(c => c.type === 'select' && c.option_preview)" :key="'preview-' + ctrl.key">
    <div class="blox-visual-select" :data-testid="'blox-control-' + ctrl.key" role="group" :aria-label="ctrl.label">
        <div class="blox-visual-select-heading">
            <span class="text-xs font-semibold text-gray-700" x-text="ctrl.label"></span>
            <template x-if="ctrl.option_preview === 'entrance'">
                <button type="button" class="blox-motion-replay" @click="replayElementAnimation()"
                        :disabled="!controlValue(ctrl) || previewLoading" data-testid="blox-animation-replay"
                        title="<?= e(__('blox_anim_replay')) ?>" aria-label="<?= e(__('blox_anim_replay')) ?>">
                    <i class="ti ti-player-play" aria-hidden="true"></i><span><?= e(__('blox_anim_replay')) ?></span>
                </button>
            </template>
        </div>
        <div class="blox-visual-options" :class="[2, 4].includes(Object.keys(controlOptions(ctrl)).length) ? 'blox-visual-options-pairs' : ''">
            <template x-for="(label, value) in controlOptions(ctrl)" :key="value">
                <button type="button" class="blox-visual-option" @click="setControlValue(ctrl, value)"
                        :aria-pressed="controlValue(ctrl) === value" :aria-label="label"
                        :disabled="!!(ctrl.option_requirements && ctrl.option_requirements[value] && !controlRequirementMet(ctrl.option_requirements[value]))"
                        :title="(ctrl.option_hints && ctrl.option_hints[value]) || label"
                        :data-testid="'blox-choice-' + ctrl.key + '-' + value">
                    <span class="blox-choice-preview" :data-kind="ctrl.option_preview" :data-value="value" aria-hidden="true">
                        <span class="blox-choice-sample" x-show="ctrl.option_preview !== 'table' && ctrl.option_preview !== 'entrance' && !ctrl.option_preview.startsWith('image-') && !ctrl.option_preview.startsWith('divider-')">
                            <span class="blox-choice-media"><i class="ti ti-photo"></i></span>
                            <span class="blox-choice-icon"><i class="ti" :class="ctrl.option_preview === 'quote-style' ? 'ti-quote' : (ctrl.option_preview.startsWith('alert-') ? 'ti-' + ((ctrl.option_icons || {})[value] || 'info-circle') : 'ti-star')"></i></span>
                            <span class="blox-choice-copy"><span></span><span></span><span></span></span>
                        </span>
                        <i x-show="ctrl.option_preview !== 'table' && ctrl.option_preview !== 'entrance' && !ctrl.option_preview.startsWith('image-')" class="blox-choice-motion ti" :class="value === 'lift' ? 'ti-arrow-up' : (value === 'zoom' ? 'ti-zoom-in' : (value === 'none' ? 'ti-ban' : 'ti-shadow'))"></i>
                        <template x-if="ctrl.option_preview === 'table'">
                            <span class="block w-full px-2">
                                <template x-for="r in 3" :key="r">
                                    <span class="grid grid-cols-3 border-b border-gray-300" :class="value === 'striped' && r === 2 ? 'bg-gray-200' : ''" :style="r === 1 && value === 'brand' ? 'background:var(--yk-color-primary,#2563eb)' : (r === 1 && value === 'dark' ? 'background:#1f2937' : '')">
                                        <template x-for="c in 3" :key="c"><span class="block h-3" :class="value === 'bordered' ? 'border border-gray-300' : ''"></span></template>
                                    </span>
                                </template>
                            </span>
                        </template>
                        <template x-if="ctrl.option_preview === 'image-ratio'">
                            <span class="blox-image-ratio-sample"><i class="ti ti-photo" aria-hidden="true"></i></span>
                        </template>
                        <template x-if="ctrl.option_preview.startsWith('divider-')">
                            <span class="blox-divider-sample"><span></span></span>
                        </template>
                        <template x-if="ctrl.option_preview === 'image-fit'">
                            <span class="blox-image-fit-sample"><img src="/assets/images/demo/yikaicms-industrial-600.webp" alt="" loading="lazy" decoding="async"></span>
                        </template>
                        <template x-if="ctrl.option_preview === 'image-position'">
                            <i class="ti blox-image-position-sample" :class="'ti-' + ((ctrl.option_icons || {})[value] || 'focus-centered')" aria-hidden="true"></i>
                        </template>
                        <template x-if="ctrl.option_preview === 'entrance'">
                            <span class="blox-entrance-stage">
                                <span class="blox-entrance-sample" x-show="value !== ''"><span></span><span></span></span>
                                <i class="ti blox-entrance-direction" :class="'ti-' + ((ctrl.option_icons || {})[value] || 'ban')"></i>
                            </span>
                        </template>
                    </span>
                    <span class="blox-choice-label" x-text="label"></span>
                </button>
            </template>
        </div>
    </div>
</template>
