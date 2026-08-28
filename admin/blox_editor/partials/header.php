<?php

declare(strict_types=1);
?>
    <!-- ===== 顶栏 ===== -->
    <header class="blox-editor-header h-14 bg-gray-900 text-white flex items-center justify-between px-4 gap-4 select-none">
        <div class="blox-header-brand flex items-center gap-3 min-w-0">
            <?php
            // 前台来源优先；没有来源时保留 back=home 与对象管理页的既有返回路径。
            $hasFrontendReturn = ($editorReturnTo ?? '') !== '';
            $bloxBackUrl = $hasFrontendReturn
                ? $editorReturnTo
                : (($editorBackTo ?? '') === 'home'
                    ? '/admin/blox_editor.php?home=1'
                    : ($templateId ? '/admin/blox_templates.php' : ($isHomeBlox ? '/admin/setting_home.php' : '/admin/page.php')));
            $bloxBackTitle = $hasFrontendReturn
                ? __('blox_return_to_page')
                : (($editorBackTo ?? '') === 'home' ? __('blox_back_to_home_editor') : __('admin_back'));
            ?>
            <a href="<?php echo e($bloxBackUrl); ?>" data-testid="blox-back"
               data-frontend-return="<?= $hasFrontendReturn ? '1' : '0' ?>"
               class="text-gray-300 hover:text-white inline-flex items-center gap-1 text-sm shrink-0" title="<?= e($bloxBackTitle) ?>">
                <i class="ti ti-chevron-left text-lg"></i>
                <?php if ($hasFrontendReturn || ($editorBackTo ?? '') === 'home'): ?>
                <span class="text-xs whitespace-nowrap"><?php echo e($bloxBackTitle); ?></span>
                <?php endif; ?>
            </a>
            <span class="blox-header-brand-copy inline-flex items-center gap-1.5 font-bold tracking-wide shrink-0">
                <i class="ti ti-stack-2 text-blue-400"></i>Blox
                <span class="text-[10px] font-medium bg-blue-500/20 text-blue-300 px-1.5 py-0.5 rounded"><?= __('label_experimental') ?></span>
            </span>
            <span class="blox-header-page min-w-0 text-gray-400 text-sm truncate">/ <?php echo e($isHomeBlox ? __('blox_home_draft') : $page['name']); ?></span>
            <?php if ($redirectedFromPage !== null): ?>
            <span data-testid="blox-redirect-source"
                  class="hidden lg:inline-flex items-center gap-1 text-[10px] font-medium bg-blue-500/15 text-blue-200 px-1.5 py-0.5 rounded shrink-0"
                  title="<?php echo e(__('blox_redirected_from_parent_tip', ['parent' => $redirectedFromPage['name'], 'target' => $page['name']])); ?>">
                <i class="ti ti-corner-down-right"></i><?php echo e(__('blox_redirected_from_parent', ['parent' => $redirectedFromPage['name']])); ?>
            </span>
            <?php endif; ?>
            <?php if ($pageLanguageVersions !== []): ?>
            <div data-testid="blox-language-switch" role="group" aria-label="<?= e(__('lse_versions')) ?>"
                 class="blox-header-languages inline-flex items-center rounded border border-gray-700 bg-gray-800 p-0.5 min-w-0 max-w-full overflow-x-auto">
                <?php foreach ($pageLanguageVersions as $languageVersion): ?>
                    <?php if ($languageVersion['id'] > 0): ?>
                    <a href="<?= e(BloxAreaEditorTarget::withReturnTo('/admin/blox_editor.php?id=' . (int) $languageVersion['id'], (string) ($editorReturnTo ?? ''))) ?>"
                       data-testid="blox-language-<?= e($languageVersion['code']) ?>"
                       title="<?= e($languageVersion['label'] . ($languageVersion['has_blox'] ? '' : ' · ' . __('blox_language_no_blox'))) ?>"
                       <?php if ($languageVersion['current']): ?>aria-current="page"<?php endif; ?>
                       class="relative min-w-7 h-6 rounded px-1.5 text-[10px] font-semibold inline-flex items-center justify-center transition <?= $languageVersion['current'] ? 'bg-blue-600 text-white' : 'text-gray-300 hover:bg-gray-700 hover:text-white' ?>">
                        <?= e($languageVersion['short']) ?>
                        <?php if (!$languageVersion['has_blox']): ?><span class="absolute right-0.5 top-0.5 w-1 h-1 rounded-full bg-amber-400"></span><?php endif; ?>
                    </a>
                    <?php else: ?>
                    <a href="/admin/page_edit.php?id=<?= (int) $page['id'] ?>"
                       data-testid="blox-language-<?= e($languageVersion['code']) ?>"
                       title="<?= e($languageVersion['label'] . ' · ' . __('lse_create_version')) ?>"
                       class="min-w-7 h-6 rounded px-1.5 text-[10px] font-semibold text-gray-500 hover:bg-gray-700 hover:text-amber-300 inline-flex items-center justify-center transition">
                        <?= e($languageVersion['short']) ?>
                    </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if ($isHomeBlox): ?>
            <span class="blox-header-legacy text-[10px] font-medium bg-amber-500/15 text-amber-300 px-1.5 py-0.5 rounded shrink-0"><?= __('blox_legacy_home_online') ?></span>
            <?php endif; ?>
            <?php if ($templateId && $templateType === 'header' && !$customHeaderEnabled): ?>
            <a href="/admin/blox_templates.php?type=header"
               data-testid="blox-header-disabled-status"
               title="<?= e(__('blox_custom_header_disabled_hint')) ?>"
               class="text-[10px] font-medium bg-amber-500/15 text-amber-300 px-1.5 py-0.5 rounded shrink-0 hover:bg-amber-500/25">
                <i class="ti ti-player-pause mr-0.5"></i><?= e(__('blox_custom_header_disabled_badge')) ?>
            </a>
            <?php endif; ?>
        </div>

        <!-- 设备切换 -->
        <div class="flex items-center gap-1 bg-gray-800 rounded-lg p-1">
            <template x-for="d in devices" :key="d.key">
                <button type="button" @click="previewDevice = d.key" :title="responsiveDeviceTitle(d.key)" :aria-label="responsiveDeviceTitle(d.key)"
                        :data-testid="'blox-device-' + d.key"
                        :data-responsive-state="selectedResponsiveOverrideCount(d.key) > 0 ? 'override' : 'inherit'"
                        :data-responsive-overrides="selectedResponsiveOverrideCount(d.key)"
                        class="relative w-8 h-7 rounded-md inline-flex items-center justify-center transition"
                        :class="previewDevice === d.key ? 'bg-blue-600 text-white' : 'text-gray-400 hover:text-white'">
                    <i class="ti text-base" :class="d.icon"></i>
                    <span x-show="selectedResponsiveOverrideCount(d.key) > 0"
                          class="absolute top-0.5 right-0.5 w-1.5 h-1.5 rounded-full bg-amber-400 ring-1 ring-gray-800"></span>
                </button>
            </template>
        </div>

        <div class="blox-header-actions flex items-center gap-2 shrink-0" data-testid="blox-desktop-actions">
            <span class="text-xs text-amber-300" x-show="dirty" data-testid="blox-dirty"><?= __('blox_dirty') ?></span>
            <div class="flex items-center gap-0.5 border-r border-gray-700 pr-2 mr-0.5">
                <button type="button" @click="undo()" :disabled="!canUndo()" data-testid="blox-undo"
                        title="<?php echo e(__('blox_undo_shortcut')); ?>" aria-label="<?php echo e(__('blox_undo')); ?>"
                        class="w-8 h-8 rounded inline-flex items-center justify-center text-gray-300 hover:text-white hover:bg-gray-800 disabled:opacity-30 disabled:cursor-not-allowed disabled:hover:bg-transparent transition">
                    <i class="ti ti-arrow-back-up text-base"></i>
                </button>
                <button type="button" @click="redo()" :disabled="!canRedo()" data-testid="blox-redo"
                        title="<?php echo e(__('blox_redo_shortcut')); ?>" aria-label="<?php echo e(__('blox_redo')); ?>"
                        class="w-8 h-8 rounded inline-flex items-center justify-center text-gray-300 hover:text-white hover:bg-gray-800 disabled:opacity-30 disabled:cursor-not-allowed disabled:hover:bg-transparent transition">
                    <i class="ti ti-arrow-forward-up text-base"></i>
                </button>
            </div>
