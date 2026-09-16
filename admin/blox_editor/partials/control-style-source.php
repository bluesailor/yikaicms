<?php

declare(strict_types=1);
// 通用控件样式来源（主题 / 令牌 / 全局样式 / 响应式继承）。已由 style-source.php
// 给出共享样式溯源的控件不再重复显示，避免同一控件出现两行来源说明。
?>
<template x-if="!(window.BloxStyleSources && selEl && BloxStyleSources.supports(selEl.type, ctrl))">
<div x-show="panelTab === 'style'" class="flex items-center justify-between gap-1 mb-1.5 text-[10px] text-gray-400" :data-style-source="controlStyleSource(ctrl).source">
    <span x-text="styleSourceText[controlStyleSource(ctrl).source]"></span>
    <button type="button" x-show="controlStyleSource(ctrl).reset" @click="restoreControlStyle(ctrl)" class="text-blue-600" :data-testid="'blox-restore-style-' + ctrl.key"><?= e(__('blox_exp_restore')) ?></button>
    <button type="button" x-show="['theme','token','global'].includes(controlStyleSource(ctrl).source) && canManageDesign" @click="openDesignSystem(controlStyleSource(ctrl).source === 'global' ? 'styles' : 'colors')" class="text-blue-600"><?= e(__('blox_exp_source_open')) ?></button>
</div>
</template>
