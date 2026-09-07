<?php

declare(strict_types=1);
?>
<div x-show="panelTab === 'style'" class="flex items-center justify-between gap-1 mb-1.5 text-[10px] text-gray-400" :data-style-source="controlStyleSource(ctrl).source">
    <span x-text="styleSourceText[controlStyleSource(ctrl).source]"></span>
    <button type="button" x-show="controlStyleSource(ctrl).reset" @click="restoreControlStyle(ctrl)" class="text-blue-600" :data-testid="'blox-restore-style-' + ctrl.key"><?= e(__('blox_exp_restore')) ?></button>
    <button type="button" x-show="['theme','token','global'].includes(controlStyleSource(ctrl).source) && canManageDesign" @click="openDesignSystem(controlStyleSource(ctrl).source === 'global' ? 'styles' : 'colors')" class="text-blue-600"><?= e(__('blox_exp_source_open')) ?></button>
</div>