<?php if (!$isHomeBlox && !$templateId): ?>
            <button type="button" @click="openRevisions()"
                    class="text-gray-300 hover:text-white text-sm inline-flex items-center gap-1 px-2 py-1.5" title="<?= e(__('revision_history')) ?>">
                <i class="ti ti-history text-base"></i>
            </button>
<?php endif; ?>
<?php if ($isHomeBlox): ?>
            <?php if ($advancedBloxEnabled): ?>
            <a href="<?= e($homeHeaderEditorUrl) ?>" data-testid="blox-home-header-settings"
               class="text-cyan-300 hover:text-white text-xs inline-flex items-center gap-1 px-2 py-1.5"
               title="<?= e(__('blox_edit_header_hint')) ?>">
                <i class="ti ti-layout-navbar"></i><?= e(__('blox_edit_header')) ?>
            </a>
            <?php endif; ?>
            <span x-show="homePublished" x-cloak class="text-[10px] text-emerald-300 inline-flex items-center gap-1">
                <i class="ti ti-world-check"></i><?php echo e(__('blox_published')); ?>
            </span>
            <span x-show="!homePublished" x-cloak class="text-[10px] text-amber-300 inline-flex items-center gap-1">
                <i class="ti ti-history-toggle"></i><?php echo e(__('blox_legacy_active')); ?>
            </span>
            <button type="button" @click="rollbackHome()" :disabled="homeActionBusy || !homePublished" data-testid="blox-rollback"
                    class="text-amber-300 hover:text-white disabled:opacity-30 text-xs inline-flex items-center gap-1 px-2 py-1.5"
                    title="<?php echo e(__('blox_rollback')); ?>">
                <i class="ti ti-restore"></i><?php echo e(__('blox_rollback')); ?>
            </button>
