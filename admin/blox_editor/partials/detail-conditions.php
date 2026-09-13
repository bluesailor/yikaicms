<?php
/**
 * 完整条件面板（TASK-006 第一批）。
 *
 * 编辑的是 v2 作用域的多条规则：include 的 all/item/category（含子级）与 exclude（沿用 resolver
 * 允许的类型，即不含 all）。保存时经 `conditions_json` 完整通道提交，与旧 `ui_scope` 互斥；
 * 命中语义一律由服务端 DetailTemplateResolver 判定，本面板不做客户端匹配。
 */
declare(strict_types=1);
?>
<template x-if="conditionContentType !== ''">
    <div class="border-t border-gray-200 mt-3 pt-3" data-testid="blox-detail-conditions" x-init="conditionEnsure()">
        <p class="text-xs font-medium text-gray-700 mb-2"><?= e(__('blox_cond_title')) ?></p>
        <?php foreach (['include' => 'blox_cond_include', 'exclude' => 'blox_cond_exclude'] as $side => $sideLabel): ?>
        <div class="mb-3">
            <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
                <span class="text-xs text-gray-600"><?= e(__($sideLabel)) ?></span>
                <span class="flex flex-wrap gap-1">
                    <?php if ($side === 'include'): ?>
                    <button type="button" @click="conditionAdd('include', 'all')" data-testid="blox-cond-add-include-all"
                            class="text-[11px] px-2 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50"><?= e(__('blox_article_scope_all')) ?></button>
                    <?php endif; ?>
                    <button type="button" @click="conditionAdd('<?= e($side) ?>', 'item')" data-testid="blox-cond-add-<?= e($side) ?>-item"
                            class="text-[11px] px-2 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50"><?= e(__('blox_cond_add_item')) ?></button>
                    <button type="button" @click="conditionAdd('<?= e($side) ?>', 'category')" data-testid="blox-cond-add-<?= e($side) ?>-category"
                            class="text-[11px] px-2 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50"><?= e(__('blox_cond_add_category')) ?></button>
                </span>
            </div>
            <template x-for="(row, index) in (conditionRows ? conditionRows['<?= e($side) ?>'] : [])" :key="'<?= e($side) ?>-' + index">
                <div class="flex flex-wrap items-center gap-2 py-1" :data-testid="'blox-cond-<?= e($side) ?>-row-' + index">
                    <select x-model="row.kind" :data-testid="'blox-cond-<?= e($side) ?>-kind-' + index"
                            class="border border-gray-300 rounded px-2 py-1 text-xs">
                        <?php if ($side === 'include'): ?>
                        <option value="all"><?= e(__('blox_article_scope_all')) ?></option>
                        <?php endif; ?>
                        <option value="item"><?= e(__('blox_cond_kind_item')) ?></option>
                        <option value="category"><?= e(__('blox_cond_kind_category')) ?></option>
                    </select>
                    <template x-if="row.kind !== 'all'">
                        <select multiple x-model="row.ids" :data-testid="'blox-cond-<?= e($side) ?>-target-' + index"
                                class="border border-gray-300 rounded px-2 py-1 text-xs min-w-[12rem] max-h-24">
                            <?php // 选项由 x-for 后建，x-model 的首次同步可能早于选项存在——那样重开时会"看着没选中"。
                                  // 所以每条 option 自己按 row.ids 绑定 selected，不依赖先后顺序。 ?>
                            <template x-for="opt in (row.kind === 'category' ? conditionCategories : conditionItems)" :key="opt.id">
                                <option :value="String(opt.id)" x-text="opt.name"
                                        :selected="(row.ids || []).map(String).includes(String(opt.id))"></option>
                            </template>
                        </select>
                    </template>
                    <label x-show="row.kind === 'category'" class="inline-flex items-center gap-1 text-[11px] text-gray-600">
                        <input type="checkbox" x-model="row.include_children" :data-testid="'blox-cond-<?= e($side) ?>-children-' + index">
                        <span><?= e(__('blox_scope_children')) ?></span>
                    </label>
                    <button type="button" @click="conditionRemove('<?= e($side) ?>', index)" :data-testid="'blox-cond-remove-<?= e($side) ?>-' + index"
                            class="text-[11px] px-2 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50"><?= e(__('blox_cond_remove')) ?></button>
                </div>
            </template>
        </div>
        <?php endforeach; ?>
        <?php // 说清"不应用"与"主题默认"的区别：前者是空 include，后者是 source=native ?>
        <p class="text-[11px] leading-relaxed text-gray-500" data-testid="blox-cond-notes"><?= e(__('blox_cond_notes')) ?></p>
        <p x-show="conditionDirty()" x-cloak data-testid="blox-cond-dirty" class="mt-1 text-[11px] text-amber-600"><?= e(__('blox_cond_dirty')) ?></p>
        <p x-show="conditionProblems().length" x-cloak data-testid="blox-cond-problems" class="mt-1 text-[11px] text-red-600"><?= e(__('blox_cond_problem_target')) ?></p>
    </div>
</template>
