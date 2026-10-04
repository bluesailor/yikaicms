<?php
declare(strict_types=1);
// 样式来源与恢复继承（2.0.4，借鉴 WordPress 7.2）：控件下方一行说明当前生效值来自哪一层
// （本元素 / 沿用更大设备档 / 全局类 / 样式预设 / 主题），本元素的值压过了哪些来源（已覆盖），
// 并可一键恢复继承。计算见 assets/js/yikay-style-origin.js。
// $styleSourceControl 由父模板给出可信的 Alpine 控件表达式，从不来自请求数据。
?>
<div x-data="{ origin: { layers: [], effective: null, overridden: [], resettable: false } }"
     x-effect="origin = controlOrigin(<?= e($styleSourceControl) ?>)"
     x-show="panelTab === 'style' && origin.effective && origin.effective.kind !== 'default'"
     :data-testid="'blox-style-source-' + (<?= e($styleSourceControl) ?>).key"
     :data-origin="origin.effective ? origin.effective.kind : ''"
     :data-overridden="origin.overridden.map(function (layer) { return layer.kind; }).join(' ')"
     class="-mt-0.5 mb-1.5 space-y-0.5 text-[10px] leading-4 text-gray-500">
    <div class="flex items-center gap-1">
        <i class="ti text-[11px]" aria-hidden="true"
           :class="{ 'ti-point-filled text-blue-500': origin.effective && origin.effective.kind === 'local', 'ti-link': origin.effective && origin.effective.kind === 'inherit',
                     'ti-hash': origin.effective && origin.effective.kind === 'class', 'ti-palette': origin.effective && ['theme', 'preset'].includes(origin.effective.kind) }"></i>
        <span class="min-w-0 truncate">
            <span><?= e(__('blox_origin_label')) ?></span>
            <span class="font-medium text-gray-700" x-text="originLayerLabel(origin.effective)"></span>
            <span class="text-gray-400" x-show="originLayerValue(origin.effective)" x-text="'· ' + originLayerValue(origin.effective)"></span>
        </span>
        <button type="button" x-show="origin.resettable" @click="restoreControlOrigin(<?= e($styleSourceControl) ?>)"
                :data-testid="'blox-style-source-reset-' + (<?= e($styleSourceControl) ?>).key"
                class="ms-auto shrink-0 inline-flex items-center gap-0.5 text-blue-600 hover:underline"
                title="<?= e(__('blox_origin_restore_tip')) ?>">
            <i class="ti ti-restore" aria-hidden="true"></i><?= e(__('blox_exp_restore')) ?>
        </button>
        <button type="button" x-show="origin.effective && ['class', 'theme', 'preset'].includes(origin.effective.kind) && (origin.effective.kind === 'class' || canManageDesign)"
                @click="openOriginSource(origin.effective)"
                :data-testid="'blox-style-source-open-' + (<?= e($styleSourceControl) ?>).key"
                class="ms-auto shrink-0 text-blue-600 hover:underline"><?= e(__('blox_origin_edit')) ?></button>
    </div>
    <div x-show="origin.overridden.length" class="flex items-start gap-1 text-amber-700"
         :data-testid="'blox-style-overrides-' + (<?= e($styleSourceControl) ?>).key">
        <i class="ti ti-arrow-back-up text-[11px]" aria-hidden="true"></i>
        <span><span><?= e(__('blox_origin_overrides')) ?></span> <span x-text="originOverriddenText(<?= e($styleSourceControl) ?>)"></span></span>
    </div>
</div>