<?php elseif ($templateId): ?>
<?php if ($templateId && $templateType === 'header'): ?>
            <?php // 默认收起：进入页头编辑不应自动弹出设置层盖住画布，由用户点「网页头设置」展开。 ?>
            <details class="relative" data-testid="blox-sticky-settings">
                <summary class="list-none rounded bg-cyan-500/15 text-cyan-200 hover:bg-cyan-500/25 hover:text-white text-xs inline-flex items-center gap-1.5 px-2.5 py-1.5 cursor-pointer font-medium">
                    <i class="ti ti-layout-navbar"></i><?php echo e(__('blox_header_settings')); ?>
                </summary>
                <div class="absolute right-0 top-full z-50 mt-2 w-96 max-w-[calc(100vw-1rem)] border border-gray-700 bg-gray-900 p-3 shadow-2xl">
                    <label class="flex items-start gap-3 border border-gray-700 bg-gray-800 px-3 py-2.5 cursor-pointer"
                           title="<?php echo e(__('blox_sticky_header_hint')); ?>" data-testid="blox-sticky-toggle">
                        <input type="checkbox" x-model="docSettings.sticky" @change="markDocumentSettingsChanged(); refreshPreview()"
                               class="mt-0.5 rounded border-gray-600 bg-gray-800 text-blue-500 focus:ring-blue-500 focus:ring-offset-gray-900">
                        <span class="min-w-0">
                            <span class="block text-xs font-medium text-white"><?php echo e(__('blox_sticky_header')); ?></span>
                            <span class="mt-0.5 block text-[10px] leading-relaxed text-gray-400"><?php echo e(__('blox_sticky_header_hint')); ?></span>
                        </span>
                    </label>
                    <div x-show="docSettings.sticky" class="mt-3" data-testid="blox-sticky-options">
                        <label class="block text-xs text-gray-400 mb-1.5"><?php echo e(__('blox_sticky_behavior')); ?></label>
                        <select x-model="docSettings.sticky_behavior"
                                @change="markDocumentSettingsChanged(); refreshPreview()"
                                data-testid="blox-sticky-behavior"
                                class="w-full border border-gray-600 bg-gray-800 px-2 py-1.5 text-xs text-white">
                            <option value="always"><?php echo e(__('blox_sticky_always')); ?></option>
                            <option value="scroll-up"><?php echo e(__('blox_sticky_scroll_up')); ?></option>
                        </select>
                        <p class="mt-1 text-[10px] leading-relaxed text-gray-500"><?php echo e(__('blox_sticky_behavior_hint')); ?></p>
                        <div class="mt-3 text-xs text-gray-400 mb-1.5"><?php echo e(__('blox_sticky_devices')); ?></div>
                        <div class="grid grid-cols-3 gap-1.5">
                            <label class="flex min-w-0 items-center gap-1.5 whitespace-nowrap border border-gray-700 bg-gray-800 px-2 py-2 text-xs text-gray-200">
                                <input type="checkbox" :checked="stickyDeviceEnabled('desktop')"
                                       @change="toggleStickyDevice('desktop', $event.target.checked)"
                                       data-testid="blox-sticky-device-desktop"
                                       class="rounded border-gray-600 bg-gray-800 text-blue-500">
                                <span><?php echo e(__('blox_device_desktop')); ?></span>
                            </label>
                            <label class="flex min-w-0 items-center gap-1.5 whitespace-nowrap border border-gray-700 bg-gray-800 px-2 py-2 text-xs text-gray-200">
                                <input type="checkbox" :checked="stickyDeviceEnabled('tablet')"
                                       @change="toggleStickyDevice('tablet', $event.target.checked)"
                                       data-testid="blox-sticky-device-tablet"
                                       class="rounded border-gray-600 bg-gray-800 text-blue-500">
                                <span><?php echo e(__('blox_device_tablet')); ?></span>
                            </label>
                            <label class="flex min-w-0 items-center gap-1.5 whitespace-nowrap border border-gray-700 bg-gray-800 px-2 py-2 text-xs text-gray-200">
                                <input type="checkbox" :checked="stickyDeviceEnabled('mobile')"
                                       @change="toggleStickyDevice('mobile', $event.target.checked)"
                                       data-testid="blox-sticky-device-mobile"
                                       class="rounded border-gray-600 bg-gray-800 text-blue-500">
                                <span><?php echo e(__('blox_device_mobile')); ?></span>
                            </label>
                        </div>
                    </div>
                </div>
            </details>
            <details class="relative" data-testid="blox-header-state-settings">
                <summary class="list-none text-cyan-300 hover:text-white text-xs inline-flex items-center gap-1 px-2 py-1.5 cursor-pointer">
                    <i class="ti ti-layers-difference"></i><?php echo e(__('blox_header_states')); ?>
                </summary>
                <div class="absolute right-0 top-full z-50 mt-2 w-80 border border-gray-700 bg-gray-900 p-3 shadow-2xl">
                    <div class="grid grid-cols-3 gap-1 rounded border border-gray-700 bg-gray-800 p-1">
                        <button type="button" @click="setHeaderPreviewState('normal')"
                                data-testid="blox-header-state-normal"
                                :class="headerPreviewState === 'normal' ? 'bg-blue-600 text-white' : 'text-gray-400 hover:text-white'"
                                class="h-8 text-xs inline-flex items-center justify-center gap-1">
                            <i class="ti ti-layout-navbar"></i><?php echo e(__('blox_header_state_normal')); ?>
                        </button>
                        <button type="button" @click="setHeaderPreviewState('overlay')"
                                data-testid="blox-header-state-overlay"
                                :class="headerPreviewState === 'overlay' ? 'bg-blue-600 text-white' : 'text-gray-400 hover:text-white'"
                                class="h-8 text-xs inline-flex items-center justify-center gap-1">
                            <i class="ti ti-layers-intersect"></i><?php echo e(__('blox_header_state_overlay')); ?>
                        </button>
                        <button type="button" @click="setHeaderPreviewState('stuck')"
                                data-testid="blox-header-state-stuck"
                                :class="headerPreviewState === 'stuck' ? 'bg-blue-600 text-white' : 'text-gray-400 hover:text-white'"
                                class="h-8 text-xs inline-flex items-center justify-center gap-1">
                            <i class="ti ti-pin"></i><?php echo e(__('blox_header_state_stuck')); ?>
                        </button>
                    </div>
                    <label class="mt-3 flex items-center gap-2 text-xs text-gray-300">
                        <input type="checkbox" x-model="docSettings.header_overlay_enabled"
                               data-testid="blox-header-overlay-enabled"
                               @change="markDocumentSettingsChanged(); refreshPreview()"
                               class="rounded border-gray-600 bg-gray-800 text-blue-500">
                        <?php echo e(__('blox_header_overlay_enabled')); ?>
                    </label>
                    <div class="mt-3 grid grid-cols-[5rem_1fr] items-center gap-x-2 gap-y-2 text-xs">
                        <label class="text-gray-400"><?php echo e(__('blox_header_state_background')); ?></label>
                        <input type="text" x-model="docSettings.header_states[headerPreviewState].background"
                               data-testid="blox-header-state-background"
                               @change="markDocumentSettingsChanged(); refreshPreview()"
                               placeholder="transparent / #ffffff"
                               class="border border-gray-600 bg-gray-800 px-2 py-1.5 font-mono text-white">
                        <label class="text-gray-400"><?php echo e(__('blox_header_state_opacity')); ?></label>
                        <div class="flex items-center gap-2">
                            <input type="range" min="0" max="100" step="1"
                                   :value="headerStateOpacity()"
                                   @input.debounce.120ms="setHeaderStateOpacity($event.target.value)"
                                   data-testid="blox-header-state-opacity"
                                   class="min-w-0 flex-1 accent-blue-500">
                            <span class="w-10 text-right font-mono text-[10px] text-gray-300" x-text="headerStateOpacity() + '%'"></span>
                        </div>
                        <label class="text-gray-400"><?php echo e(__('blox_header_state_text')); ?></label>
                        <input type="text" x-model="docSettings.header_states[headerPreviewState].text"
                               data-testid="blox-header-state-text"
                               @change="markDocumentSettingsChanged(); refreshPreview()"
                               placeholder="#111827"
                               class="border border-gray-600 bg-gray-800 px-2 py-1.5 font-mono text-white">
                        <label class="text-gray-400"><?php echo e(__('blox_header_state_border')); ?></label>
                        <input type="text" x-model="docSettings.header_states[headerPreviewState].border"
                               data-testid="blox-header-state-border"
                               @change="markDocumentSettingsChanged(); refreshPreview()"
                               placeholder="transparent"
                               class="border border-gray-600 bg-gray-800 px-2 py-1.5 font-mono text-white">
                        <label class="text-gray-400"><?php echo e(__('blox_header_state_shadow')); ?></label>
                        <select x-model="docSettings.header_states[headerPreviewState].shadow"
                                data-testid="blox-header-state-shadow"
                                @change="markDocumentSettingsChanged(); refreshPreview()"
                                class="border border-gray-600 bg-gray-800 px-2 py-1.5 text-white">
                            <option value="none"><?php echo e(__('blox_spacing_none')); ?></option>
                            <option value="sm"><?php echo e(__('blox_spacing_sm')); ?></option>
                            <option value="md"><?php echo e(__('blox_spacing_md')); ?></option>
                            <option value="lg"><?php echo e(__('blox_spacing_lg')); ?></option>
                        </select>
                    </div>
                    <p class="mt-2 text-[10px] leading-relaxed text-gray-500"><?php echo e(__('blox_header_state_opacity_hint')); ?></p>
                    <p class="mt-1 text-[10px] leading-relaxed text-gray-500"><?php echo e(__('blox_header_states_hint')); ?></p>
                </div>
            </details>
