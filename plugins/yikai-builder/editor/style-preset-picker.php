<?php declare(strict_types=1); ?>
<?php if (!defined('ROOT_PATH')) exit('Access Denied'); ?>
                                    <div x-show="professionalOpen && stylePresetsEnabled" class="pb-3 border-b border-gray-200">
                                        <div class="flex items-center justify-between mb-1.5">
                                            <label class="text-xs font-semibold text-gray-600 inline-flex items-center gap-1.5">
                                                <i class="ti ti-components text-sm text-emerald-500"></i><?= e(__('blox_global_style')) ?>
                                            </label>
                                            <button x-show="canManageDesign" type="button" @click="openDesignSystem('styles')"
                                                    class="w-7 h-7 inline-flex items-center justify-center rounded text-gray-400 hover:text-emerald-600 hover:bg-white"
                                                    title="<?= e(__('blox_design_system')) ?>">
                                                <i class="ti ti-settings text-sm"></i>
                                            </button>
                                        </div>
                                        <select :value="selEl.data._global_style || ''" @change="applyGlobalStyle($event.target.value)"
                                                data-testid="blox-global-style-select"
                                                class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs bg-white">
                                            <option value=""><?= e(__('blox_design_no_style')) ?></option>
                                            <template x-for="style in globalStyleOptions(selEl.data._global_style)" :key="style.id">
                                                <option :value="style.id" x-text="globalStyleLabel(style)"></option>
                                            </template>
                                        </select>
                                        <div data-testid="blox-style-binding-status" class="mt-2 text-xs text-gray-600">
                                            <span x-show="!selEl.data._global_style"><?= e(__('blox_style_binding_none')) ?></span>
                                            <span x-show="!!selEl.data._global_style"><?= e(__('blox_style_binding_shared')) ?></span>
                                        </div>
                                        <button x-show="!!selEl.data._global_style" type="button"
                                                data-testid="blox-style-binding-remove" @click="applyGlobalStyle('')"
                                                class="mt-2 text-xs text-gray-600 hover:text-emerald-600"
                                                title="<?= e(__('blox_style_binding_remove_hint')) ?>">
                                            <i class="ti ti-unlink" aria-hidden="true"></i> <?= e(__('blox_style_binding_remove')) ?>
                                        </button>
                                    </div>
