<?php

declare(strict_types=1);
/** Yikai Builder Pro：价格方案的套餐编辑器（ctrl.type === 'pricing_plans'），经 blox_editor_panel 'pricing_plans' 插槽挂到控件循环。 */
$pricingText = json_encode([
    'name' => __('blox_pricing_plan_name'),
    'badge' => __('blox_pricing_plan_badge'),
    'price' => __('blox_pricing_plan_price'),
    'priceYearly' => __('blox_pricing_plan_price_yearly'),
    'period' => __('blox_pricing_plan_period'),
    'periodYearly' => __('blox_pricing_plan_period_yearly'),
    'description' => __('blox_pricing_plan_description'),
    'features' => __('blox_pricing_plan_features'),
    'buttonText' => __('blox_pricing_plan_button_text'),
    'buttonUrl' => __('blox_pricing_plan_button_url'),
    'featured' => __('blox_pricing_plan_featured'),
    'add' => __('blox_pricing_add_plan'),
    'delete' => __('blox_pricing_delete_plan'),
    'newPlan' => __('blox_pricing_new_plan'),
    'up' => __('blox_ctx_move_up'),
    'down' => __('blox_ctx_move_down'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<template x-if="ctrl.type === 'pricing_plans' && professionalFeatures.pricing.allowed">
    <div data-testid="blox-pricing-plans" class="space-y-2" x-data="{ pt: <?= $pricingText ?> }">
        <template x-for="(plan, index) in pricingPlans(selEl)" :key="'plan-' + index">
            <div data-testid="blox-pricing-plan" class="rounded border p-2.5 space-y-1.5"
                 :class="plan.featured ? 'border-blue-300 bg-blue-50/60' : 'border-gray-200 bg-gray-50/70'">
                <div class="flex items-center gap-1">
                    <i class="ti text-sm" :class="plan.featured ? 'ti-star-filled text-amber-500' : 'ti-tag text-gray-400'" aria-hidden="true"></i>
                    <span class="min-w-0 flex-1 truncate text-[10px] font-semibold text-gray-500" x-text="(index + 1) + ' · ' + (plan.name || pt.newPlan)"></span>
                    <button type="button" @click.stop="movePricingPlan(index, -1)" :disabled="index === 0" :title="pt.up" :aria-label="pt.up"
                            class="w-6 h-6 rounded text-gray-400 hover:bg-white hover:text-blue-600 disabled:text-gray-200 inline-flex items-center justify-center">
                        <i class="ti ti-arrow-up text-xs"></i>
                    </button>
                    <button type="button" @click.stop="movePricingPlan(index, 1)" :disabled="index === pricingPlans(selEl).length - 1" :title="pt.down" :aria-label="pt.down"
                            class="w-6 h-6 rounded text-gray-400 hover:bg-white hover:text-blue-600 disabled:text-gray-200 inline-flex items-center justify-center">
                        <i class="ti ti-arrow-down text-xs"></i>
                    </button>
                    <button type="button" @click.stop="deletePricingPlan(index)" :disabled="pricingPlans(selEl).length <= 1" :title="pt.delete" :aria-label="pt.delete"
                            data-testid="blox-pricing-delete"
                            class="w-6 h-6 rounded text-gray-400 hover:bg-red-50 hover:text-red-600 disabled:text-gray-200 inline-flex items-center justify-center">
                        <i class="ti ti-trash text-xs"></i>
                    </button>
                </div>
                <?php
                // 单行字段：[数据键, 文案键]
                foreach ([['name', 'name'], ['badge', 'badge'], ['price', 'price'], ['period', 'period'],
                          ['price_yearly', 'priceYearly'], ['period_yearly', 'periodYearly'],
                          ['description', 'description'], ['button_text', 'buttonText'], ['button_url', 'buttonUrl']] as [$field, $label]):
                ?>
                <label class="block">
                    <span class="mb-0.5 block text-[9px] font-medium text-gray-400" x-text="pt.<?= $label ?>"></span>
                    <input type="text" :value="plan.<?= $field ?>" @input="setPricingPlan(index, '<?= $field ?>', $event.target.value)"
                           data-testid="blox-pricing-<?= str_replace('_', '-', $field) ?>"
                           class="w-full rounded border border-gray-200 bg-white px-2 py-1 text-xs">
                </label>
                <?php endforeach; ?>
                <label class="block">
                    <span class="mb-0.5 block text-[9px] font-medium text-gray-400" x-text="pt.features"></span>
                    <textarea rows="4" :value="plan.features" @input="setPricingPlan(index, 'features', $event.target.value)"
                              data-testid="blox-pricing-features"
                              class="w-full rounded border border-gray-200 bg-white px-2 py-1 text-xs leading-relaxed"></textarea>
                </label>
                <label class="flex items-center gap-1.5 text-[11px] text-gray-600">
                    <input type="checkbox" :checked="plan.featured" @change="setPricingPlan(index, 'featured', $event.target.checked)"
                           data-testid="blox-pricing-featured">
                    <span x-text="pt.featured"></span>
                </label>
            </div>
        </template>
        <button type="button" @click="addPricingPlan(pt.newPlan)" x-show="pricingPlans(selEl).length < pricingPlanMax()"
                data-testid="blox-pricing-add"
                class="w-full h-8 rounded border border-dashed border-blue-300 bg-white text-[11px] font-medium text-blue-600 hover:bg-blue-50 inline-flex items-center justify-center gap-1">
            <i class="ti ti-plus text-xs" aria-hidden="true"></i><span x-text="pt.add"></span>
        </button>
    </div>
</template>