<?php endif; ?>
<?php if ($templateId && $templateType === 'popup'): ?>
            <details class="relative" data-testid="blox-popup-settings">
                <summary class="list-none text-fuchsia-300 hover:text-white text-xs inline-flex items-center gap-1 px-2 py-1.5 cursor-pointer">
                    <i class="ti ti-adjustments"></i><?php echo e(__('blox_popup_settings')); ?>
                </summary>
                <div class="absolute right-0 top-full z-50 mt-2 w-80 border border-gray-700 bg-gray-900 p-3 shadow-2xl">
                    <div class="grid grid-cols-2 gap-3 text-xs">
                        <label class="space-y-1"><span class="text-gray-400"><?php echo e(__('blox_popup_trigger')); ?></span>
                            <select x-model="docSettings.trigger" @change="markDocumentSettingsChanged()" class="w-full border border-gray-600 bg-gray-800 px-2 py-1.5 text-white">
                                <option value="delay"><?php echo e(__('blox_popup_trigger_delay')); ?></option>
                                <option value="exit"><?php echo e(__('blox_popup_trigger_exit')); ?></option>
                                <option value="click"><?php echo e(__('blox_popup_trigger_click')); ?></option>
                            </select>
                        </label>
                        <label x-show="docSettings.trigger === 'delay'" class="space-y-1"><span class="text-gray-400"><?php echo e(__('blox_popup_delay_seconds')); ?></span>
                            <input type="number" min="0" max="60" x-model.number="docSettings.delay" @change="markDocumentSettingsChanged()" class="w-full border border-gray-600 bg-gray-800 px-2 py-1.5 text-white">
                        </label>
                        <label x-show="docSettings.trigger === 'click'" class="col-span-2 space-y-1"><span class="text-gray-400"><?php echo e(__('blox_popup_selector')); ?></span>
                            <input type="text" x-model="docSettings.selector" @change="markDocumentSettingsChanged()" placeholder="#offer-button" class="w-full border border-gray-600 bg-gray-800 px-2 py-1.5 font-mono text-white">
                        </label>
                        <label class="space-y-1"><span class="text-gray-400"><?php echo e(__('blox_popup_frequency')); ?></span>
                            <select x-model="docSettings.frequency" @change="markDocumentSettingsChanged()" class="w-full border border-gray-600 bg-gray-800 px-2 py-1.5 text-white">
                                <option value="every"><?php echo e(__('blox_popup_frequency_every')); ?></option>
                                <option value="session"><?php echo e(__('blox_popup_frequency_session')); ?></option>
                                <option value="hours"><?php echo e(__('blox_popup_frequency_hours')); ?></option>
                            </select>
                        </label>
                        <label x-show="docSettings.frequency === 'hours'" class="space-y-1"><span class="text-gray-400"><?php echo e(__('blox_popup_hours')); ?></span>
                            <input type="number" min="1" max="720" x-model.number="docSettings.hours" @change="markDocumentSettingsChanged()" class="w-full border border-gray-600 bg-gray-800 px-2 py-1.5 text-white">
                        </label>
                        <label class="space-y-1"><span class="text-gray-400"><?php echo e(__('blox_popup_device')); ?></span>
                            <select x-model="docSettings.device" @change="markDocumentSettingsChanged()" class="w-full border border-gray-600 bg-gray-800 px-2 py-1.5 text-white">
                                <option value="all"><?php echo e(__('blox_popup_device_all')); ?></option>
                                <option value="desktop"><?php echo e(__('blox_popup_device_desktop')); ?></option>
                                <option value="mobile"><?php echo e(__('blox_popup_device_mobile')); ?></option>
                            </select>
                        </label>
                        <label class="space-y-1"><span class="text-gray-400"><?php echo e(__('blox_popup_width')); ?></span>
                            <select x-model="docSettings.width" @change="markDocumentSettingsChanged()" class="w-full border border-gray-600 bg-gray-800 px-2 py-1.5 text-white">
                                <option value="sm">S</option><option value="md">M</option><option value="lg">L</option><option value="xl">XL</option>
                            </select>
                        </label>
                        <label class="col-span-2 inline-flex items-center gap-2 text-gray-300"><input type="checkbox" x-model="docSettings.overlay_close" @change="markDocumentSettingsChanged()"><?php echo e(__('blox_popup_overlay_close')); ?></label>
                        <label class="col-span-2 inline-flex items-center gap-2 text-gray-300"><input type="checkbox" x-model="docSettings.show_close" @change="markDocumentSettingsChanged()"><?php echo e(__('blox_popup_show_close')); ?></label>
                    </div>
                </div>
            </details>
