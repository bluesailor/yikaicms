<?php declare(strict_types=1); ?>
<?php if (!defined('ROOT_PATH')) exit('Access Denied'); ?>
                                    <div x-show="professionalOpen && globalClassesEnabled" class="pb-3 border-b border-gray-200" data-testid="blox-global-classes-panel">
                                        <div class="flex items-center justify-between mb-1.5">
                                            <label class="text-xs font-semibold text-gray-600 inline-flex items-center gap-1.5">
                                                <i class="ti ti-tags text-sm text-sky-500"></i><?= e(__('blox_global_classes')) ?>
                                            </label>
                                        </div>
                                        <div x-show="elementClassIds().length" class="flex flex-wrap gap-1 mb-2" data-testid="blox-element-class-chips">
                                            <template x-for="cid in elementClassIds()" :key="cid">
                                                <span class="inline-flex items-center gap-1 rounded bg-sky-50 border border-sky-200 px-1.5 py-0.5 text-[11px] text-sky-700">
                                                    <span x-text="globalClassLabel(cid)"></span>
                                                    <button type="button" @click="removeElementClass(cid)"
                                                            class="text-sky-400 hover:text-red-500" title="<?= e(__('blox_class_remove')) ?>">
                                                        <i class="ti ti-x text-[10px]"></i>
                                                    </button>
                                                </span>
                                            </template>
                                        </div>
                                        <select x-show="availableClassOptions().length" value=""
                                                @change="addElementClass($event.target.value); $event.target.value = ''"
                                                data-testid="blox-element-class-add"
                                                class="w-full border border-gray-200 rounded px-2 py-1.5 text-xs bg-white">
                                            <option value=""><?= e(__('blox_class_add_existing')) ?></option>
                                            <template x-for="cls in availableClassOptions()" :key="cls.class_id">
                                                <option :value="cls.class_id" x-text="cls.name"></option>
                                            </template>
                                        </select>
                                        <div class="flex items-center gap-1 mt-2">
                                            <input type="text" x-model="newGlobalClassName" maxlength="48"
                                                   @keydown.enter.prevent="createGlobalClass()"
                                                   placeholder="<?= e(__('blox_class_create_placeholder')) ?>"
                                                   data-testid="blox-class-create-name"
                                                   class="flex-1 min-w-0 border border-gray-200 rounded px-2 py-1.5 text-xs">
                                            <button type="button" @click="createGlobalClass()" data-testid="blox-class-create"
                                                    class="shrink-0 rounded bg-sky-600 px-2 py-1.5 text-xs text-white hover:bg-sky-700">
                                                <?= e(__('blox_class_create')) ?>
                                            </button>
                                        </div>
                                        <p class="text-[10px] text-gray-400 mt-1"><?= e(__('blox_class_panel_hint')) ?></p>
                                    </div>
