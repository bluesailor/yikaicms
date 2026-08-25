<?php

declare(strict_types=1);
?>
    <!-- 富文本编辑弹窗（系统 TinyMCE；不做点遮罩关闭——误点会丢内容） -->
    <div x-show="rteOpen" x-cloak x-ref="rteDialog" tabindex="-1"
         @keydown="dialogKeydown($event, $refs.rteDialog, () => closeRte())"
         role="dialog" aria-modal="true" aria-labelledby="blox-rte-dialog-title"
         class="fixed inset-0 z-[90] flex items-center justify-center p-6">
        <div class="absolute inset-0 bg-black/50"></div>
        <div class="relative bg-white rounded-xl shadow-2xl w-[860px] max-w-full flex flex-col">
            <div class="h-12 px-4 flex items-center justify-between border-b border-gray-100 shrink-0">
                <span id="blox-rte-dialog-title" class="text-sm font-semibold text-gray-700 inline-flex items-center gap-1.5">
                    <i class="ti ti-edit text-base text-blue-500"></i><?= __('blox_visual_edit') ?>
                </span>
                <button type="button" @click="closeRte()" class="text-gray-400 hover:text-gray-600 p-1" title="<?= e(__('cancel')) ?>" aria-label="<?= e(__('cancel')) ?>">
                    <i class="ti ti-x text-base"></i>
                </button>
            </div>
            <div class="p-3">
                <textarea id="bloxRte" data-dialog-initial></textarea>
            </div>
            <div class="h-14 px-4 flex items-center justify-end gap-2 border-t border-gray-100 shrink-0">
                <button type="button" @click="closeRte()"
                        class="text-sm text-gray-500 hover:text-gray-700 border border-gray-200 rounded px-4 py-1.5 transition"><?= __('cancel') ?></button>
                <button type="button" @click="saveRte()"
                        class="text-sm text-white bg-blue-600 hover:bg-blue-500 rounded px-4 py-1.5 transition"><?= __('admin_apply') ?></button>
            </div>
        </div>
    </div>

    <?php // z-[1500]：媒体库弹窗还会从 TinyMCE 的图片对话框（z≈1100+）里被唤起，必须压在其上 ?>
    <!-- 媒体库选择弹窗 -->
    <div x-show="mediaOpen" x-cloak x-ref="mediaDialog" tabindex="-1"
         @keydown="dialogKeydown($event, $refs.mediaDialog, () => closeMedia())"
         role="dialog" aria-modal="true" aria-labelledby="blox-media-dialog-title"
         class="fixed inset-0 z-[1500] flex items-center justify-center p-6">
        <div class="absolute inset-0 bg-black/50" @click="closeMedia()"></div>
        <?php // 固定紧凑尺寸：内容区定高滚动，弹窗不随视口撑大（约 860×520） ?>
        <div class="relative bg-white rounded-xl shadow-2xl w-[860px] max-w-[90vw] flex flex-col">
            <div class="h-12 px-4 flex items-center justify-between border-b border-gray-100 shrink-0">
                <span id="blox-media-dialog-title" class="text-sm font-semibold text-gray-700 inline-flex items-center gap-1.5">
                    <i class="ti ti-photo text-base text-blue-500"></i><?= __('blox_pick_from_media') ?>
                </span>
                <button type="button" @click="closeMedia()" class="text-gray-400 hover:text-gray-600 p-1" aria-label="<?= e(__('close')) ?>">
                    <i class="ti ti-x text-base"></i>
                </button>
            </div>
            <div class="p-3 border-b border-gray-100 shrink-0 flex gap-2">
                <input type="text" x-model="mediaKeyword" @keydown.enter.prevent="loadMedia(1)"
                       data-dialog-initial
                       placeholder="<?= e(__('blox_search_files')) ?>" class="flex-1 border border-gray-200 rounded px-2 py-1.5 text-sm">
                <button type="button" @click="loadMedia(1)"
                        class="shrink-0 text-sm text-white bg-blue-600 hover:bg-blue-500 rounded px-3 py-1.5 transition"><?= __('search') ?></button>
                <?php // 上传即选用：上传的目的就是马上要用这张图 ?>
                <label class="shrink-0 text-sm border rounded px-3 py-1.5 inline-flex items-center gap-1 transition"
                       :class="mediaUploading ? 'border-gray-200 text-gray-400 cursor-wait' : 'border-blue-200 text-blue-500 hover:border-blue-400 hover:text-blue-600 cursor-pointer'">
                    <i class="ti text-base" :class="mediaUploading ? 'ti-loader-2 animate-spin' : 'ti-upload'"></i>
                    <span x-text="mediaUploading ? <?= e($jt('blox_uploading')) ?> : <?= e($jt('blox_upload_image')) ?>"></span>
                    <input type="file" accept="image/*" class="hidden" :disabled="mediaUploading"
                           @change="uploadMedia($event.target.files[0]); $event.target.value = ''">
                </label>
            </div>
            <div class="h-[400px] overflow-y-auto blox-scroll p-3">
                <p x-show="mediaLoading" class="text-center text-gray-400 text-sm py-12"><?= __('theme_market_loading') ?></p>
                <p x-show="!mediaLoading && mediaItems.length === 0" class="text-center text-gray-400 text-sm py-12">
                    <?= __('blox_no_images_hint') ?>
                </p>
                <div x-show="!mediaLoading && mediaItems.length > 0"
                     data-testid="blox-media-grid"
                     class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                    <template x-for="it in mediaItems" :key="it.id">
                        <button type="button" @click="pickMedia(it.url)" :title="it.name"
                                data-testid="blox-media-item"
                                class="group/mp min-w-0 border-2 border-gray-100 hover:border-blue-400 rounded-lg overflow-hidden bg-white transition text-left">
                            <span class="block aspect-[3/2] bg-gray-100 p-1.5">
                                <img :src="it.url" class="w-full h-full object-contain" loading="lazy" alt="">
                            </span>
                            <span class="block px-2 py-1.5 text-[11px] text-gray-600 truncate" x-text="it.name"></span>
                        </button>
                    </template>
                </div>
            </div>
            <div class="h-11 px-4 flex items-center justify-between border-t border-gray-100 shrink-0 text-xs text-gray-500">
                <span x-text="<?= e($jt('blox_media_total')) ?>.replace(':n', mediaTotal)"></span>
                <div class="flex items-center gap-2">
                    <button type="button" :disabled="mediaPage <= 1" @click="loadMedia(mediaPage - 1)"
                            class="px-2 py-1 border border-gray-200 rounded hover:bg-gray-50 disabled:opacity-40"><?= __('list_prev_page') ?></button>
                    <span x-text="mediaPage + ' / ' + Math.max(mediaPages, 1)"></span>
                    <button type="button" :disabled="mediaPage >= mediaPages" @click="loadMedia(mediaPage + 1)"
                            class="px-2 py-1 border border-gray-200 rounded hover:bg-gray-50 disabled:opacity-40"><?= __('list_next_page') ?></button>
                </div>
            </div>
        </div>
    </div>

    <!-- 全站设计系统：基础颜色令牌免费；命名样式预设由高级授权控制。 -->
    <div x-show="designOpen" x-cloak x-ref="designDialog" tabindex="-1" data-testid="blox-design-dialog"
         @keydown="dialogKeydown($event, $refs.designDialog, () => closeDesignSystem())"
         role="dialog" aria-modal="true" aria-labelledby="blox-design-dialog-title"
         class="fixed inset-0 z-[140] flex items-center justify-center p-6">
        <div class="absolute inset-0 bg-black/50" @click="closeDesignSystem()"></div>
        <div class="relative bg-white rounded-lg shadow-2xl w-[920px] max-w-[94vw] flex flex-col"
             style="max-height:calc(100vh - 4rem)">
            <div class="h-12 px-4 flex items-center justify-between border-b border-gray-100 shrink-0">
                <span id="blox-design-dialog-title" class="text-sm font-semibold text-gray-700 inline-flex items-center gap-1.5">
                    <i class="ti ti-palette text-base text-emerald-500"></i><span x-text="designText.title"></span>
                </span>
                <button type="button" @click="closeDesignSystem()" class="text-gray-400 hover:text-gray-600 p-1"
                        :title="templateText.close" :aria-label="templateText.close">
                    <i class="ti ti-x text-base"></i>
                </button>
            </div>
            <div class="h-11 px-3 border-b border-gray-100 shrink-0 flex items-end gap-1" role="tablist" :aria-label="designText.title">
                <button type="button" role="tab" data-dialog-initial data-testid="blox-design-tab-colors"
                        @click="designTab = 'colors'" :aria-selected="designTab === 'colors'"
                        class="h-10 px-4 border-b-2 text-xs font-semibold inline-flex items-center gap-2 transition"
                        :class="designTab === 'colors' ? 'border-emerald-500 text-emerald-600' : 'border-transparent text-gray-400 hover:text-gray-700'">
                    <i class="ti ti-color-swatch text-base"></i><span x-text="designText.colors"></span>
                </button>
                <button x-show="advancedMode" type="button" role="tab" data-testid="blox-design-tab-styles"
                        @click="designTab = 'styles'" :aria-selected="designTab === 'styles'"
                        class="h-10 px-4 border-b-2 text-xs font-semibold inline-flex items-center gap-2 transition"
                        :class="designTab === 'styles' ? 'border-emerald-500 text-emerald-600' : 'border-transparent text-gray-400 hover:text-gray-700'">
                    <i class="ti ti-components text-base"></i><span x-text="designText.styles"></span>
                </button>
                <span x-show="designBusy" class="ml-auto mb-2.5 mr-2 text-xs text-gray-400 inline-flex items-center gap-1">
                    <i class="ti ti-loader-2 animate-spin"></i><?= e(__('loading')) ?>
                </span>
            </div>

            <div x-show="designTab === 'colors'" class="min-h-0 flex-1 overflow-y-auto blox-scroll">
                <div class="grid grid-cols-[1.2fr_.8fr_1fr_auto] gap-2 px-4 py-3 bg-gray-50 border-b border-gray-100">
                    <input type="text" x-model="newToken.name" placeholder="<?= e(__('blox_design_new_token')) ?>"
                           class="border border-gray-200 rounded px-2 py-1.5 text-xs">
                    <input type="text" x-model="newToken.category" placeholder="<?= e(__('blox_design_category')) ?>"
                           class="border border-gray-200 rounded px-2 py-1.5 text-xs">
                    <div class="flex items-center gap-1.5">
                        <input type="color" x-model="newToken.value" class="w-8 h-8 border border-gray-200 rounded p-0.5">
                        <input type="text" x-model="newToken.value" class="min-w-0 flex-1 border border-gray-200 rounded px-2 py-1.5 text-xs font-mono">
                    </div>
                    <button type="button" @click="addDesignToken()" :disabled="designBusy || !newToken.name.trim()"
                            data-testid="blox-design-add-token"
                            class="px-3 rounded bg-emerald-600 hover:bg-emerald-500 disabled:opacity-40 text-white text-xs inline-flex items-center gap-1">
                        <i class="ti ti-plus"></i><?= e(__('blox_design_add')) ?>
                    </button>
                </div>
                <div class="px-4 py-2 text-[10px] font-semibold text-gray-400 uppercase"><?= e(__('blox_design_active')) ?></div>
                <template x-for="token in activeColorTokens()" :key="token.id">
                    <div class="grid grid-cols-[2.1rem_1.2fr_.8fr_1fr_auto] gap-2 items-center px-4 py-2 border-t border-gray-100"
                         data-testid="blox-design-token-row">
                        <span class="w-8 h-8 rounded border border-gray-200" :style="'background:' + token.value"></span>
                        <input type="text" x-model="token.name" :disabled="token.system || token.locked"
                               class="min-w-0 border border-gray-200 rounded px-2 py-1.5 text-xs disabled:bg-gray-50 disabled:text-gray-500">
                        <input type="text" x-model="token.category" :disabled="token.system || token.locked"
                               class="min-w-0 border border-gray-200 rounded px-2 py-1.5 text-xs disabled:bg-gray-50 disabled:text-gray-500">
                        <div class="flex items-center gap-1.5">
                            <input type="color" x-model="token.value" :disabled="token.system || token.locked"
                                   class="w-8 h-8 border border-gray-200 rounded p-0.5 disabled:opacity-50">
                            <input type="text" x-model="token.value" :disabled="token.system || token.locked"
                                   class="min-w-0 flex-1 border border-gray-200 rounded px-2 py-1.5 text-xs font-mono disabled:bg-gray-50">
                        </div>
                          <div class="flex items-center justify-end gap-1">
                              <span x-show="designUsageCount('token', token.id) > 0"
                                    data-testid="blox-design-usage"
                                    :title="designUsageTitle('token', token.id)"
                                    class="mr-1 text-[10px] text-gray-400"
                                    x-text="designText.usedCount.replace(':count', designUsageCount('token', token.id))"></span>
                            <a x-show="token.system" href="/admin/setting.php?group=basic" target="_blank"
                               class="w-8 h-8 rounded inline-flex items-center justify-center text-gray-400 hover:text-blue-600"
                               title="<?= e(__('blox_design_system_color')) ?>"><i class="ti ti-external-link"></i></a>
                            <button x-show="!token.system" type="button" @click="toggleDesignLock('token', token)"
                                    class="w-8 h-8 rounded inline-flex items-center justify-center text-gray-400 hover:text-amber-600"
                                    :title="token.locked ? <?= e($jt('blox_design_unlock')) ?> : <?= e($jt('blox_design_lock')) ?>">
                                <i class="ti" :class="token.locked ? 'ti-lock' : 'ti-lock-open'"></i>
                            </button>
                            <button x-show="!token.system && !token.locked" type="button" @click="updateDesignToken(token)"
                                    class="w-8 h-8 rounded inline-flex items-center justify-center text-gray-400 hover:text-emerald-600"
                                    title="<?= e(__('blox_design_save')) ?>"><i class="ti ti-device-floppy"></i></button>
                            <button x-show="!token.system && !token.locked" type="button" @click="archiveDesignItem('token', token)"
                                    class="w-8 h-8 rounded inline-flex items-center justify-center text-gray-400 hover:text-red-500"
                                    title="<?= e(__('blox_design_archive')) ?>"><i class="ti ti-trash"></i></button>
                        </div>
                    </div>
                </template>
                <details x-show="archivedColorTokens().length > 0" class="border-t border-gray-200">
                    <summary class="px-4 py-2 text-xs font-semibold text-gray-500 cursor-pointer"><?= e(__('blox_design_archived')) ?></summary>
                    <template x-for="token in archivedColorTokens()" :key="'archived-'+token.id">
                        <div class="flex items-center gap-2 px-4 py-2 border-t border-gray-100 bg-gray-50">
                            <span class="w-7 h-7 rounded border border-gray-200 opacity-60" :style="'background:' + token.value"></span>
                            <span class="text-xs text-gray-500 flex-1" x-text="token.name"></span>
                            <button type="button" @click="restoreDesignItem('token', token)" class="text-xs text-emerald-600 inline-flex items-center gap-1">
                                <i class="ti ti-restore"></i><?= e(__('blox_design_restore')) ?>
                            </button>
                        </div>
                    </template>
                </details>
            </div>

            <div x-show="designTab === 'styles' && advancedMode" class="min-h-0 flex-1 overflow-y-auto blox-scroll">
                <div class="grid grid-cols-[1.1fr_.7fr_repeat(3,1fr)_.7fr_auto] gap-2 px-4 py-3 bg-gray-50 border-b border-gray-100">
                    <input type="text" x-model="newStyle.name" placeholder="<?= e(__('blox_design_new_style')) ?>" class="min-w-0 border border-gray-200 rounded px-2 py-1.5 text-xs">
                    <input type="text" x-model="newStyle.category" placeholder="<?= e(__('blox_design_category')) ?>" class="min-w-0 border border-gray-200 rounded px-2 py-1.5 text-xs">
                    <?php foreach (['color' => 'blox_design_text_color', 'background' => 'blox_design_background', 'border_color' => 'blox_design_border'] as $field => $label): ?>
                    <select x-model="newStyle.<?= $field ?>" title="<?= e(__($label)) ?>" class="min-w-0 border border-gray-200 rounded px-1 py-1.5 text-xs bg-white">
                        <option value=""><?= e(__($label)) ?> · <?= e(__('none')) ?></option>
                        <template x-for="token in activeColorTokens()" :key="'new-<?= $field ?>-'+token.id">
                            <option :value="colorTokenRef(token.id)" x-text="token.name"></option>
                        </template>
                    </select>
                    <?php endforeach; ?>
                    <select x-model="newStyle.radius" title="<?= e(__('blox_design_radius')) ?>" class="min-w-0 border border-gray-200 rounded px-1 py-1.5 text-xs bg-white">
                        <option value="none"><?= e(__('blox_spacing_none')) ?></option><option value="sm"><?= e(__('blox_spacing_sm')) ?></option>
                        <option value="md"><?= e(__('blox_spacing_md')) ?></option><option value="lg"><?= e(__('blox_spacing_lg')) ?></option><option value="full"><?= e(__('blox_design_radius_full')) ?></option>
                    </select>
                    <button type="button" @click="addGlobalStyle()" :disabled="designBusy || !newStyle.name.trim()"
                            data-testid="blox-design-add-style"
                            class="px-3 rounded bg-emerald-600 hover:bg-emerald-500 disabled:opacity-40 text-white text-xs"><i class="ti ti-plus"></i></button>
                </div>
                <div class="px-4 py-2 text-[10px] font-semibold text-gray-400 uppercase"><?= e(__('blox_design_active')) ?></div>
                <template x-for="style in activeGlobalStyles()" :key="style.id">
                      <div class="grid grid-cols-[1.1fr_.7fr_repeat(3,1fr)_.7fr_auto] gap-2 items-center px-4 py-2 border-t border-gray-100"
                         data-testid="blox-design-style-row">
                        <input type="text" x-model="style.name" :disabled="style.locked" class="min-w-0 border border-gray-200 rounded px-2 py-1.5 text-xs disabled:bg-gray-50">
                        <input type="text" x-model="style.category" :disabled="style.locked" class="min-w-0 border border-gray-200 rounded px-2 py-1.5 text-xs disabled:bg-gray-50">
                        <template x-for="field in ['color','background','border_color']" :key="style.id+'-'+field">
                            <select x-model="style[field]" :disabled="style.locked" class="min-w-0 border border-gray-200 rounded px-1 py-1.5 text-xs bg-white disabled:bg-gray-50">
                                <option value="" x-text="field === 'color' ? <?= e($jt('blox_design_text_color')) ?> : (field === 'background' ? <?= e($jt('blox_design_background')) ?> : <?= e($jt('blox_design_border')) ?>)"></option>
                                <template x-for="token in colorTokenOptions(style[field])" :key="style.id+'-'+field+'-'+token.id">
                                    <option :value="colorTokenRef(token.id)" x-text="colorTokenLabel(token)"></option>
                                </template>
                            </select>
                        </template>
                        <select x-model="style.radius" :disabled="style.locked" class="min-w-0 border border-gray-200 rounded px-1 py-1.5 text-xs bg-white disabled:bg-gray-50">
                            <option value="none"><?= e(__('blox_spacing_none')) ?></option><option value="sm"><?= e(__('blox_spacing_sm')) ?></option>
                            <option value="md"><?= e(__('blox_spacing_md')) ?></option><option value="lg"><?= e(__('blox_spacing_lg')) ?></option><option value="full"><?= e(__('blox_design_radius_full')) ?></option>
                        </select>
                          <div class="flex items-center justify-end gap-1">
                              <span x-show="designUsageCount('style', style.id) > 0"
                                    data-testid="blox-design-style-usage"
                                    :title="designUsageTitle('style', style.id)"
                                    class="mr-1 text-[10px] text-gray-400"
                                    x-text="designText.usedCount.replace(':count', designUsageCount('style', style.id))"></span>
                            <button type="button" @click="toggleDesignLock('style', style)" class="w-8 h-8 rounded text-gray-400 hover:text-amber-600" :title="style.locked ? <?= e($jt('blox_design_unlock')) ?> : <?= e($jt('blox_design_lock')) ?>"><i class="ti" :class="style.locked ? 'ti-lock' : 'ti-lock-open'"></i></button>
                            <button x-show="!style.locked" type="button" @click="updateGlobalStyle(style)" class="w-8 h-8 rounded text-gray-400 hover:text-emerald-600" title="<?= e(__('blox_design_save')) ?>"><i class="ti ti-device-floppy"></i></button>
                            <button x-show="!style.locked" type="button" @click="archiveDesignItem('style', style)" class="w-8 h-8 rounded text-gray-400 hover:text-red-500" title="<?= e(__('blox_design_archive')) ?>"><i class="ti ti-trash"></i></button>
                        </div>
                    </div>
                </template>
                <details x-show="archivedGlobalStyles().length > 0" class="border-t border-gray-200">
                    <summary class="px-4 py-2 text-xs font-semibold text-gray-500 cursor-pointer"><?= e(__('blox_design_archived')) ?></summary>
                    <template x-for="style in archivedGlobalStyles()" :key="'archived-style-'+style.id">
                        <div class="flex items-center gap-2 px-4 py-2 border-t border-gray-100 bg-gray-50">
                            <i class="ti ti-components text-gray-400"></i><span class="text-xs text-gray-500 flex-1" x-text="style.name"></span>
                            <button type="button" @click="restoreDesignItem('style', style)" class="text-xs text-emerald-600 inline-flex items-center gap-1"><i class="ti ti-restore"></i><?= e(__('blox_design_restore')) ?></button>
                        </div>
                    </template>
                </details>
            </div>
        </div>
    </div>

    <!-- Blox 模板库：目录与正文按需加载，避免大模板拖慢编辑器首屏。 -->
    <div x-show="templateOpen" x-cloak x-ref="templateDialog" tabindex="-1"
         @keydown="dialogKeydown($event, $refs.templateDialog, () => closeTemplates())"
         role="dialog" aria-modal="true" aria-labelledby="blox-template-dialog-title"
         class="fixed inset-0 z-[130] flex items-center justify-center p-6">
        <div class="absolute inset-0 bg-black/50" @click="closeTemplates()"></div>
        <div class="relative bg-white rounded-xl shadow-2xl w-[900px] max-w-[94vw] flex flex-col"
             style="max-height:calc(100vh - 4rem)">
            <div class="h-12 px-4 flex items-center justify-between border-b border-gray-100 shrink-0">
                <span id="blox-template-dialog-title" class="text-sm font-semibold text-gray-700 inline-flex items-center gap-1.5">
                    <i class="ti ti-template text-base text-blue-500"></i><span x-text="templateText.title"></span>
                </span>
                <button type="button" @click="closeTemplates()" data-testid="blox-template-close" class="text-gray-400 hover:text-gray-600 p-1"
                        :title="templateText.close" :aria-label="templateText.close">
                    <i class="ti ti-x text-base"></i>
                </button>
            </div>
            <div class="h-11 px-3 border-b border-gray-100 shrink-0 flex items-end gap-1" role="tablist" :aria-label="templateText.title">
                <button type="button" role="tab" data-testid="blox-template-tab-local"
                        @click="templateScope = 'local'; templateCategory = 'all'" :aria-selected="templateScope === 'local'"
                        class="h-10 px-4 border-b-2 text-xs font-semibold inline-flex items-center gap-2 transition"
                        :class="templateScope === 'local' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-400 hover:text-gray-700'">
                    <i class="ti ti-folders text-base"></i>
                    <span x-text="templateText.localLibrary"></span>
                    <span class="min-w-5 h-5 px-1 rounded bg-gray-100 text-[10px] text-gray-500 inline-flex items-center justify-center"
                          x-text="templateScopeCount('local')"></span>
                </button>
                <button type="button" role="tab" data-testid="blox-template-tab-remote"
                        @click="templateScope = 'remote'; templateCategory = 'all'" :aria-selected="templateScope === 'remote'"
                        class="h-10 px-4 border-b-2 text-xs font-semibold inline-flex items-center gap-2 transition"
                        :class="templateScope === 'remote' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-400 hover:text-gray-700'">
                    <i class="ti ti-cloud-download text-base"></i>
                    <span x-text="templateText.remoteLibrary"></span>
                    <span class="min-w-5 h-5 px-1 rounded bg-gray-100 text-[10px] text-gray-500 inline-flex items-center justify-center"
                          x-text="templateScopeCount('remote')"></span>
                </button>
            </div>
            <div class="p-3 border-b border-gray-100 shrink-0 flex flex-wrap items-center gap-2">
                <div class="relative flex-1 min-w-48">
                    <i class="ti ti-search text-sm text-gray-300 absolute left-2.5 top-1/2 -translate-y-1/2"></i>
                    <input type="text" x-model="templateQuery" :placeholder="templateText.search"
                           data-dialog-initial data-testid="blox-template-search"
                           class="w-full border border-gray-200 rounded pl-8 pr-3 py-1.5 text-sm">
                </div>
                <div class="inline-flex rounded border border-gray-200 p-0.5">
                    <template x-for="filter in templateFilters" :key="filter.key">
                        <button type="button" @click="templateFilter = filter.key"
                                :aria-pressed="templateFilter === filter.key"
                                class="px-3 py-1 text-xs rounded transition"
                                :class="templateFilter === filter.key ? 'bg-gray-900 text-white' : 'text-gray-500 hover:text-gray-800'"
                                x-text="filter.label"></button>
                    </template>
                </div>
                <label x-show="templateCategoryOptions().length > 1" class="relative min-w-36">
                    <span class="sr-only" x-text="templateText.category"></span>
                    <select x-model="templateCategory" data-testid="blox-template-category"
                            class="w-full h-8 border border-gray-200 rounded bg-white pl-2 pr-7 text-xs text-gray-600">
                        <option value="" disabled x-text="templateText.category"></option>
                        <option value="all" x-text="templateText.categoryAll"></option>
                        <template x-for="category in templateCategoryOptions()" :key="category">
                            <option :value="category" x-text="templateCategoryLabel(category)"></option>
                        </template>
                    </select>
                </label>
                <button type="button" @click="loadTemplates(true)" :disabled="templateLoading"
                        class="w-8 h-8 inline-flex items-center justify-center rounded border border-gray-200 text-gray-500 hover:text-blue-600 disabled:opacity-40"
                        :title="templateText.reload" :aria-label="templateText.reload">
                    <i class="ti" :class="templateLoading ? 'ti-loader-2 animate-spin' : 'ti-refresh'"></i>
                </button>
            </div>
            <div class="min-h-[320px] overflow-y-auto blox-scroll p-4">
                <div x-show="templateLoading" class="py-16 text-center text-sm text-gray-400">
                    <i class="ti ti-loader-2 animate-spin text-xl block mb-2"></i><span x-text="templateText.loading"></span>
                </div>
                <div x-show="!templateLoading && templateError" class="py-16 text-center text-sm text-red-500">
                    <i class="ti ti-alert-circle text-xl block mb-2"></i><span x-text="templateError"></span>
                </div>
                <div x-show="!templateLoading && !templateError && templateScope === 'remote' && templateRemoteError"
                     class="mb-3 px-3 py-2 border border-amber-200 bg-amber-50 text-amber-700 text-xs rounded flex items-center gap-2">
                    <i class="ti ti-cloud-off shrink-0"></i><span x-text="templateRemoteError"></span>
                </div>
                <div x-show="!templateLoading && !templateError && filteredTemplates().length === 0"
                     class="py-16 text-center text-sm text-gray-400">
                    <i class="ti text-2xl block mb-2" :class="templateScope === 'remote' ? 'ti-cloud-off' : 'ti-template-off'"></i>
                    <span x-text="templateScope === 'remote' ? templateText.emptyRemote : templateText.emptyLocal"></span>
                </div>
                <div x-show="!templateLoading && !templateError && filteredTemplates().length > 0"
                    class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                    <template x-for="item in filteredTemplates()" :key="item.key">
                        <article data-testid="blox-template-item" :data-template-key="item.key"
                                 :title="item.description || item.name"
                                 class="min-h-52 overflow-hidden border border-gray-200 rounded-lg text-left flex flex-col transition hover:border-blue-300">
                            <span class="relative block aspect-[16/7] overflow-hidden border-b border-gray-100 bg-gray-100">
                                <img x-show="item.thumbnail" :src="item.thumbnail" alt="" loading="lazy"
                                     class="h-full w-full object-cover">
                                <span x-show="!item.thumbnail" class="absolute inset-0 flex items-center justify-center text-gray-400">
                                    <i class="ti text-3xl" :class="item.type === 'page' ? 'ti-files' : 'ti-layout'"></i>
                                </span>
                            </span>
                            <span class="flex flex-1 flex-col p-3">
                            <span class="flex items-start justify-between gap-3">
                                <span class="w-9 h-9 rounded bg-gray-100 text-gray-500 inline-flex items-center justify-center shrink-0">
                                    <i class="ti text-lg" :class="item.type === 'page' ? 'ti-files' : 'ti-layout'"></i>
                                </span>
                                <span class="inline-flex items-center gap-1">
                                    <span x-show="item.paid" class="text-[10px] text-amber-700 border border-amber-200 bg-amber-50 rounded px-1.5 py-0.5"
                                          x-text="templateText.premium"></span>
                                    <span class="text-[10px] uppercase text-gray-400 border border-gray-200 rounded px-1.5 py-0.5"
                                          x-text="templateTypeLabel(item.type)"></span>
                                </span>
                            </span>
                            <span class="block mt-3 text-sm font-medium text-gray-800 truncate" x-text="item.name"></span>
                            <span x-show="item.description" class="block mt-1 text-xs text-gray-500 overflow-hidden"
                                  style="min-height:2.5rem;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical"
                                  x-text="item.description"></span>
                            <span class="mt-1 text-[11px] text-gray-400 inline-flex items-center gap-1">
                                <i class="ti" :class="item.source === 'remote' ? 'ti-cloud-download' : (item.source === 'plugin' ? 'ti-plug' : 'ti-user')"></i>
                                <span x-text="templateProviderLabel(item)"></span>
                            </span>
                            <span x-show="item.locked" class="block mt-1 text-[11px] text-amber-700">
                                <i class="ti ti-lock mr-0.5"></i><span x-text="templateLockLabel(item)"></span>
                            </span>
                            <span class="mt-auto pt-3 flex items-center gap-2">
                                <button type="button" @click="insertTemplate(item)"
                                        :disabled="templateInserting !== '' || !!item.locked"
                                        data-testid="blox-template-insert"
                                        :title="item.locked ? templateLockLabel(item) : (item.source === 'remote' ? templateText.downloadImport : templateText.insert)"
                                        class="h-8 rounded px-3 text-xs font-medium inline-flex items-center justify-center gap-1.5 disabled:bg-gray-100 disabled:border-gray-200 disabled:text-gray-400 disabled:cursor-not-allowed"
                                        :class="item.source === 'remote'
                                            ? 'w-auto border border-gray-200 bg-white text-gray-600 hover:border-blue-300 hover:bg-blue-50 hover:text-blue-600'
                                            : 'flex-1 border border-blue-600 bg-blue-600 text-white hover:border-blue-500 hover:bg-blue-500'">
                                    <i class="ti text-sm" :class="templateInserting === item.key ? 'ti-loader-2 animate-spin' : (item.locked ? 'ti-lock' : (item.source === 'remote' ? 'ti-cloud-download' : 'ti-plus'))"></i>
                                    <span x-text="item.source === 'remote' ? templateText.downloadImport : templateText.insert"></span>
                                </button>
                                <template x-if="canEditLocalTemplate(item)">
                                    <a :href="localTemplateEditUrl(item)" target="_blank" rel="noopener"
                                       data-testid="blox-template-edit" :title="templateText.edit" :aria-label="templateText.edit + ': ' + item.name"
                                       class="h-8 px-3 rounded border border-gray-200 text-xs font-medium text-gray-600 inline-flex items-center justify-center gap-1.5 hover:border-blue-300 hover:text-blue-600">
                                        <i class="ti ti-pencil text-sm"></i><span x-text="templateText.edit"></span>
                                    </a>
                                </template>
                            </span>
                            </span>
                        </article>
                    </template>
                </div>
            </div>
            <div class="h-12 px-4 flex items-center justify-between border-t border-gray-100 shrink-0">
                <span class="text-xs text-gray-400" aria-live="polite"
                      x-text="templateText.resultCount.replace(':shown', filteredTemplates().length).replace(':total', scopedTemplates().length)"></span>
                <span class="inline-flex items-center gap-4">
                    <a x-show="templateScope === 'remote' && hasLockedTemplates()" href="/admin/license.php"
                       class="text-xs text-amber-700 hover:text-amber-800 inline-flex items-center gap-1">
                        <i class="ti ti-key"></i><span x-text="templateText.manageLicense"></span>
                    </a>
                    <a x-show="templateScope === 'local' && advancedMode" href="/admin/blox_templates.php" class="text-xs text-blue-600 hover:text-blue-800 inline-flex items-center gap-1">
                        <i class="ti ti-settings"></i><span x-text="templateText.manage"></span>
                    </a>
                </span>
            </div>
        </div>
    </div>

    <!-- revisions modal -->
    <div x-show="revisionOpen" x-cloak x-ref="revisionDialog" tabindex="-1"
         @keydown="dialogKeydown($event, $refs.revisionDialog, () => closeRevisions())"
         role="dialog" aria-modal="true" aria-labelledby="blox-revision-dialog-title"
         class="fixed inset-0 z-[120] flex items-center justify-center p-6">
        <div class="absolute inset-0 bg-black/50" @click="closeRevisions()"></div>
        <div class="relative bg-white rounded-xl shadow-2xl w-[920px] max-w-[94vw] flex flex-col" style="max-height:calc(100vh - 4rem)">
            <div class="h-12 px-4 flex items-center justify-between border-b border-gray-100 shrink-0">
                <span id="blox-revision-dialog-title" class="text-sm font-semibold text-gray-700 inline-flex items-center gap-1.5">
                    <i class="ti ti-history text-base text-blue-500"></i><?= __('revision_history') ?>
                </span>
                <button type="button" @click="closeRevisions()" data-dialog-initial class="text-gray-400 hover:text-gray-600 p-1" title="<?= e(__('close')) ?>" aria-label="<?= e(__('close')) ?>">
                    <i class="ti ti-x text-base"></i>
                </button>
            </div>
            <div class="grid grid-cols-[280px_1fr] min-h-0 flex-1">
                <div class="border-r border-gray-100 min-h-0 flex flex-col">
                    <div class="h-10 px-3 flex items-center justify-between border-b border-gray-100 shrink-0">
                        <span class="text-xs text-gray-500" x-text="revisionLoading ? <?= e($jt('loading')) ?> : <?= e($jt('blox_n_revisions')) ?>.replace(':n', revisions.length)"></span>
                        <button type="button" @click="loadRevisions()" class="text-xs text-gray-400 hover:text-blue-500 inline-flex items-center gap-1">
                            <i class="ti ti-refresh text-sm"></i><?= __('btn_refresh') ?>
                        </button>
                    </div>
                    <div class="flex-1 overflow-y-auto blox-scroll p-2">
                        <p x-show="!revisionLoading && revisions.length === 0" class="text-xs text-gray-400 text-center py-10"><?= __('blox_no_revisions') ?></p>
                        <template x-for="rev in revisions" :key="rev.id">
                            <button type="button" @click="previewRevision(rev)"
                                    class="w-full text-left rounded-lg border px-3 py-2 mb-2 transition"
                                    :class="activeRev && activeRev.id === rev.id ? 'border-blue-400 bg-blue-50' : 'border-gray-200 hover:border-blue-200'">
                                <span class="block text-sm text-gray-700 truncate" x-text="rev.summary || <?= e($jt('revision_history')) ?>"></span>
                                <span class="block text-[11px] text-gray-400 mt-0.5" x-text="rev.time_text + (rev.admin_name ? ' / ' + rev.admin_name : '')"></span>
                            </button>
                        </template>
                    </div>
                </div>
                <div class="min-h-0 flex flex-col">
                    <div class="h-10 px-3 flex items-center justify-between border-b border-gray-100 shrink-0">
                        <span class="text-xs text-gray-500 truncate" x-text="activeRev ? (activeRev.summary || <?= e($jt('revision_history')) ?>) : <?= e($jt('blox_pick_revision')) ?>"></span>
                        <button type="button" x-show="activeRev" @click="restoreRevision(activeRev)" :disabled="revisionRestoring"
                                class="text-xs text-white bg-blue-600 hover:bg-blue-500 disabled:opacity-50 rounded px-3 py-1.5 inline-flex items-center gap-1">
                            <i class="ti text-sm" :class="revisionRestoring ? 'ti-loader-2 animate-spin' : 'ti-restore'"></i>
                            <?= __('blox_restore_this') ?>
                        </button>
                    </div>
                    <iframe class="flex-1 w-full border-0 bg-white" :srcdoc="revisionPreview || '<!doctype html><html><body></body></html>'"></iframe>
                </div>
            </div>
        </div>
    </div>

    <!-- 异常退出恢复：只在本机存在比服务器更新且内容不同的快照时出现。 -->
    <div x-show="recoveryOpen" x-cloak x-ref="recoveryDialog" tabindex="-1"
         @keydown="dialogKeydown($event, $refs.recoveryDialog, null)"
         class="fixed inset-0 z-[150] flex items-center justify-center p-5"
         role="dialog" aria-modal="true" aria-labelledby="blox-recovery-title" data-testid="blox-recovery-dialog">
        <div class="absolute inset-0 bg-black/55"></div>
        <div class="relative w-full max-w-lg rounded-lg bg-white shadow-2xl">
            <div class="border-b border-gray-100 px-5 py-4">
                <h2 id="blox-recovery-title" class="text-base font-semibold text-gray-900" x-text="recoveryText.title"></h2>
                <p class="mt-1 text-sm leading-6 text-gray-500" x-text="recoveryText.desc"></p>
            </div>
            <div class="flex flex-col-reverse gap-2 px-5 py-4 sm:flex-row sm:justify-end">
                <button type="button" @click="discardRecovery()" data-testid="blox-recovery-discard"
                        class="h-9 px-4 rounded border border-gray-300 text-sm text-gray-700 hover:bg-gray-50"
                        x-text="recoveryText.discard"></button>
                <button type="button" @click="restoreRecovery()" data-testid="blox-recovery-restore"
                        data-dialog-initial
                        class="h-9 px-4 rounded bg-blue-600 text-sm font-medium text-white hover:bg-blue-500"
                        x-text="recoveryText.restore"></button>
            </div>
        </div>
    </div>

    <!-- 乐观并发冲突：本地内容已进恢复稿，不允许静默覆盖服务器版本。 -->
    <div x-show="conflictOpen" x-cloak x-ref="conflictDialog" tabindex="-1"
         @keydown="dialogKeydown($event, $refs.conflictDialog, () => continueAfterConflict())"
         class="fixed inset-0 z-[150] flex items-center justify-center p-5"
         role="dialog" aria-modal="true" aria-labelledby="blox-conflict-title" data-testid="blox-conflict-dialog">
        <div class="absolute inset-0 bg-black/55"></div>
        <div class="relative w-full max-w-lg rounded-lg bg-white shadow-2xl">
            <div class="border-b border-gray-100 px-5 py-4">
                <h2 id="blox-conflict-title" class="text-base font-semibold text-gray-900" x-text="recoveryText.conflictTitle"></h2>
                <p class="mt-1 text-sm leading-6 text-gray-500" x-text="recoveryText.conflictDesc"></p>
            </div>
            <div class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:flex-wrap sm:justify-end">
                <button type="button" @click="continueAfterConflict()" data-testid="blox-conflict-continue" data-dialog-initial
                        class="h-9 px-4 rounded border border-gray-300 text-sm text-gray-700 hover:bg-gray-50"
                        x-text="recoveryText.continueEditing"></button>
                <button type="button" @click="copyConflictDocument()" data-testid="blox-conflict-copy"
                        class="h-9 px-4 rounded border border-blue-300 text-sm text-blue-700 hover:bg-blue-50"
                        x-text="recoveryText.copy"></button>
                <button type="button" @click="reloadAfterConflict()" data-testid="blox-conflict-reload"
                        class="h-9 px-4 rounded bg-blue-600 text-sm font-medium text-white hover:bg-blue-500"
                        x-text="recoveryText.reload"></button>
            </div>
        </div>
    </div>

    <!-- toast -->
    <div x-show="toastMsg" x-transition data-testid="blox-toast"
         class="fixed bottom-5 left-1/2 -translate-x-1/2 bg-gray-900 text-white text-sm px-4 py-2 rounded-lg shadow-lg z-50"
         x-text="toastMsg" style="display:none"></div>

    <!-- context menu -->
    <div x-show="ctx.open" x-cloak @click.outside="closeCtx()" @contextmenu.prevent data-testid="blox-context-menu"
         class="fixed z-[120] min-w-44 rounded-lg border border-gray-200 bg-white py-1 shadow-2xl text-sm text-gray-700"
         :style="'left:' + ctx.x + 'px; top:' + ctx.y + 'px'">
        <template x-for="item in ctxItems()" :key="item.key">
            <div>
                <div x-show="item.sep" class="my-1 border-t border-gray-100"></div>
                <button type="button" x-show="!item.sep" @click="runCtx(item.key)" :disabled="item.disabled === true" :data-testid="'blox-context-' + item.key"
                        class="w-full h-8 px-3 inline-flex items-center gap-2 text-left disabled:opacity-40 disabled:cursor-not-allowed hover:bg-gray-50 disabled:hover:bg-white"
                        :class="item.danger ? 'text-red-600 hover:bg-red-50' : ''">
                    <i class="ti text-base shrink-0" :class="'ti-' + item.icon"></i>
                    <span class="flex-1" x-text="item.label"></span>
                </button>
            </div>
        </template>
    </div>