<?php endif; ?>
<?php if ($areaCtxOptions !== []): ?>
            <label class="inline-flex min-w-0 items-center gap-1.5 text-xs text-gray-400">
                <span class="whitespace-nowrap"><?php echo e(__('blox_ctx_label')); ?></span>
                <select x-model="previewContext" @change="ctxChanged()" data-testid="blox-ctx-select"
                        title="<?php echo e(__('blox_ctx_label')); ?>" aria-label="<?php echo e(__('blox_ctx_label')); ?>"
                        class="bg-gray-800 border border-gray-700 text-gray-200 text-xs rounded-lg px-2 py-1.5 max-w-[12rem]">
                    <?php foreach ($areaCtxOptions as $ctxOpt): ?>
                    <option value="<?php echo e($ctxOpt['value']); ?>"><?php echo e($ctxOpt['label']); ?></option>
                    <?php endforeach; ?>
                    <?php foreach ($areaCtxOptionGroups as $ctxGroup): ?>
                    <optgroup label="<?php echo e($ctxGroup['label']); ?>">
                        <?php foreach ($ctxGroup['options'] as $ctxOpt): ?>
                        <option value="<?php echo e($ctxOpt['value']); ?>"><?php echo e($ctxOpt['label']); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
            </label>
            <span x-show="ctxHit !== null && ctxHit !== <?php echo (int) $templateId; ?>" x-cloak
                  data-testid="blox-ctx-warn"
                  class="text-[10px] text-amber-300 inline-flex items-center gap-1 max-w-[14rem]">
                <i class="ti ti-eye-off"></i>
                <span x-text="ctxHit === 0 ? <?= e($jt('blox_ctx_hit_none')) ?> : <?= e($jt('blox_ctx_hit_other')) ?>.replace(':id', ctxHit)"></span>
            </span>
