<?php declare(strict_types=1); ?>
<?php if (!defined('ROOT_PATH')) exit('Access Denied'); ?>
<?php /* 元素交互（v1.28 §10.1）：repeater 编辑 data._interactions。
         归一化权威在服务端 BloxInteractions（非法条目落盘时静默丢弃）。 */ ?>
                    <template x-if="interactionsEnabled && panelTab === 'condition' && selEl">
                        <div class="space-y-3 pt-3 mt-3 border-t border-gray-200" data-testid="blox-interactions-editor">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold text-gray-600 inline-flex items-center gap-1.5">
                                    <i class="ti ti-hand-click text-sm text-violet-500"></i><span x-text="interactionText.trigger + ' · ' + interactionText.action"></span>
                                </span>
                                <button type="button" @click="addInteraction()" x-show="interactionItems().length < 10"
                                        data-testid="blox-interaction-add"
                                        class="text-[11px] text-violet-600 hover:underline">
                                    <i class="ti ti-plus" aria-hidden="true"></i> <span x-text="interactionText.add"></span>
                                </button>
                            </div>
                            <p x-show="!interactionItems().length" class="text-[10px] leading-relaxed text-gray-400" x-text="interactionText.empty"></p>
                            <template x-for="(item, itemIndex) in interactionItems()" :key="itemIndex">
                                <div class="rounded border border-gray-200 p-2 space-y-1.5" :data-testid="'blox-interaction-' + itemIndex">
                                    <div class="flex gap-1.5">
                                        <select x-model="item.trigger" @change="interactionChanged(item)" data-testid="blox-interaction-trigger"
                                                class="min-w-0 flex-1 border border-gray-200 rounded px-1.5 py-1.5 text-[11px] bg-white">
                                            <template x-for="(label, key) in interactionText.triggers" :key="key">
                                                <option :value="key" x-text="label"></option>
                                            </template>
                                        </select>
                                        <select x-model="item.action" @change="interactionChanged(item)" data-testid="blox-interaction-action"
                                                class="min-w-0 flex-1 border border-gray-200 rounded px-1.5 py-1.5 text-[11px] bg-white">
                                            <template x-for="(label, key) in interactionText.actions" :key="key">
                                                <option :value="key" x-text="label"></option>
                                            </template>
                                        </select>
                                        <button type="button" @click="removeInteraction(itemIndex)"
                                                class="w-7 h-7 rounded text-gray-400 hover:bg-red-50 hover:text-red-600 inline-flex items-center justify-center shrink-0"
                                                title="<?= e(__('admin_delete')) ?>"><i class="ti ti-x text-sm"></i></button>
                                    </div>
                                    <label x-show="item.trigger === 'scroll'" class="block text-[11px] text-gray-600">
                                        <span class="mb-1 block" x-text="interactionText.scrollDepth"></span>
                                        <input type="number" min="10" max="100" step="5" x-model.number="item.scroll_depth"
                                               class="w-full border border-gray-200 rounded px-2 py-1.5 text-[11px]">
                                    </label>
                                    <div x-show="['open_popup', 'close_popup'].indexOf(item.action) === -1" class="flex gap-1.5">
                                        <select x-model="item.target" data-testid="blox-interaction-target"
                                                class="min-w-0 flex-1 border border-gray-200 rounded px-1.5 py-1.5 text-[11px] bg-white">
                                            <template x-for="(label, key) in interactionText.targets" :key="key">
                                                <option :value="key" x-text="label"></option>
                                            </template>
                                        </select>
                                        <input x-show="item.target === 'selector'" type="text" x-model="item.selector"
                                               :placeholder="interactionText.selector" data-testid="blox-interaction-selector"
                                               class="min-w-0 flex-1 border border-gray-200 rounded px-2 py-1.5 text-[11px] font-mono">
                                    </div>
                                    <input x-show="['add_class', 'remove_class', 'toggle_class'].indexOf(item.action) !== -1"
                                           type="text" x-model="item.value" :placeholder="interactionText.className"
                                           data-testid="blox-interaction-class"
                                           class="w-full border border-gray-200 rounded px-2 py-1.5 text-[11px] font-mono">
                                    <select x-show="item.action === 'animate'" x-model="item.value" data-testid="blox-interaction-animation"
                                            class="w-full border border-gray-200 rounded px-2 py-1.5 text-[11px] bg-white">
                                        <?php foreach (BloxInteractions::ANIMATIONS as $animationName): ?>
                                            <option value="<?= e($animationName) ?>"><?= e($animationName) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label class="inline-flex items-center gap-1.5 text-[11px] text-gray-600">
                                        <input type="checkbox" x-model="item.run_once" class="h-3.5 w-3.5 accent-violet-600">
                                        <span x-text="interactionText.runOnce"></span>
                                    </label>
                                </div>
                            </template>
                            <p x-show="interactionItems().length" class="text-[10px] text-gray-400" x-text="interactionText.hint"></p>
                        </div>
                    </template>