<?php endif; ?>
<?php endif; ?>
            <button type="button" x-show="draftSummary().changed" x-cloak @click="openDraftSummary()"
                    data-testid="blox-draft-summary-open"
                    class="h-8 max-w-52 rounded border border-amber-400/30 bg-amber-400/10 px-2.5 text-xs font-medium text-amber-200 inline-flex items-center gap-1.5 hover:border-amber-300/60 hover:bg-amber-400/15 hover:text-white transition-colors"
                    :title="draftSummaryText.title" :aria-label="draftSummaryCountText()">
                <i class="ti ti-list-details shrink-0 text-sm"></i>
                <span class="truncate" x-text="draftSummaryCountText()"></span>
            </button>
            <span class="text-xs text-gray-400" x-show="previewLoading"><?= __('blox_refreshing') ?></span>
            <div class="inline-flex items-center gap-0.5 border-r border-gray-700 pr-2 mr-0.5"
                 role="group" aria-label="<?php echo e(__('blox_add_content')); ?>">
                <button type="button" @click="openElementLibrary()" data-testid="blox-elements-open"
                        class="w-8 h-8 rounded inline-flex items-center justify-center text-gray-300 hover:text-white hover:bg-gray-800 transition"
                        title="<?php echo e(__('blox_open_elements')); ?>" aria-label="<?php echo e(__('blox_open_elements')); ?>">
                    <i class="ti ti-circle-plus text-lg"></i>
                </button>
            </div>
<?php if ($canManageBloxDesign): ?>
            <button type="button" @click="openDesignSystem()" data-testid="blox-design-open"
                    class="text-gray-300 hover:text-emerald-300 text-sm inline-flex items-center gap-1 px-2 py-1.5 transition-colors"
                    title="<?php echo e(__('blox_design_system')); ?>" aria-label="<?php echo e(__('blox_design_system')); ?>">
                <i class="ti ti-palette text-base"></i><span class="text-xs"><?php echo e(__('blox_design_system')); ?></span>
            </button>
<?php endif; ?>
<?php if ($canManageBloxDesign): ?>
            <button type="button" @click="clearSiteCache()" :disabled="cacheClearing"
                    data-testid="blox-clear-cache"
                    class="w-8 h-8 rounded inline-flex items-center justify-center text-gray-300 hover:text-white hover:bg-gray-800 disabled:opacity-40 disabled:cursor-wait transition"
                    title="<?php echo e(__('scache_clear_now')); ?>" aria-label="<?php echo e(__('scache_clear_now')); ?>">
                <i class="ti text-base" :class="cacheClearing ? 'ti-loader-2 animate-spin' : 'ti-database-off'"></i>
            </button>
<?php endif; ?>
            <?php
            // 前台预览目标按编辑对象定：首页 / 单页各指自身；页头/页尾模板指首页
            //（在真实页面上看头尾效果）；区块/弹窗等模板没有对应前台页——不显示。
            // 此前模板模式漏兜底，$page['slug'] 为空拼出坏链接「/.html?preview」。
            $frontPreviewUrl = null;
            if ($templateId) {
                if (in_array($templateType ?? '', ['header', 'footer'], true)) {
                    $frontPreviewUrl = '/?preview';
                }
            } elseif ($isHomeBlox) {
                $frontPreviewUrl = '/?preview';
            } else {
                $frontPreviewUrl = '/' . $page['slug'] . '.html?preview';
            }
            ?>
            <?php if ($frontPreviewUrl !== null): ?>
            <a href="<?php echo e($frontPreviewUrl); ?>" target="_blank" rel="noopener"
               data-testid="blox-front-preview"
               class="text-gray-300 hover:text-white text-sm inline-flex items-center gap-1 px-2 py-1.5" title="<?= e(__('blox_front_preview')) ?>">
                <i class="ti ti-eye text-base"></i><span class="text-xs"><?php echo e(__('blox_front_preview')); ?></span>
            </a>
            <?php endif; ?>
            <div class="inline-flex items-center gap-1" data-testid="blox-save-publish-actions">
                <button type="button" @click="save()" :disabled="saving || homeActionBusy || pageActionBusy" data-testid="blox-save"
                        class="h-8 bg-blue-600 hover:bg-blue-500 disabled:opacity-50 text-white text-xs font-medium px-2 rounded inline-flex items-center justify-center gap-1 transition">
                    <i class="ti text-base" :class="saving ? 'ti-loader-2 animate-spin' : 'ti-device-floppy'"></i>
                    <span x-text="saving ? <?= e($jt('blox_saving')) ?> : '<?php echo ($isHomeBlox || !$templateId) ? __('blox_save_draft') : __('save'); ?>'"></span>
                </button>
<?php if ($isHomeBlox): ?>
                <button type="button" @click="publishHome()" :disabled="homeActionBusy || saving" data-testid="blox-publish"
                        class="h-8 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white text-xs font-medium px-3 rounded inline-flex items-center justify-center gap-1.5 transition"
                        title="<?php echo e(__('blox_publish_saves_current')); ?>">
                    <i class="ti ti-rocket text-base"></i><?php echo e(__('blox_publish')); ?>
                </button>
<?php elseif ($templateId): ?>
                <button type="button" @click="publishTemplate()" :disabled="saving" data-testid="blox-publish-template"
                        class="h-8 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white text-xs font-medium px-3 rounded inline-flex items-center justify-center gap-1.5 transition"
                        title="<?php echo e(__('blox_tpl_publish_saves_current')); ?>">
                    <i class="ti ti-rocket text-base"></i><?php echo e($replaceThemeAreaOnPublish !== '' ? __('blox_tpl_publish_and_use') : __('blox_tpl_publish_draft')); ?>
                </button>
<?php else: ?>
                <button type="button" @click="publishPage()" :disabled="pageActionBusy || saving" data-testid="blox-publish-page"
                        class="h-8 bg-emerald-600 hover:bg-emerald-500 disabled:opacity-50 text-white text-xs font-medium px-3 rounded inline-flex items-center justify-center gap-1.5 transition"
                        title="<?php echo e(__('blox_page_publish_saves_current')); ?>">
                    <i class="ti ti-rocket text-base"></i><?php echo e(__('blox_page_publish')); ?>
                </button>
<?php endif; ?>
            </div>
        </div>
        <div class="blox-mobile-actions" data-testid="blox-mobile-actions">
            <button type="button" @click="mobileActionsOpen = !mobileActionsOpen" data-testid="blox-mobile-actions-open"
                    :aria-expanded="mobileActionsOpen ? 'true' : 'false'"
                    aria-label="<?php echo e(__('admin_actions')); ?>" title="<?php echo e(__('admin_actions')); ?>"
                    class="w-9 h-9 rounded inline-flex items-center justify-center text-gray-200 hover:text-white hover:bg-gray-800">
                <i class="ti ti-dots-vertical text-lg"></i>
            </button>
            <div x-show="mobileActionsOpen" x-cloak @click.outside="mobileActionsOpen = false"
                 @keydown.escape.window="mobileActionsOpen = false" class="blox-mobile-actions-menu">
                <button type="button" @click="undo(); mobileActionsOpen = false" :disabled="!canUndo()">
                    <i class="ti ti-arrow-back-up"></i><?php echo e(__('blox_undo')); ?>
                </button>
                <button type="button" @click="redo(); mobileActionsOpen = false" :disabled="!canRedo()">
                    <i class="ti ti-arrow-forward-up"></i><?php echo e(__('blox_redo')); ?>
                </button>
                <button type="button" x-show="draftSummary().changed" x-cloak @click="openDraftSummary()"
                        data-testid="blox-mobile-draft-summary-open">
                    <i class="ti ti-list-details"></i><span x-text="draftSummaryCountText()"></span>
                </button>
<?php if (!$isHomeBlox): ?>
                <button type="button" @click="openRevisions(); mobileActionsOpen = false">
                    <i class="ti ti-history"></i><?php echo e(__('revision_history')); ?>
                </button>
<?php endif; ?>
                <button type="button" @click="save(); mobileActionsOpen = false" :disabled="saving || homeActionBusy || pageActionBusy">
                    <i class="ti ti-device-floppy"></i><?php echo e(($isHomeBlox || !$templateId) ? __('blox_save_draft') : __('save')); ?>
                </button>
<?php if ($isHomeBlox): ?>
                <button type="button" @click="publishHome(); mobileActionsOpen = false" :disabled="homeActionBusy || saving">
                    <i class="ti ti-rocket"></i><?php echo e(__('blox_publish')); ?>
                </button>
                <button type="button" @click="rollbackHome(); mobileActionsOpen = false" :disabled="homeActionBusy || !homePublished">
                    <i class="ti ti-restore"></i><?php echo e(__('blox_rollback')); ?>
                </button>
<?php elseif (!$templateId): ?>
                <button type="button" @click="publishPage(); mobileActionsOpen = false" :disabled="pageActionBusy || saving">
                    <i class="ti ti-rocket"></i><?php echo e(__('blox_page_publish')); ?>
                </button>
                <?php foreach ($pageLanguageVersions as $languageVersion): ?>
                <?php $mobileLanguageUrl = $languageVersion['id'] > 0
                    ? BloxAreaEditorTarget::withReturnTo('/admin/blox_editor.php?id=' . (int) $languageVersion['id'], (string) ($editorReturnTo ?? ''))
                    : '/admin/page_edit.php?id=' . (int) $page['id']; ?>
                <a href="<?= e($mobileLanguageUrl) ?>"
                   data-testid="blox-mobile-language-<?= e($languageVersion['code']) ?>">
                    <i class="ti ti-language"></i><?= e($languageVersion['label']) ?><?= $languageVersion['current'] ? ' · ' . e(__('lse_current')) : '' ?>
                </a>
                <?php endforeach; ?>
<?php else: ?>
                <button type="button" @click="publishTemplate(); mobileActionsOpen = false" :disabled="saving">
                    <i class="ti ti-rocket"></i><?php echo e($replaceThemeAreaOnPublish !== '' ? __('blox_tpl_publish_and_use') : __('blox_tpl_publish_draft')); ?>
                </button>
<?php endif; ?>
                <button type="button" @click="openElementLibrary(); mobileActionsOpen = false">
                    <i class="ti ti-circle-plus"></i><?php echo e(__('blox_open_elements')); ?>
                </button>
<?php if ($templateId && ($templateType ?? '') === 'header'): ?>
                <button type="button" @click="openHeaderPresets(); mobileActionsOpen = false">
                    <i class="ti ti-layout-navbar"></i><?php echo e(__('blox_header_presets')); ?>
                </button>
<?php else: ?>
                <button type="button" @click="openPrebuiltSections(); mobileActionsOpen = false">
                    <i class="ti ti-layout-grid-add"></i><?php echo e(__('blox_prebuilt_sections')); ?>
                </button>
<?php if (!$isHomeBlox && !$templateId): ?>
                <button type="button" @click="openPageTemplates(); mobileActionsOpen = false">
                    <i class="ti ti-files"></i><?php echo e(__('blox_page_library')); ?>
                </button>
<?php endif; ?>
<?php endif; ?>
<?php if ($canManageBloxDesign): ?>
                <button type="button" @click="openDesignSystem(); mobileActionsOpen = false">
                    <i class="ti ti-palette"></i><?php echo e(__('blox_design_system')); ?>
                </button>
                <button type="button" @click="clearSiteCache(); mobileActionsOpen = false" :disabled="cacheClearing">
                    <i class="ti" :class="cacheClearing ? 'ti-loader-2 animate-spin' : 'ti-database-off'"></i><?php echo e(__('scache_clear_now')); ?>
                </button>
<?php endif; ?>
                <a :href="homeMode ? '/?preview' : ('/' + '<?php echo e($page['slug']); ?>' + '.html?preview')" target="_blank" rel="noopener"
                   @click="mobileActionsOpen = false">
                    <i class="ti ti-eye"></i><?php echo e(__('blox_front_preview')); ?>
                </a>
            </div>
        </div>
    </header>
