<?php

declare(strict_types=1);
?>
    <!-- 元素、区块、列和容器共用的颜色选择器。值仍保存为 HEX 或稳定站点令牌引用。 -->
    <div x-show="colorPicker.open" x-cloak @keydown.escape.window="closeEditorColorPicker()"
         class="fixed inset-0 z-[170]" data-testid="blox-editor-color-picker-layer">
        <button type="button" class="absolute inset-0 cursor-default bg-transparent" @click="closeEditorColorPicker()"
                tabindex="-1" aria-hidden="true"></button>
        <section role="dialog" aria-modal="false" :aria-label="colorPicker.title"
                 data-testid="blox-editor-color-picker"
                 class="absolute w-[304px] max-w-[calc(100vw-24px)] border border-gray-200 bg-white shadow-xl"
                 :style="colorPicker.style">
            <header class="flex h-11 items-center justify-between border-b border-gray-100 px-3">
                <h3 class="min-w-0 truncate text-sm font-semibold text-gray-900" x-text="colorPicker.title"></h3>
                <button type="button" @click="closeEditorColorPicker()"
                        class="inline-flex h-8 w-8 shrink-0 items-center justify-center text-gray-400 hover:bg-gray-100 hover:text-gray-700"
                        :aria-label="designText.close" :title="designText.close">
                    <i class="ti ti-x"></i>
                </button>
            </header>
            <div class="max-h-[min(520px,calc(100vh-90px))] overflow-y-auto blox-scroll p-3">
                <div>
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <span class="text-xs font-medium text-gray-600" x-text="designText.siteColors"></span>
                        <a href="/admin/blox_design.php" target="_blank" rel="noopener"
                           class="inline-flex items-center gap-1 text-[11px] text-blue-600 hover:text-blue-800"
                           :title="designText.manageColors">
                            <span x-text="designText.manageColors"></span><i class="ti ti-external-link"></i>
                        </a>
                    </div>
                    <div class="grid grid-cols-8 gap-2" data-testid="blox-editor-color-site-colors">
                        <template x-for="token in activeColorTokens()" :key="'editor-picker-token-' + token.id">
                            <button type="button" @click="applyEditorColor(colorTokenRef(token.id), false)"
                                    :title="token.name + ' · ' + token.value" :aria-label="token.name + ' ' + token.value"
                                    :data-testid="'blox-editor-color-token-' + token.id"
                                    class="relative h-7 w-7 border border-black/10 hover:scale-110 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
                                    :class="colorPicker.raw === colorTokenRef(token.id) ? 'ring-2 ring-blue-500 ring-offset-2' : ''"
                                    :style="'background:' + token.value">
                                <i x-show="colorPicker.raw === colorTokenRef(token.id)" class="ti ti-check absolute inset-0 flex items-center justify-center text-sm" :class="colorPickerCheckClass(token.value)"></i>
                            </button>
                        </template>
                    </div>
                </div>

                <div class="mt-4 border-t border-gray-100 pt-3">
                    <span class="mb-2 block text-xs font-medium text-gray-600" x-text="designText.recommended"></span>
                    <div class="space-y-2">
                        <template x-for="group in colorPaletteGroups" :key="group.id">
                            <div class="grid grid-cols-8 gap-2">
                                <template x-for="color in group.colors" :key="group.id + '-' + color">
                                    <button type="button" @click="applyEditorColor(color, true)" :title="color" :aria-label="color"
                                            class="relative h-7 w-7 border border-black/10 hover:scale-110 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-1"
                                            :class="colorPicker.raw.toLowerCase() === color ? 'ring-2 ring-blue-500 ring-offset-1' : ''" :style="'background:' + color">
                                        <i x-show="colorPicker.raw.toLowerCase() === color" class="ti ti-check absolute inset-0 flex items-center justify-center text-sm" :class="colorPickerCheckClass(color)"></i>
                                    </button>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>

                <div x-show="colorRecent.length" class="mt-4 border-t border-gray-100 pt-3">
                    <span class="mb-2 block text-xs font-medium text-gray-600" x-text="designText.recent"></span>
                    <div class="flex flex-wrap gap-2">
                        <template x-for="color in colorRecent" :key="'editor-recent-' + color">
                            <button type="button" @click="applyEditorColor(color, true)" :title="color" :aria-label="color"
                                    class="h-7 w-7 border border-black/10 hover:scale-110 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2" :style="'background:' + color"></button>
                        </template>
                    </div>
                </div>

                <div class="mt-4 border-t border-gray-100 pt-3">
                    <label class="mb-2 block text-xs font-medium text-gray-600" for="blox-editor-custom-color" x-text="designText.custom"></label>
                    <div class="flex h-10 items-center gap-2 border bg-white px-2 focus-within:ring-2"
                         :class="colorPicker.invalid ? 'border-red-400 focus-within:border-red-500 focus-within:ring-red-100' : 'border-gray-300 focus-within:border-blue-500 focus-within:ring-blue-100'">
                        <input id="blox-editor-custom-color" type="color" :value="colorPicker.custom"
                               @input="applyEditorColor($event.target.value, true)"
                               class="h-7 w-9 shrink-0 cursor-pointer border-0 bg-transparent p-0" data-testid="blox-editor-color-native">
                        <input type="text" :value="colorPicker.custom" @input="colorPicker.invalid = false"
                               @change="applyEditorColorText($event.target.value, $event.target)"
                               @keydown.enter.prevent="applyEditorColorText($event.target.value, $event.target)"
                               x-bind:aria-invalid="colorPicker.invalid" pattern="#[0-9a-fA-F]{6}" maxlength="7" spellcheck="false"
                               class="min-w-0 flex-1 border-0 bg-transparent font-mono text-sm uppercase outline-none"
                               data-testid="blox-editor-color-text">
                    </div>
                    <p x-show="colorPicker.invalid" class="mt-2 text-[11px] leading-4 text-red-600" role="alert" x-text="designText.invalidColor"></p>
                    <p class="mt-2 text-[11px] leading-4 text-gray-400" x-text="designText.pickerHint"></p>
                </div>
            </div>
            <footer x-show="colorPicker.allowClear" class="flex h-11 items-center justify-end border-t border-gray-100 px-3">
                <button type="button" @click="applyEditorColor('', false); closeEditorColorPicker()"
                        class="inline-flex h-8 items-center gap-1.5 px-3 text-xs text-gray-600 hover:bg-gray-100 hover:text-red-600"
                        data-testid="blox-editor-color-clear">
                    <i class="ti ti-eraser"></i><span x-text="designText.clear"></span>
                </button>
            </footer>
        </section>
    </div>

    <!-- 未发布变化摘要：轻量侧栏，不打断画布操作，关闭后按稳定区块 ID 精确定位。 -->
    <div x-show="draftSummaryOpen" x-cloak class="fixed inset-0 z-[115] pointer-events-none"
         @keydown.escape.window="closeDraftSummary()">
        <button type="button" class="absolute inset-0 bg-black/20 pointer-events-auto lg:hidden"
                @click="closeDraftSummary()" :aria-label="templateText.close"></button>
        <aside x-ref="draftSummaryPanel" tabindex="-1" data-testid="blox-draft-summary-panel"
               aria-labelledby="blox-draft-summary-title"
               class="absolute right-0 top-14 bottom-0 w-[25rem] max-w-full bg-white border-l border-gray-200 shadow-2xl pointer-events-auto flex flex-col focus:outline-none">
            <header class="min-h-14 px-4 py-3 border-b border-gray-100 flex items-start gap-3 shrink-0">
                <span class="mt-0.5 w-8 h-8 rounded bg-amber-50 text-amber-700 inline-flex items-center justify-center shrink-0">
                    <i class="ti ti-list-details text-lg"></i>
                </span>
                <span class="min-w-0 flex-1">
                    <strong id="blox-draft-summary-title" class="block text-sm font-semibold text-gray-800" x-text="draftSummaryText.title"></strong>
                    <span class="mt-0.5 block text-xs leading-5 text-gray-500" x-text="draftSummaryText.description"></span>
                </span>
                <button type="button" @click="closeDraftSummary()"
                        class="w-8 h-8 rounded inline-flex items-center justify-center text-gray-400 hover:bg-gray-100 hover:text-gray-700"
                        :title="templateText.close" :aria-label="templateText.close">
                    <i class="ti ti-x text-base"></i>
                </button>
            </header>

            <div class="px-4 py-3 border-b border-gray-100 flex flex-wrap gap-1.5 shrink-0" data-testid="blox-draft-summary-totals">
                <span x-show="draftSummary().totals.added" class="rounded bg-emerald-50 px-2 py-1 text-[11px] font-medium text-emerald-700"
                      x-text="draftSummaryText.added + ' ' + draftSummary().totals.added"></span>
                <span x-show="draftSummary().totals.removed" class="rounded bg-red-50 px-2 py-1 text-[11px] font-medium text-red-700"
                      x-text="draftSummaryText.removed + ' ' + draftSummary().totals.removed"></span>
                <span x-show="draftSummary().totals.moved" class="rounded bg-blue-50 px-2 py-1 text-[11px] font-medium text-blue-700"
                      x-text="draftSummaryText.moved + ' ' + draftSummary().totals.moved"></span>
                <span x-show="draftSummary().totals.content" class="rounded bg-violet-50 px-2 py-1 text-[11px] font-medium text-violet-700"
                      x-text="draftSummaryText.content + ' ' + draftSummary().totals.content"></span>
                <span x-show="draftSummary().totals.style" class="rounded bg-cyan-50 px-2 py-1 text-[11px] font-medium text-cyan-700"
                      x-text="draftSummaryText.style + ' ' + draftSummary().totals.style"></span>
                <span x-show="draftSummary().totals.settings" class="rounded bg-gray-100 px-2 py-1 text-[11px] font-medium text-gray-700"
                      x-text="draftSummaryText.settings"></span>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto blox-scroll" aria-live="polite">
                <p x-show="!draftSummary().changed" class="px-5 py-12 text-center text-sm text-gray-400"
                   x-text="draftSummaryText.empty"></p>
                <template x-for="(item, index) in draftSummary().items" :key="(item.id || 'settings') + '-' + index">
                    <div class="px-4 py-3 border-b border-gray-100 flex items-start gap-3" data-testid="blox-draft-summary-item">
                        <span class="mt-0.5 w-7 h-7 rounded bg-gray-50 text-gray-500 inline-flex items-center justify-center shrink-0">
                            <i class="ti text-sm" :class="item.settings ? 'ti-adjustments' : (item.removed ? 'ti-trash' : 'ti-layout')"></i>
                        </span>
                        <span class="min-w-0 flex-1">
                            <strong class="block truncate text-xs font-semibold text-gray-800" x-text="draftChangeLabel(item)"></strong>
                            <span class="mt-1 flex flex-wrap gap-1">
                                <span x-show="item.added" class="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] text-emerald-700" x-text="draftSummaryText.added"></span>
                                <span x-show="item.removed" class="rounded bg-red-50 px-1.5 py-0.5 text-[10px] text-red-700" x-text="draftSummaryText.removed"></span>
                                <span x-show="item.moved" class="rounded bg-blue-50 px-1.5 py-0.5 text-[10px] text-blue-700" x-text="draftSummaryText.moved"></span>
                                <span x-show="item.content" class="rounded bg-violet-50 px-1.5 py-0.5 text-[10px] text-violet-700" x-text="draftSummaryText.content"></span>
                                <span x-show="item.style" class="rounded bg-cyan-50 px-1.5 py-0.5 text-[10px] text-cyan-700" x-text="draftSummaryText.style"></span>
                                <span x-show="item.settings" class="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-700" x-text="draftSummaryText.settings"></span>
                            </span>
                            <span x-show="item.removed" class="mt-1.5 block text-[11px] leading-4 text-gray-400" x-text="draftSummaryText.removedHint"></span>
                        </span>
                        <button type="button" x-show="item.canLocate" @click="locateDraftChange(item)"
                                data-testid="blox-draft-summary-locate"
                                class="h-8 shrink-0 rounded border border-gray-200 px-2.5 text-xs font-medium text-gray-600 inline-flex items-center gap-1 hover:border-blue-300 hover:text-blue-700">
                            <i class="ti ti-focus-2 text-sm"></i><span x-text="draftSummaryText.locate"></span>
                        </button>
                    </div>
                </template>
            </div>
        </aside>
    </div>

    <!-- 页面标题区：系统区域不进入正文文档，单独保存来源、背景与显示开关。 -->
    <div x-show="pageHeroOpen" x-cloak x-ref="pageHeroDialog" tabindex="-1"
         data-testid="blox-page-hero-dialog"
         @keydown="dialogKeydown($event, $refs.pageHeroDialog, () => closePageHeroSettings())"
         role="dialog" aria-modal="true" aria-labelledby="blox-page-hero-dialog-title"
         class="fixed inset-0 z-[145] flex items-center justify-center p-4 sm:p-6">
        <div class="absolute inset-0 bg-black/50" @click="closePageHeroSettings()"></div>
        <div class="relative flex max-h-[calc(100vh-2rem)] w-[720px] max-w-full flex-col overflow-hidden rounded-lg bg-white shadow-2xl">
            <header class="flex min-h-14 items-center gap-3 border-b border-gray-100 px-4 py-3">
                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded bg-gray-900 text-white">
                    <i class="ti ti-layout-navbar-collapse text-lg"></i>
                </span>
                <span class="min-w-0 flex-1">
                    <strong id="blox-page-hero-dialog-title" class="block text-sm font-semibold text-gray-900" x-text="pageHeroText.title"></strong>
                    <span class="mt-0.5 block text-xs leading-5 text-gray-500" x-text="pageHeroText.description"></span>
                </span>
                <button type="button" @click="closePageHeroSettings()" :disabled="pageHeroSaving"
                        class="inline-flex h-8 w-8 items-center justify-center text-gray-400 hover:bg-gray-100 hover:text-gray-700 disabled:opacity-40"
                        :aria-label="templateText.close" :title="templateText.close">
                    <i class="ti ti-x"></i>
                </button>
            </header>
            <div class="min-h-0 flex-1 space-y-5 overflow-y-auto p-4 sm:p-5">
                <label class="flex items-center justify-between gap-4 border-b border-gray-100 pb-4">
                    <span>
                        <strong class="block text-sm font-medium text-gray-800" x-text="pageHeroText.visible"></strong>
                        <span class="mt-1 block text-xs text-gray-500" x-text="pageHero.name"></span>
                    </span>
                    <input type="checkbox" x-model="pageHero.show_hero" data-dialog-initial
                           class="h-5 w-5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                </label>
                <fieldset>
                    <legend class="mb-2 text-sm font-medium text-gray-800" x-text="pageHeroText.styleSource"></legend>
                    <div class="grid grid-cols-3 gap-2">
                        <label class="cursor-pointer border px-3 py-2.5 text-center text-sm transition"
                               data-testid="blox-page-hero-mode-self"
                               :class="pageHero.style_source === 'self' ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 text-gray-600 hover:border-gray-400'">
                            <input type="radio" value="self" x-model="pageHero.style_source" class="sr-only">
                            <span x-text="pageHeroText.modeSelf"></span>
                        </label>
                        <label class="border px-3 py-2.5 text-center text-sm transition"
                               data-testid="blox-page-hero-mode-parent"
                               :class="[pageHero.can_inherit ? 'cursor-pointer' : 'cursor-not-allowed opacity-40', pageHero.style_source === 'parent' ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 text-gray-600 hover:border-gray-400']">
                            <input type="radio" value="parent" x-model="pageHero.style_source" :disabled="!pageHero.can_inherit" class="sr-only">
                            <span x-text="pageHeroText.modeParent"></span>
                        </label>
                        <label class="cursor-pointer border px-3 py-2.5 text-center text-sm transition"
                               data-testid="blox-page-hero-mode-global"
                               :class="pageHero.style_source === 'global' ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 text-gray-600 hover:border-gray-400'">
                            <input type="radio" value="global" x-model="pageHero.style_source" class="sr-only">
                            <span x-text="pageHeroText.modeGlobal"></span>
                        </label>
                    </div>
                    <p class="mt-2 text-xs leading-5 text-gray-500" x-text="pageHeroModeHint()"></p>
                </fieldset>
                <div class="flex flex-wrap items-center gap-3 border-y border-gray-100 bg-gray-50 px-3 py-3" data-testid="blox-page-hero-effective-source">
                    <span class="min-w-0 flex-1">
                        <span class="block text-xs text-gray-500" x-text="pageHeroText.effectiveSource"></span>
                        <strong class="mt-0.5 block text-sm font-medium text-gray-800" x-text="pageHeroEffectiveSourceLabel()"></strong>
                        <span x-show="pageHeroInheritancePathLabel()" class="mt-1 block break-words text-xs leading-5 text-gray-500" x-text="pageHeroInheritancePathLabel()"></span>
                    </span>
                    <button x-show="pageHero.style_source !== 'self'" type="button" @click="copyPageHeroToSelf()"
                            data-testid="blox-page-hero-copy-self" :title="pageHeroText.copyToSelfHint"
                            class="inline-flex h-9 items-center gap-1.5 border border-gray-300 bg-white px-3 text-xs font-medium text-gray-700 hover:border-blue-300 hover:text-blue-700">
                        <i class="ti ti-copy"></i><span x-text="pageHeroText.copyToSelf"></span>
                    </button>
                    <button x-show="pageHero.style_source === 'self'" type="button" @click="restorePageHeroInheritance()"
                            data-testid="blox-page-hero-restore-inheritance" :title="pageHeroText.restoreInheritanceHint"
                            class="inline-flex h-9 items-center gap-1.5 border border-gray-300 bg-white px-3 text-xs font-medium text-gray-700 hover:border-amber-300 hover:text-amber-700">
                        <i class="ti ti-arrow-back-up"></i><span x-text="pageHeroText.restoreInheritance"></span>
                    </button>
                </div>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-xs font-medium text-gray-600" x-text="pageHeroText.previewDevice"></span>
                    <span class="inline-flex border border-gray-200 bg-gray-50 p-1" role="group" :aria-label="pageHeroText.previewDevice">
                        <button type="button" @click="pageHeroPreviewDevice = 'desktop'" :aria-pressed="pageHeroPreviewDevice === 'desktop' ? 'true' : 'false'"
                                data-testid="blox-page-hero-preview-desktop" class="inline-flex h-7 w-9 items-center justify-center"
                                :class="pageHeroPreviewDevice === 'desktop' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-400 hover:text-gray-700'" :title="pageHeroText.previewDesktop"><i class="ti ti-device-desktop"></i></button>
                        <button type="button" @click="pageHeroPreviewDevice = 'mobile'" :aria-pressed="pageHeroPreviewDevice === 'mobile' ? 'true' : 'false'"
                                data-testid="blox-page-hero-preview-mobile" class="inline-flex h-7 w-9 items-center justify-center"
                                :class="pageHeroPreviewDevice === 'mobile' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-400 hover:text-gray-700'" :title="pageHeroText.previewMobile"><i class="ti ti-device-mobile"></i></button>
                    </span>
                </div>
                <div class="flex justify-center overflow-hidden border border-gray-200 bg-gray-100 p-2" data-testid="blox-page-hero-style-preview">
                    <div class="w-full overflow-hidden bg-gray-900 transition-[max-width] duration-200" :class="pageHeroPreviewDevice === 'mobile' ? 'max-w-[390px]' : 'max-w-full'">
                    <div class="relative bg-cover px-5 transition-[height] duration-200"
                         :class="[
                            pageHeroPreviewHeight() === 'large' ? 'h-36' : (pageHeroPreviewHeight() === 'compact' ? 'h-20' : 'h-28'),
                            !pageHeroPreviewBackground() && !pageHeroPreviewOptions().background_color ? 'bg-gradient-to-r from-gray-900 via-gray-800 to-gray-900' : ''
                         ]"
                         :style="pageHeroPreviewStyle()">
                        <div x-show="pageHeroPreviewBackground() && Number(pageHeroPreviewOptions().overlay_opacity || 0) > 0"
                             class="absolute inset-0 bg-black"
                             :style="'opacity:' + (Number(pageHeroPreviewOptions().overlay_opacity || 0) / 100)"></div>
                        <div class="relative flex h-full min-w-0 flex-col justify-center"
                             :class="pageHeroPreviewOptions().alignment === 'center' ? 'items-center text-center' : 'items-start text-left'">
                            <span class="max-w-full truncate text-[10px]"
                                  :class="pageHeroPreviewTone() === 'light' ? 'text-white/65' : 'text-gray-500'"><?= e(__('breadcrumb_home')) ?> / <span x-text="pageHero.name"></span></span>
                            <strong class="mt-1 max-w-full truncate text-base"
                                    :class="pageHeroPreviewTone() === 'light' ? 'text-white' : 'text-gray-900'"
                                    x-text="pageHero.name"></strong>
                            <span x-show="pageHero.description" class="mt-1 max-w-[80%] truncate text-[11px]"
                                  :class="pageHeroPreviewTone() === 'light' ? 'text-white/75' : 'text-gray-600'"
                                  x-text="pageHero.description"></span>
                        </div>
                    </div>
                    </div>
                </div>
                <div>
                    <div class="mb-2 flex items-center justify-between gap-3">
                        <label class="text-sm font-medium text-gray-800" for="blox-page-hero-bg" x-text="pageHeroText.background"></label>
                        <span class="text-xs text-gray-500">
                            <span x-text="pageHeroText.source"></span>: <strong class="font-medium text-gray-700" x-text="pageHeroSourceLabel()"></strong>
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <template x-if="pageHeroPreviewBackground()">
                            <img :src="pageHeroPreviewBackground()" class="h-11 w-16 shrink-0 border border-gray-200 object-cover" alt="">
                        </template>
                        <input id="blox-page-hero-bg" type="text" x-model="pageHero.hero_bg"
                               placeholder="/uploads/images/page-hero.jpg"
                               :disabled="pageHero.style_source !== 'self'"
                               class="min-w-0 flex-1 border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:bg-gray-100 disabled:text-gray-400">
                        <button type="button" @click="pickPageHeroBackground()" :disabled="pageHero.style_source !== 'self'"
                                class="h-10 shrink-0 border border-gray-300 px-3 text-sm text-gray-700 hover:border-blue-400 hover:text-blue-700 disabled:cursor-not-allowed disabled:opacity-40 inline-flex items-center gap-1.5">
                            <i class="ti ti-photo"></i><?= e(__('admin_media')) ?>
                        </button>
                        <button type="button" @click="pageHero.hero_bg = ''" :disabled="pageHero.style_source !== 'self' || !pageHero.hero_bg"
                                class="inline-flex h-10 w-10 shrink-0 items-center justify-center text-gray-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-30"
                                :title="designText.clear" :aria-label="designText.clear">
                            <i class="ti ti-eraser"></i>
                        </button>
                    </div>
                    <p class="mt-2 text-xs leading-5 text-gray-500" x-show="pageHero.style_source === 'self'" x-text="pageHeroText.backgroundHint"></p>
                </div>
                <section class="space-y-4 border-t border-gray-100 pt-4" :class="pageHero.style_source === 'self' ? '' : 'opacity-60'">
                    <div>
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <span class="text-sm font-medium text-gray-800" x-text="pageHeroText.presets"></span>
                            <span x-show="pageHero.style_source !== 'self'" class="text-xs text-gray-500" x-text="pageHeroText.inheritedReadonly"></span>
                        </div>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <button type="button" @click="applyPageHeroPreset('standard')" :disabled="pageHero.style_source !== 'self'"
                                    data-testid="blox-page-hero-preset-standard"
                                    class="h-9 border border-gray-200 px-2 text-xs text-gray-700 hover:border-gray-400 disabled:cursor-not-allowed" x-text="pageHeroText.presetStandard"></button>
                            <button type="button" @click="applyPageHeroPreset('compact')" :disabled="pageHero.style_source !== 'self'"
                                    data-testid="blox-page-hero-preset-compact"
                                    class="h-9 border border-gray-200 px-2 text-xs text-gray-700 hover:border-gray-400 disabled:cursor-not-allowed" x-text="pageHeroText.presetCompact"></button>
                            <button type="button" @click="applyPageHeroPreset('statement')" :disabled="pageHero.style_source !== 'self'"
                                    data-testid="blox-page-hero-preset-statement"
                                    class="h-9 border border-gray-200 px-2 text-xs text-gray-700 hover:border-gray-400 disabled:cursor-not-allowed" x-text="pageHeroText.presetStatement"></button>
                            <button type="button" @click="applyPageHeroPreset('minimal')" :disabled="pageHero.style_source !== 'self'"
                                    data-testid="blox-page-hero-preset-minimal"
                                    class="h-9 border border-gray-200 px-2 text-xs text-gray-700 hover:border-gray-400 disabled:cursor-not-allowed" x-text="pageHeroText.presetMinimal"></button>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label class="mb-1.5 block text-xs font-medium text-gray-600" x-text="pageHeroText.backgroundColor"></label>
                            <button type="button"
                                    @click="openEditorColorPicker($event, 'page-hero-bg', pageHeroText.backgroundColor, pageHero.style_options.background_color, '#111827', true, value => pageHero.style_options.background_color = value)"
                                    :disabled="pageHero.style_source !== 'self'"
                                    data-testid="blox-page-hero-color-picker"
                                    class="flex h-10 w-full items-center gap-2 border border-gray-200 bg-white px-2 text-left hover:border-blue-300 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:cursor-not-allowed">
                                <span class="h-7 w-9 shrink-0 border border-black/10" :style="'background:' + colorFieldPreview(pageHero.style_options.background_color, '#111827')"></span>
                                <span class="min-w-0 flex-1 truncate text-sm text-gray-700" x-text="colorFieldLabel(pageHero.style_options.background_color, designText.clear)"></span>
                                <i class="ti ti-chevron-down text-sm text-gray-400"></i>
                            </button>
                        </div>
                        <div>
                            <div class="mb-1.5 flex items-center justify-between gap-2 text-xs font-medium text-gray-600">
                                <label for="blox-page-hero-overlay" x-text="pageHeroText.overlay"></label>
                                <span class="tabular-nums" x-text="String(pageHero.style_options.overlay_opacity) + '%' "></span>
                            </div>
                            <input id="blox-page-hero-overlay" type="range" min="0" max="90" step="5"
                                   x-model.number="pageHero.style_options.overlay_opacity" :disabled="pageHero.style_source !== 'self'"
                                   class="h-10 w-full accent-gray-900 disabled:cursor-not-allowed">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <fieldset>
                            <legend class="mb-1.5 text-xs font-medium text-gray-600" x-text="pageHeroText.height"></legend>
                            <div class="grid grid-cols-3 border border-gray-200">
                                <template x-for="item in [{v:'compact',l:pageHeroText.heightCompact},{v:'standard',l:pageHeroText.heightStandard},{v:'large',l:pageHeroText.heightLarge}]" :key="item.v">
                                    <label class="cursor-pointer px-1 py-2 text-center text-xs" :class="pageHero.style_options.height === item.v ? 'bg-gray-900 text-white' : 'text-gray-600'">
                                        <input type="radio" class="sr-only" x-model="pageHero.style_options.height" :value="item.v" :disabled="pageHero.style_source !== 'self'"><span x-text="item.l"></span>
                                    </label>
                                </template>
                            </div>
                        </fieldset>
                        <fieldset>
                            <legend class="mb-1.5 text-xs font-medium text-gray-600" x-text="pageHeroText.alignment"></legend>
                            <div class="grid grid-cols-2 border border-gray-200">
                                <template x-for="item in [{v:'left',l:pageHeroText.alignLeft},{v:'center',l:pageHeroText.alignCenter}]" :key="item.v">
                                    <label class="cursor-pointer px-1 py-2 text-center text-xs" :class="pageHero.style_options.alignment === item.v ? 'bg-gray-900 text-white' : 'text-gray-600'">
                                        <input type="radio" class="sr-only" x-model="pageHero.style_options.alignment" :value="item.v" :disabled="pageHero.style_source !== 'self'"><span x-text="item.l"></span>
                                    </label>
                                </template>
                            </div>
                        </fieldset>
                        <fieldset>
                            <legend class="mb-1.5 text-xs font-medium text-gray-600" x-text="pageHeroText.textTone"></legend>
                            <div class="grid grid-cols-3 border border-gray-200">
                                <template x-for="item in [{v:'auto',l:pageHeroText.toneAuto},{v:'light',l:pageHeroText.toneLight},{v:'dark',l:pageHeroText.toneDark}]" :key="item.v">
                                    <label class="cursor-pointer px-1 py-2 text-center text-xs" :class="pageHero.style_options.text_tone === item.v ? 'bg-gray-900 text-white' : 'text-gray-600'">
                                        <input type="radio" class="sr-only" x-model="pageHero.style_options.text_tone" :value="item.v" :disabled="pageHero.style_source !== 'self'"><span x-text="item.l"></span>
                                    </label>
                                </template>
                            </div>
                        </fieldset>
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <fieldset>
                            <legend class="mb-1.5 text-xs font-medium text-gray-600" x-text="pageHeroText.mobileHeight"></legend>
                            <div class="grid grid-cols-4 border border-gray-200">
                                <template x-for="item in [{v:'inherit',l:pageHeroText.heightInherit},{v:'compact',l:pageHeroText.heightCompact},{v:'standard',l:pageHeroText.heightStandard},{v:'large',l:pageHeroText.heightLarge}]" :key="item.v">
                                    <label class="cursor-pointer px-1 py-2 text-center text-xs" :class="pageHero.style_options.mobile_height === item.v ? 'bg-gray-900 text-white' : 'text-gray-600'">
                                        <input type="radio" class="sr-only" x-model="pageHero.style_options.mobile_height" :value="item.v" :disabled="pageHero.style_source !== 'self'"><span x-text="item.l"></span>
                                    </label>
                                </template>
                            </div>
                        </fieldset>
                        <div x-show="pageHeroPreviewBackground()">
                            <span class="mb-1.5 block text-xs font-medium text-gray-600" x-text="pageHeroText.focus"></span>
                            <div class="grid grid-cols-2 gap-3">
                                <label class="text-[11px] text-gray-500"><span class="flex justify-between"><span x-text="pageHeroText.focusX"></span><span x-text="pageHero.style_options.focal_x + '%'"></span></span><input type="range" min="0" max="100" step="5" x-model.number="pageHero.style_options.focal_x" :disabled="pageHero.style_source !== 'self'" class="h-7 w-full accent-gray-900"></label>
                                <label class="text-[11px] text-gray-500"><span class="flex justify-between"><span x-text="pageHeroText.focusY"></span><span x-text="pageHero.style_options.focal_y + '%'"></span></span><input type="range" min="0" max="100" step="5" x-model.number="pageHero.style_options.focal_y" :disabled="pageHero.style_source !== 'self'" class="h-7 w-full accent-gray-900"></label>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
            <footer class="flex min-h-14 items-center justify-end gap-2 border-t border-gray-100 px-4 py-3">
                <button type="button" @click="closePageHeroSettings()" :disabled="pageHeroSaving"
                        class="h-9 border border-gray-300 px-4 text-sm text-gray-600 hover:bg-gray-50 disabled:opacity-40"><?= e(__('cancel')) ?></button>
                <button type="button" @click="savePageHeroSettings()" :disabled="pageHeroSaving"
                        class="h-9 bg-gray-900 px-4 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-wait disabled:opacity-60 inline-flex items-center gap-2">
                    <i class="ti" :class="pageHeroSaving ? 'ti-loader-2 animate-spin' : 'ti-device-floppy'"></i>
                    <span x-text="pageHeroSaving ? pageHeroText.saving : pageHeroText.save"></span>
                </button>
            </footer>
        </div>
    </div>

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
                <label x-show="templateEntry === 'sections' && templatePurposeOptions().length > 1" class="relative min-w-36">
                    <span class="sr-only" x-text="templateText.purpose"></span>
                    <select x-model="templatePurpose" data-testid="blox-template-purpose"
                            class="w-full h-8 border border-gray-200 rounded bg-white pl-2 pr-7 text-xs text-gray-600">
                        <option value="all" x-text="templateText.purposeAll"></option>
                        <template x-for="purpose in templatePurposeOptions()" :key="purpose">
                            <option :value="purpose" x-text="templatePurposeLabel(purpose)"></option>
                        </template>
                    </select>
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

    <!-- 网页头/网页脚样式：随包预置直接替换当前区域草稿，应用动作可撤销。 -->
    <div x-show="headerPresetOpen" x-cloak x-ref="headerPresetDialog" tabindex="-1"
         data-testid="blox-header-presets"
         @keydown="dialogKeydown($event, $refs.headerPresetDialog, () => closeHeaderPresets())"
         role="dialog" aria-modal="true" aria-labelledby="blox-header-presets-title"
         class="fixed inset-0 z-[130] flex items-center justify-center p-6">
        <div class="absolute inset-0 bg-black/50" @click="closeHeaderPresets()"></div>
        <div class="relative flex max-h-[calc(100vh-4rem)] w-[860px] max-w-[96vw] flex-col rounded-lg bg-white shadow-2xl"
             :inert="headerPresetPreviewOpen" :aria-hidden="headerPresetPreviewOpen ? 'true' : 'false'">
            <div class="flex min-h-14 shrink-0 items-center justify-between border-b border-gray-100 px-5 py-3">
                <span class="min-w-0">
                    <span id="blox-header-presets-title" class="flex items-center gap-2 text-sm font-semibold text-gray-800">
                        <i class="ti text-lg text-blue-500" :class="areaPresetType === 'footer' ? 'ti-layout-bottombar' : 'ti-layout-navbar'"></i>
                        <span x-text="headerPresetText.title"></span>
                    </span>
                    <span class="mt-0.5 block text-xs text-gray-400" x-text="headerPresetText.hint"></span>
                </span>
                <button type="button" @click="closeHeaderPresets()"
                        class="ml-4 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded text-gray-400 hover:bg-gray-100 hover:text-gray-700"
                        :title="headerPresetText.close" :aria-label="headerPresetText.close">
                    <i class="ti ti-x text-base"></i>
                </button>
            </div>
            <div class="min-h-0 flex-1 overflow-y-auto blox-scroll">
                <div class="grid content-start grid-cols-1 gap-3 p-4 sm:grid-cols-2">
                    <template x-for="preset in headerPresets" :key="preset.slug">
                        <article class="flex min-h-64 flex-col overflow-hidden rounded border bg-white transition"
                                 :class="selectedHeaderPresetSlug === preset.slug ? 'border-blue-500 ring-2 ring-blue-100' : 'border-gray-200 hover:border-blue-300 hover:shadow-sm'"
                                 :data-selected="selectedHeaderPresetSlug === preset.slug ? 'true' : 'false'"
                                 :data-current="isCurrentHeaderPreset(preset) ? 'true' : 'false'"
                                 :data-testid="'blox-header-preset-' + preset.slug">
                            <div class="relative flex h-28 shrink-0 items-center justify-center bg-gray-50 px-5 py-4">
                                <span x-show="isCurrentHeaderPreset(preset)"
                                      class="absolute left-2 top-2 inline-flex items-center gap-1 rounded bg-emerald-600 px-2 py-1 text-[10px] font-semibold text-white">
                                    <i class="ti ti-check"></i><span x-text="headerPresetText.currentDraft"></span>
                                </span>
                                <div x-show="areaPresetType === 'header'" class="flex flex-col overflow-hidden border border-gray-200 bg-white shadow-sm"
                                     :class="preset.preview === 'viewport-left' ? 'w-full' : 'w-5/6'" aria-hidden="true">
                                    <span x-show="preset.preview === 'corporate'" class="flex h-4 items-center justify-end gap-1 bg-gray-800 px-2">
                                        <i class="h-1 w-7 rounded bg-gray-500"></i><i class="h-1 w-4 rounded bg-gray-500"></i>
                                    </span>
                                    <span x-show="preset.preview === 'topbar'" class="flex h-4 items-center justify-between bg-gray-200 px-2">
                                        <i class="h-1 w-12 rounded bg-gray-400"></i><i class="h-1 w-5 rounded bg-gray-400"></i>
                                    </span>
                                    <span x-show="preset.preview === 'centered-brand'" class="flex h-16 flex-col items-center justify-center gap-2 px-3">
                                        <span data-header-preset-logo class="inline-flex h-4 min-w-14 shrink-0 items-center justify-center rounded-sm bg-gray-900 px-1.5 text-[7px] font-bold leading-none text-white">[LOGO]</span>
                                        <i class="h-1.5 w-28 rounded bg-gray-300"></i>
                                    </span>
                                    <span x-show="preset.preview === 'search'" class="flex h-12 items-center gap-2 px-3">
                                        <span data-header-preset-logo class="inline-flex h-4 min-w-14 shrink-0 items-center justify-center rounded-sm bg-gray-900 px-1.5 text-[7px] font-bold leading-none text-white">[LOGO]</span>
                                        <i class="h-5 flex-1 rounded border border-gray-300 bg-gray-50"></i>
                                        <i class="h-2 w-5 rounded bg-gray-300"></i>
                                    </span>
                                    <span x-show="preset.preview === 'search'" class="flex h-4 items-center justify-center bg-gray-800 px-3">
                                        <i class="h-1 w-24 rounded bg-gray-500"></i>
                                    </span>
                                    <span x-show="preset.preview !== 'centered-brand' && preset.preview !== 'search'"
                                          class="flex h-12 items-center justify-between px-3">
                                        <span data-header-preset-logo class="inline-flex h-4 min-w-14 shrink-0 items-center justify-center rounded-sm bg-gray-900 px-1.5 text-[7px] font-bold leading-none text-white">[LOGO]</span>
                                        <span class="flex items-center gap-1.5"><i class="h-1.5 w-10 rounded bg-gray-300"></i><i class="h-1.5 w-7 rounded bg-gray-300"></i><i class="h-5 w-8 rounded bg-blue-100"></i></span>
                                    </span>
                                </div>
                                <div x-show="areaPresetType === 'footer'"
                                     class="flex w-5/6 flex-col overflow-hidden border border-gray-300 bg-white shadow-sm" aria-hidden="true">
                                    <span x-show="preset.preview === 'footer-search'" class="flex h-5 items-center gap-2 bg-gray-800 px-3">
                                        <i class="h-1.5 w-9 rounded bg-blue-400"></i><i class="h-2.5 flex-1 rounded bg-gray-600"></i>
                                    </span>
                                    <span class="flex items-start justify-between gap-3 px-3"
                                          :class="preset.preview === 'footer-compact' ? 'h-8 items-center bg-gray-800' : (preset.preview === 'footer-columns-dark' || preset.preview === 'footer-search' ? 'h-12 bg-gray-700 py-2' : 'h-12 bg-gray-50 py-2')">
                                        <span x-show="preset.preview === 'footer-columns'" class="flex flex-col gap-1">
                                            <i class="h-2 w-10 rounded bg-gray-500"></i>
                                            <i class="h-1.5 w-16 rounded bg-gray-300"></i>
                                        </span>
                                        <i x-show="preset.preview !== 'footer-columns'" class="h-2 w-10 rounded" :class="preset.preview === 'footer-columns-dark' || preset.preview === 'footer-compact' || preset.preview === 'footer-search' ? 'bg-blue-400' : 'bg-blue-500'"></i>
                                        <span class="flex flex-1 justify-end gap-2">
                                            <i class="h-1.5 w-8 rounded" :class="preset.preview === 'footer-columns-dark' || preset.preview === 'footer-compact' || preset.preview === 'footer-search' ? 'bg-gray-400' : 'bg-gray-300'"></i>
                                            <i class="h-1.5 w-6 rounded" :class="preset.preview === 'footer-contact' ? 'bg-blue-300' : (preset.preview === 'footer-columns-dark' || preset.preview === 'footer-compact' || preset.preview === 'footer-search' ? 'bg-gray-400' : 'bg-gray-300')"></i>
                                            <i x-show="preset.preview !== 'footer-compact'" class="h-1.5 w-7 rounded" :class="preset.preview === 'footer-columns-dark' || preset.preview === 'footer-search' ? 'bg-gray-400' : 'bg-gray-300'"></i>
                                        </span>
                                    </span>
                                    <span x-show="preset.preview !== 'footer-compact'" class="h-3"
                                          :class="preset.preview === 'footer-columns-dark' || preset.preview === 'footer-search' ? 'bg-gray-900' : 'bg-gray-200'"></span>
                                </div>
                            </div>
                            <div class="flex flex-1 flex-col border-t border-gray-100 p-4">
                                <h3 class="text-sm font-semibold text-gray-800" x-text="preset.name"></h3>
                                <div class="mt-2 flex min-h-6 flex-wrap gap-1.5">
                                    <template x-for="feature in preset.features" :key="preset.slug + '-' + feature">
                                        <span class="rounded bg-gray-100 px-2 py-1 text-[10px] font-medium text-gray-600" x-text="feature"></span>
                                    </template>
                                </div>
                                <p class="mt-2 min-h-10 text-xs leading-5 text-gray-500" x-text="preset.description"></p>
                                <div class="mt-auto grid grid-cols-2 gap-2 pt-3">
                                    <button type="button" @click="previewHeaderPreset(preset)" data-dialog-initial
                                            data-testid="blox-header-preset-preview"
                                            class="inline-flex h-9 items-center justify-center gap-1.5 rounded border border-gray-200 px-3 text-xs font-semibold text-gray-600 hover:border-blue-300 hover:text-blue-600">
                                        <i class="ti ti-eye text-sm"></i><span x-text="headerPresetText.preview"></span>
                                    </button>
                                    <button type="button" @click="applyHeaderPreset(preset)" data-header-preset-apply
                                            data-testid="blox-header-preset-apply" :disabled="isCurrentHeaderPreset(preset)"
                                            class="inline-flex h-9 items-center justify-center gap-1.5 rounded border border-gray-300 bg-gray-50 px-3 text-xs font-semibold text-gray-700 hover:border-blue-300 hover:bg-gray-100 hover:text-blue-700 disabled:cursor-default disabled:border-emerald-300 disabled:bg-white disabled:text-emerald-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-300 focus-visible:ring-offset-2">
                                        <i class="ti text-sm" :class="isCurrentHeaderPreset(preset) ? 'ti-check text-emerald-600' : 'ti-arrow-right text-blue-500'"></i>
                                        <span x-text="isCurrentHeaderPreset(preset) ? headerPresetText.currentApply : headerPresetText.apply"></span>
                                    </button>
                                </div>
                            </div>
                        </article>
                    </template>
                </div>
            </div>
        </div>

        <div x-show="headerPresetPreviewOpen" x-cloak x-ref="headerPresetPreviewDialog" tabindex="-1"
             data-testid="blox-header-preset-preview-dialog"
             @keydown="dialogKeydown($event, $refs.headerPresetPreviewDialog, () => closeHeaderPresetPreview())"
             role="dialog" aria-modal="true" aria-labelledby="blox-header-preset-preview-title"
             class="fixed inset-0 z-[140] flex items-center justify-center p-4 sm:p-6">
            <div class="absolute inset-0 bg-black/60" @click="closeHeaderPresetPreview()"></div>
            <template x-for="preset in [selectedHeaderPreset()]" :key="preset ? 'preview-' + preset.slug : 'empty-preview'">
                <div x-show="preset" data-testid="blox-header-preset-preview-panel"
                     class="relative flex max-h-[calc(100vh-2rem)] w-[1360px] max-w-[96vw] flex-col overflow-hidden rounded-lg bg-white shadow-2xl sm:max-h-[calc(100vh-3rem)]">
                    <div class="flex min-h-14 shrink-0 flex-col items-start justify-between gap-3 border-b border-gray-100 px-5 py-3 sm:flex-row sm:items-center">
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span class="flex min-w-0 items-center gap-2">
                                <button type="button" @click="selectAdjacentHeaderPreset(-1)" data-testid="blox-header-preset-preview-previous"
                                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded text-gray-400 hover:bg-gray-100 hover:text-gray-700"
                                        :title="headerPresetText.previous" :aria-label="headerPresetText.previous">
                                    <i class="ti ti-chevron-left"></i>
                                </button>
                                <span id="blox-header-preset-preview-title" class="block truncate text-sm font-semibold text-gray-800" x-text="preset && preset.name"></span>
                                <button type="button" @click="selectAdjacentHeaderPreset(1)" data-testid="blox-header-preset-preview-next"
                                        class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded text-gray-400 hover:bg-gray-100 hover:text-gray-700"
                                        :title="headerPresetText.next" :aria-label="headerPresetText.next">
                                    <i class="ti ti-chevron-right"></i>
                                </button>
                            </span>
                            <span class="mt-0.5 block max-w-full text-xs text-gray-500" x-text="headerPresetText.previewDataHint"></span>
                        </span>
                        <span class="flex w-full flex-none flex-wrap items-center gap-2 sm:ml-4 sm:w-auto sm:flex-nowrap sm:gap-3">
                            <span x-show="areaPresetType === 'header'" class="inline-flex h-9 items-center rounded border border-gray-200 bg-gray-50 p-1" role="group" :aria-label="headerPresetText.previewTitle">
                                <template x-for="state in ['normal', 'overlay', 'stuck']" :key="'preset-state-' + state">
                                    <button type="button" @click="setHeaderPresetPreviewState(state)"
                                            :data-testid="'blox-header-preset-preview-state-' + state"
                                            :aria-pressed="headerPresetPreviewState === state ? 'true' : 'false'"
                                            :title="state === 'normal' ? headerPresetText.previewNormal : (state === 'overlay' ? headerPresetText.previewOverlay : headerPresetText.previewStuck)"
                                            class="inline-flex h-7 min-w-9 items-center justify-center rounded px-2 text-[11px] font-semibold text-gray-500 transition"
                                            :class="headerPresetPreviewState === state ? 'bg-white text-blue-600 shadow-sm' : 'hover:text-gray-800'">
                                        <span x-text="state === 'normal' ? headerPresetText.previewNormal : (state === 'overlay' ? headerPresetText.previewOverlay : headerPresetText.previewStuck)"></span>
                                    </button>
                                </template>
                            </span>
                            <span class="inline-flex h-9 items-center rounded border border-gray-200 bg-gray-50 p-1" role="group" :aria-label="headerPresetText.previewTitle">
                                <button type="button" @click="setHeaderPresetPreviewDevice('desktop')"
                                        data-testid="blox-header-preset-preview-desktop"
                                        :aria-pressed="headerPresetPreviewDevice === 'desktop' ? 'true' : 'false'"
                                        :title="headerPresetText.previewDesktop" :aria-label="headerPresetText.previewDesktop"
                                        class="inline-flex h-7 min-w-9 items-center justify-center rounded px-2 text-gray-500 transition"
                                        :class="headerPresetPreviewDevice === 'desktop' ? 'bg-white text-blue-600 shadow-sm' : 'hover:text-gray-800'">
                                    <i class="ti ti-device-desktop text-base"></i>
                                </button>
                                <button type="button" @click="setHeaderPresetPreviewDevice('mobile')"
                                        data-testid="blox-header-preset-preview-mobile"
                                        :aria-pressed="headerPresetPreviewDevice === 'mobile' ? 'true' : 'false'"
                                        :title="headerPresetText.previewMobile" :aria-label="headerPresetText.previewMobile"
                                        class="inline-flex h-7 min-w-9 items-center justify-center rounded px-2 text-gray-500 transition"
                                        :class="headerPresetPreviewDevice === 'mobile' ? 'bg-white text-blue-600 shadow-sm' : 'hover:text-gray-800'">
                                    <i class="ti ti-device-mobile text-base"></i>
                                </button>
                            </span>
                            <button type="button" x-show="areaPresetType === 'header' && headerPresetPreviewDevice === 'mobile'"
                                    @click="toggleHeaderPresetPreviewDrawer()"
                                    data-testid="blox-header-preset-preview-drawer"
                                    :aria-pressed="headerPresetPreviewDrawerOpen ? 'true' : 'false'"
                                    :title="headerPresetPreviewDrawerOpen ? headerPresetText.previewDrawerClose : headerPresetText.previewDrawerOpen"
                                    class="inline-flex h-9 w-9 items-center justify-center rounded border border-gray-200 text-gray-500 transition hover:bg-gray-50 hover:text-gray-800"
                                    :class="headerPresetPreviewDrawerOpen ? 'border-blue-200 bg-blue-50 text-blue-600' : ''">
                                <i class="ti" :class="headerPresetPreviewDrawerOpen ? 'ti-layout-sidebar-right-collapse' : 'ti-menu-2'"></i>
                            </button>
                            <button type="button" @click="closeHeaderPresetPreview()" data-dialog-initial data-testid="blox-header-preset-preview-close"
                                    class="ml-auto inline-flex h-8 w-8 shrink-0 items-center justify-center rounded text-gray-400 hover:bg-gray-100 hover:text-gray-700 sm:ml-0"
                                    :title="headerPresetText.close" :aria-label="headerPresetText.close">
                                <i class="ti ti-x text-base"></i>
                            </button>
                        </span>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto bg-gray-100 p-4 blox-scroll">
                        <div class="flex min-h-[320px] items-start justify-center overflow-auto rounded border border-gray-200 bg-gray-200 p-3">
                            <div class="relative max-w-full shrink-0 overflow-hidden bg-white shadow-lg"
                                 data-testid="blox-header-preset-preview-viewport"
                                 :data-device="headerPresetPreviewDevice"
                                 :style="headerPresetPreviewDevice === 'mobile'
                                     ? 'width:min(390px, 100%);height:320px'
                                     : 'width:min(1280px, 100%);height:320px'">
                                <iframe data-testid="blox-header-preset-preview-frame"
                                        :src="headerPresetPreviewUrl(preset)"
                                        @load="headerPresetPreviewLoading = false"
                                        :title="headerPresetText.previewTitle"
                                        tabindex="-1" aria-hidden="true"
                                        class="h-full w-full border-0 bg-white"></iframe>
                                <div x-show="headerPresetPreviewLoading"
                                     class="absolute inset-0 flex items-center justify-center bg-white text-xs font-medium text-gray-500"
                                     role="status">
                                    <i class="ti ti-loader-2 mr-2 animate-spin text-base text-blue-500"></i>
                                    <span x-text="headerPresetText.previewLoading"></span>
                                </div>
                            </div>
                        </div>
                        <div class="mt-5 flex items-start justify-between gap-3">
                            <p class="text-sm leading-6 text-gray-600" x-text="preset && preset.description"></p>
                            <span x-show="preset && isCurrentHeaderPreset(preset)" class="shrink-0 rounded bg-emerald-100 px-2 py-1 text-[10px] font-semibold text-emerald-700" x-text="headerPresetText.currentDraft"></span>
                        </div>
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            <template x-for="feature in (preset ? preset.features : [])" :key="'preview-' + feature">
                                <span class="rounded bg-gray-100 px-2 py-1 text-[10px] font-medium text-gray-600" x-text="feature"></span>
                            </template>
                        </div>
                        <p class="mt-3 text-[11px] text-gray-400" x-text="headerPresetText.sectionCount.replace(':count', preset ? preset.sections.length : 0)"></p>
                        <div class="mt-4 border-t border-gray-200 pt-4" data-testid="blox-header-preset-difference">
                            <p class="text-xs font-semibold text-gray-700" x-text="headerPresetText.differenceTitle"></p>
                            <template x-if="preset && headerPresetComparison(preset).same">
                                <p class="mt-2 text-xs text-emerald-700" x-text="headerPresetText.differenceSame"></p>
                            </template>
                            <div x-show="preset && !headerPresetComparison(preset).same" class="mt-2 grid gap-2 sm:grid-cols-2">
                                <p class="text-xs leading-5 text-gray-600">
                                    <span class="font-semibold text-emerald-700" x-text="headerPresetText.differenceAdded"></span>
                                    <span x-text="preset ? headerPresetComparison(preset).added.join(headerPresetText.differenceSeparator) || '—' : '—'"></span>
                                </p>
                                <p class="text-xs leading-5 text-gray-600">
                                    <span class="font-semibold text-amber-700" x-text="headerPresetText.differenceRemoved"></span>
                                    <span x-text="preset ? headerPresetComparison(preset).removed.join(headerPresetText.differenceSeparator) || '—' : '—'"></span>
                                </p>
                            </div>
                        </div>
                        <div x-show="preset && headerPresetWarnings(preset).length" class="mt-4 border border-amber-200 bg-amber-50 px-3 py-2"
                             data-testid="blox-header-preset-warnings" role="status">
                            <template x-for="warning in (preset ? headerPresetWarnings(preset) : [])" :key="warning">
                                <p class="text-xs leading-5 text-amber-800"><i class="ti ti-alert-triangle mr-1" aria-hidden="true"></i><span x-text="warning"></span></p>
                            </template>
                        </div>
                        <div x-show="preset && headerPresetFocusTypes(preset).length" class="mt-4 flex flex-wrap items-center gap-2"
                             data-testid="blox-header-preset-edit-targets">
                            <template x-for="type in (preset ? headerPresetFocusTypes(preset) : [])" :key="'edit-' + type">
                                <button type="button" @click="applyHeaderPreset(preset, type)"
                                        class="inline-flex h-8 items-center gap-1.5 rounded border border-gray-200 bg-white px-3 text-xs font-medium text-gray-600 hover:border-blue-300 hover:text-blue-700">
                                    <i class="ti" :class="'ti-' + elIcon(type)" aria-hidden="true"></i>
                                    <span x-text="headerPresetText.applyAndEdit.replace(':name', headerElementLabels[type] || elSchema(type).label || type)"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center justify-between gap-2 border-t border-gray-100 px-5 py-3">
                        <button type="button" @click="saveHeaderAsLocalStyle()" data-testid="blox-header-save-local-style"
                                class="inline-flex h-9 items-center justify-center gap-1.5 rounded px-3 text-xs font-semibold text-gray-600 hover:bg-gray-100 hover:text-gray-800">
                            <i class="ti ti-copy" aria-hidden="true"></i>
                            <span x-text="headerPresetText.saveLocal"></span>
                        </button>
                        <span class="flex items-center gap-2">
                        <button type="button" @click="closeHeaderPresetPreview()"
                                class="inline-flex h-9 items-center justify-center rounded border border-gray-200 px-4 text-xs font-semibold text-gray-600 hover:bg-gray-50 hover:text-gray-800"
                                x-text="headerPresetText.close"></button>
                        <button type="button" @click="applyHeaderPreset(preset)" :disabled="!preset || isCurrentHeaderPreset(preset)"
                                data-testid="blox-header-preset-preview-apply"
                                class="inline-flex h-9 items-center justify-center gap-1.5 rounded bg-blue-600 px-4 text-xs font-semibold text-white hover:bg-blue-500 disabled:cursor-default disabled:bg-emerald-600">
                            <i class="ti" :class="preset && isCurrentHeaderPreset(preset) ? 'ti-check' : 'ti-arrow-right'"></i>
                            <span x-text="preset && isCurrentHeaderPreset(preset) ? headerPresetText.currentApply : headerPresetText.apply"></span>
                        </button>
                        </span>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <!-- Blox 模板库：目录与正文按需加载，避免大模板拖慢编辑器首屏。 -->
    <div x-show="templateOpen" x-cloak x-ref="templateDialog" tabindex="-1"
         data-testid="blox-template-dialog"
         @keydown="templateDialogKeydown($event)"
         role="dialog" :aria-modal="templateSectionsDocked() ? 'false' : 'true'" aria-labelledby="blox-template-dialog-title"
         class="fixed inset-0 z-[130] flex"
         :class="templateSectionsDocked() ? 'items-stretch justify-start pt-14 pointer-events-none' : 'items-center justify-center p-6'">
        <div x-show="!templateSectionsDocked()" class="absolute inset-0 bg-black/50" @click="closeTemplates()"></div>
        <div id="blox-template-panel" data-testid="blox-template-panel"
             class="blox-template-panel relative bg-white shadow-2xl max-w-[94vw] flex flex-col pointer-events-auto"
             :class="templateSectionsDocked()
                 ? 'max-w-[calc(100vw-320px)] h-[calc(100vh-3.5rem)] rounded-none border-r border-gray-200'
                : (templateEntry === 'sections' ? 'w-[1180px] rounded-xl' : 'w-[900px] rounded-xl')"
             :style="templatePanelStyle()">
            <div x-show="templateSectionsDocked()" data-testid="blox-template-panel-resizer"
                 class="blox-panel-resizer blox-template-panel-resizer"
                 :class="templatePanelResizing ? 'is-active' : ''"
                 role="separator" aria-orientation="vertical" tabindex="0"
                 :aria-valuemin="templatePanelMin" :aria-valuemax="templatePanelMaximum()"
                 :aria-valuenow="templatePanelCurrentWidth()" :aria-valuetext="templatePanelCurrentWidth() + 'px'"
                 aria-controls="blox-template-panel"
                 title="<?= e(__('blox_resize_template_panel_hint')) ?>"
                 aria-label="<?= e(__('blox_resize_template_panel')) ?>"
                 @pointerdown="startTemplatePanelResize($event)"
                 @pointermove="resizeTemplatePanel($event)"
                 @pointerup="finishTemplatePanelResize($event)"
                 @pointercancel="finishTemplatePanelResize($event)"
                 @dblclick="resetTemplatePanelWidth()"
                 @keydown.left.prevent="resizeTemplatePanelBy(-16)"
                 @keydown.right.prevent="resizeTemplatePanelBy(16)"
                 @keydown.home.prevent="resetTemplatePanelWidth()">
                <span aria-hidden="true"></span>
            </div>
            <div class="h-12 px-4 flex items-center justify-between border-b border-gray-100 shrink-0">
                <span id="blox-template-dialog-title" class="text-sm font-semibold text-gray-700 inline-flex items-center gap-1.5">
                    <i class="ti text-base text-blue-500" :class="templateEntry === 'sections' ? 'ti-layout-grid-add' : (templateEntry === 'pages' ? 'ti-files' : 'ti-template')"></i>
                    <span x-text="templateEntry === 'sections' ? templateText.prebuiltTitle : (templateEntry === 'pages' ? templateText.pageLibrary : templateText.title)"></span>
                </span>
                <button type="button" @click="closeTemplates()" data-testid="blox-template-close" class="text-gray-400 hover:text-gray-600 p-1"
                        :title="templateText.close" :aria-label="templateText.close">
                    <i class="ti ti-x text-base"></i>
                </button>
            </div>
            <div class="h-11 px-3 border-b border-gray-100 shrink-0 flex items-end gap-1" role="tablist" :aria-label="templateText.title">
                <button type="button" role="tab" data-testid="blox-template-tab-local"
                        @click="templateScope = 'local'; templateCategory = 'all'; templateQuickFilter = templateQuickCount('recommended') > 0 ? 'recommended' : 'all'" :aria-selected="templateScope === 'local'"
                        class="h-10 px-4 border-b-2 text-xs font-semibold inline-flex items-center gap-2 transition"
                        :class="templateScope === 'local' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-400 hover:text-gray-700'">
                    <i class="ti ti-folders text-base"></i>
                    <span x-text="templateText.localLibrary"></span>
                    <span class="min-w-5 h-5 px-1 rounded bg-gray-100 text-[10px] text-gray-500 inline-flex items-center justify-center"
                          x-text="templateScopeCount('local')"></span>
                </button>
                <button type="button" role="tab" data-testid="blox-template-tab-remote"
                        @click="templateScope = 'remote'; templateCategory = 'all'; templateQuickFilter = templateQuickCount('recommended') > 0 ? 'recommended' : 'all'" :aria-selected="templateScope === 'remote'"
                        class="h-10 px-4 border-b-2 text-xs font-semibold inline-flex items-center gap-2 transition"
                        :class="templateScope === 'remote' ? 'border-blue-500 text-blue-600' : 'border-transparent text-gray-400 hover:text-gray-700'">
                    <i class="ti ti-cloud-download text-base"></i>
                    <span x-text="templateText.remoteLibrary"></span>
                    <span class="min-w-5 h-5 px-1 rounded bg-gray-100 text-[10px] text-gray-500 inline-flex items-center justify-center"
                          x-text="templateScopeCount('remote')"></span>
                </button>
            </div>
            <div class="p-3 border-b border-gray-100 shrink-0 flex flex-wrap items-center gap-2">
                <div class="relative flex-1 min-w-48" :class="templateSectionsDocked() ? 'basis-full' : ''">
                    <i class="ti ti-search text-sm text-gray-300 absolute left-2.5 top-1/2 -translate-y-1/2"></i>
                    <input type="text" x-model="templateQuery" :placeholder="templateText.search"
                           data-dialog-initial data-testid="blox-template-search"
                           class="w-full border border-gray-200 rounded pl-8 pr-3 py-1.5 text-sm">
                </div>
                <div x-show="templateEntry === 'all'" class="inline-flex rounded border border-gray-200 p-0.5">
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
                <div x-show="templateEntry === 'sections'" role="group" :aria-label="templateText.prebuiltTitle"
                     data-testid="blox-template-quick-filters"
                     class="inline-flex h-8 rounded border border-gray-200 bg-white p-0.5">
                    <button type="button" @click="templateQuickFilter = 'recommended'"
                            data-testid="blox-template-quick-recommended" :aria-pressed="templateQuickFilter === 'recommended'"
                            class="px-2 rounded text-[11px] inline-flex items-center gap-1 transition"
                            :class="templateQuickFilter === 'recommended' ? 'bg-emerald-600 text-white' : 'text-gray-500 hover:text-emerald-700'">
                        <i class="ti ti-sparkles text-xs"></i><span x-text="templateText.recommended"></span>
                        <span class="opacity-70" x-text="templateQuickCount('recommended')"></span>
                    </button>
                    <button type="button" @click="templateQuickFilter = 'all'"
                            data-testid="blox-template-quick-all" :aria-pressed="templateQuickFilter === 'all'"
                            class="px-2 rounded text-[11px] inline-flex items-center gap-1 transition"
                            :class="templateQuickFilter === 'all' ? 'bg-gray-900 text-white' : 'text-gray-500 hover:text-gray-800'">
                        <span x-text="templateText.quickAll"></span>
                        <span class="opacity-70" x-text="templateQuickCount('all')"></span>
                    </button>
                    <button type="button" @click="templateQuickFilter = 'favorites'"
                            data-testid="blox-template-quick-favorites" :aria-pressed="templateQuickFilter === 'favorites'"
                            :title="templateText.favorites" :aria-label="templateText.favorites"
                            class="px-2 rounded text-[11px] inline-flex items-center gap-1 transition"
                            :class="templateQuickFilter === 'favorites' ? 'bg-amber-500 text-white' : 'text-gray-500 hover:text-amber-600'">
                        <i class="ti ti-star text-xs"></i><span x-text="templateQuickCount('favorites')"></span>
                    </button>
                    <button type="button" @click="templateQuickFilter = 'recent'"
                            data-testid="blox-template-quick-recent" :aria-pressed="templateQuickFilter === 'recent'"
                            :title="templateText.recent" :aria-label="templateText.recent"
                            class="px-2 rounded text-[11px] inline-flex items-center gap-1 transition"
                            :class="templateQuickFilter === 'recent' ? 'bg-blue-600 text-white' : 'text-gray-500 hover:text-blue-600'">
                        <i class="ti ti-history text-xs"></i><span x-text="templateQuickCount('recent')"></span>
                    </button>
                </div>
                <div x-show="templateEntry === 'sections'" role="group" :aria-label="templateText.density"
                     data-testid="blox-template-density"
                     class="inline-flex h-8 rounded border border-gray-200 bg-white p-0.5">
                    <button type="button" @click="setTemplateDensity('standard')"
                            data-testid="blox-template-density-standard" :aria-pressed="templateDensity === 'standard'"
                            :title="templateText.densityStandard" :aria-label="templateText.densityStandard"
                            class="w-7 rounded inline-flex items-center justify-center transition"
                            :class="templateDensity === 'standard' ? 'bg-gray-900 text-white' : 'text-gray-500 hover:text-gray-800'">
                        <i class="ti ti-layout-grid text-sm"></i>
                    </button>
                    <button type="button" @click="setTemplateDensity('compact')"
                            data-testid="blox-template-density-compact" :aria-pressed="templateDensity === 'compact'"
                            :title="templateText.densityCompact" :aria-label="templateText.densityCompact"
                            class="w-7 rounded inline-flex items-center justify-center transition"
                            :class="templateDensity === 'compact' ? 'bg-gray-900 text-white' : 'text-gray-500 hover:text-gray-800'">
                        <i class="ti ti-list text-sm"></i>
                    </button>
                </div>
                <button type="button" @click="loadTemplates(true)" :disabled="templateLoading"
                        class="w-8 h-8 inline-flex items-center justify-center rounded border border-gray-200 text-gray-500 hover:text-blue-600 disabled:opacity-40"
                        :title="templateText.reload" :aria-label="templateText.reload">
                    <i class="ti" :class="templateLoading ? 'ti-loader-2 animate-spin' : 'ti-refresh'"></i>
                </button>
            </div>
            <div x-show="templateEntry === 'sections'"
                 class="px-4 py-2 border-b border-blue-100 bg-blue-50 text-xs text-blue-700 flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                <span class="inline-flex min-w-0 items-center gap-2">
                    <i class="ti ti-map-pin-check shrink-0"></i>
                    <span class="truncate" x-text="templateText.insertTarget.replace(':target', insertHint())"></span>
                </span>
                <span x-show="templateQuickFilter === 'recommended'" class="inline-flex items-center gap-1.5 text-emerald-700">
                    <i class="ti ti-sparkles shrink-0"></i>
                    <span x-text="templateText.recommendedFor.replace(':page', templateText.pageIntent)"></span>
                </span>
            </div>
            <div x-ref="templateScroll" @scroll.passive="rememberTemplateSectionScroll($event.target.scrollTop)"
                 class="min-h-[320px] overflow-y-auto blox-scroll p-4">
                <section x-show="templateEntry === 'pages' && pageMode" data-testid="blox-page-library-actions" class="mb-4 border-b border-gray-100 pb-4">
                    <div class="mb-3">
                        <h3 class="text-sm font-semibold text-gray-800" x-text="templateText.pageLibrary"></h3>
                        <p class="mt-0.5 text-xs text-gray-500" x-text="templateText.pageLibraryHint"></p>
                    </div>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        <button type="button" @click="startBlankPage()" data-testid="blox-page-library-blank"
                                class="flex min-h-20 items-center gap-3 border border-gray-200 bg-white p-3 text-left hover:border-blue-300 hover:bg-blue-50/40">
                            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center border border-gray-200 bg-gray-50 text-gray-600"><i class="ti ti-file-plus text-lg"></i></span>
                            <span class="min-w-0"><strong class="block text-sm text-gray-800" x-text="templateText.blankPage"></strong><span class="mt-0.5 block text-xs text-gray-500" x-text="templateText.blankPageHint"></span></span>
                        </button>
                        <button type="button" @click="restorePublishedPage()" :disabled="!pagePublished"
                                data-testid="blox-page-library-restore" :title="pagePublished ? templateText.restorePublishedHint : templateText.noPublishedPage"
                                class="flex min-h-20 items-center gap-3 border border-gray-200 bg-white p-3 text-left hover:border-emerald-300 hover:bg-emerald-50/40 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:opacity-50">
                            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center border border-gray-200 bg-gray-50 text-gray-600"><i class="ti ti-history text-lg"></i></span>
                            <span class="min-w-0"><strong class="block text-sm text-gray-800" x-text="templateText.restorePublished"></strong><span class="mt-0.5 block text-xs text-gray-500" x-text="pagePublished ? templateText.restorePublishedHint : templateText.noPublishedPage"></span></span>
                        </button>
                    </div>
                    <div class="mt-4 flex items-center gap-2 text-xs font-semibold text-gray-500"><i class="ti ti-template"></i><span x-text="templateText.fullPageTemplates"></span></div>
                </section>
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
                     data-testid="blox-template-empty" :data-empty-reason="templateEmptyReason()"
                     class="py-16 text-center text-sm text-gray-500">
                    <i class="ti text-2xl block mb-2" :class="templateEmptyIcon()"></i>
                    <span class="block" x-text="templateEmptyMessage()"></span>
                    <button type="button" x-show="templateCanClearFilters()" @click="clearTemplateSectionFilters()"
                            data-testid="blox-template-clear-filters"
                            class="mt-3 h-8 rounded border border-gray-200 bg-white px-3 text-xs font-medium text-gray-600 inline-flex items-center justify-center gap-1.5 hover:border-blue-300 hover:text-blue-700">
                        <i class="ti ti-filter-off text-sm"></i><span x-text="templateText.clearFilters"></span>
                    </button>
                </div>
                <div x-show="!templateLoading && !templateError && filteredTemplates().length > 0"
                     class="grid"
                     :class="templateEntry === 'sections' ? ['blox-template-section-grid', (templateCompactSections() ? 'gap-2' : 'gap-3')] : 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3'">
                    <template x-for="item in filteredTemplates()" :key="item.key">
                        <article data-testid="blox-template-item" :data-template-key="item.key"
                                 :draggable="templateSectionDraggable(item)"
                                 @dragstart="startTemplateDrag(item, $event)" @dragend="finishPaletteDrag()"
                                 :title="templateSectionDraggable(item) ? templateText.dragHint.replace(':name', item.name) : (item.description || item.name)"
                                 class="group overflow-hidden border border-gray-200 rounded-lg text-left flex transition hover:border-blue-300 hover:shadow-sm focus-within:border-blue-400"
                                 :class="[(templateCompactSections() ? 'h-24 min-h-0 flex-row' : ((templateEntry === 'sections' ? 'min-h-64' : 'min-h-52') + ' flex-col')), (templateSectionDraggable(item) ? 'cursor-grab active:cursor-grabbing' : ''), (templateDragItem && templateDragItem.key === item.key ? 'border-blue-500 ring-2 ring-blue-100 shadow-sm' : '')]">
                            <span class="relative block overflow-hidden"
                                :class="templateCompactSections() ? 'w-32 shrink-0 border-r border-gray-100 aspect-auto bg-white' : ((templateEntry === 'sections' ? 'aspect-[16/8] bg-white' : 'aspect-[16/7] bg-gray-100') + ' border-b border-gray-100')">
                                <img x-show="item.thumbnail" :src="item.thumbnail" alt="" loading="lazy"
                                     class="h-full w-full" :class="templateEntry === 'sections' ? 'object-contain' : 'object-cover'">
                                <span x-show="!item.thumbnail" class="absolute inset-0 flex items-center justify-center text-gray-400">
                                    <i class="ti text-3xl" :class="item.type === 'page' ? 'ti-files' : 'ti-layout'"></i>
                                </span>
                                <button type="button" x-show="templateEntry === 'sections' && item.type === 'section'"
                                        @pointerdown.stop @click.stop="toggleTemplateFavorite(item.key)" draggable="false"
                                        :data-testid="'blox-template-favorite-' + item.key"
                                        :aria-pressed="isTemplateFavorite(item.key)"
                                        :title="(isTemplateFavorite(item.key) ? templateText.removeFavorite : templateText.addFavorite).replace(':label', item.name)"
                                        :aria-label="(isTemplateFavorite(item.key) ? templateText.removeFavorite : templateText.addFavorite).replace(':label', item.name)"
                                        class="absolute inline-flex items-center justify-center rounded shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 transition"
                                        :class="[(isTemplateFavorite(item.key) ? 'bg-amber-50 text-amber-600' : 'bg-white/95 text-gray-500 hover:text-amber-500'), (templateCompactSections() ? 'right-1.5 top-1.5 w-7 h-7' : 'right-2 top-2 w-8 h-8')]">
                                    <i class="ti ti-star text-base"></i>
                                </button>
                            </span>
                            <template x-if="templateEntry === 'sections'">
                                <span data-testid="blox-template-section-bar"
                                      class="flex flex-1 min-w-0 items-center gap-2 transition group-hover:bg-blue-50/50"
                                      :class="templateCompactSections() ? 'p-2.5' : 'px-3 py-2.5 bg-gray-50'">
                                    <span class="min-w-0 flex-1">
                                        <span class="flex min-w-0 items-center gap-1.5">
                                            <span class="truncate text-sm font-semibold text-gray-800" x-text="item.name"></span>
                                            <span x-show="templateItemRecommended(item)"
                                                  class="shrink-0 rounded border border-emerald-200 bg-emerald-50 px-1.5 py-0.5 text-[10px] text-emerald-700"
                                                  x-text="templateText.recommended"></span>
                                             <span x-show="item.paid" class="shrink-0 text-[10px] text-amber-700 border border-amber-200 bg-amber-50 rounded px-1.5 py-0.5"
                                                   x-text="templateText.premium"></span>
                                            <span x-show="item.metadata && item.metadata.purpose && item.metadata.purpose !== 'general'"
                                                  class="blox-template-purpose-badge shrink-0 rounded border border-gray-200 bg-white px-1.5 py-0.5 text-[10px] text-gray-500"
                                                  x-text="templatePurposeLabel(item.metadata.purpose)"></span>
                                        </span>
                                        <span class="mt-0.5 flex min-w-0 items-center gap-1 text-[11px]"
                                              :class="item.locked ? 'text-amber-700' : 'text-gray-400'">
                                            <i class="ti shrink-0" :class="item.locked ? 'ti-lock' : (item.source === 'remote' ? 'ti-cloud-download' : (item.source === 'plugin' ? 'ti-plug' : 'ti-user'))"></i>
                                            <span class="blox-template-provider truncate" x-text="item.locked ? templateLockLabel(item) : templateProviderLabel(item)"></span>
                                        </span>
                                    </span>
                                    <template x-if="canEditLocalTemplate(item)">
                                        <a :href="localTemplateEditUrl(item)" target="_blank" rel="noopener"
                                           data-testid="blox-template-edit" :title="templateText.edit" :aria-label="templateText.edit + ': ' + item.name"
                                           class="h-8 w-8 shrink-0 rounded border border-gray-200 bg-white text-gray-500 inline-flex items-center justify-center hover:border-blue-300 hover:text-blue-600">
                                            <i class="ti ti-pencil text-sm"></i>
                                        </a>
                                    </template>
                                    <button type="button" @click="insertTemplate(item)"
                                            :disabled="templateInserting !== '' || !!item.locked"
                                            data-testid="blox-template-insert"
                                            :title="item.locked ? templateLockLabel(item) : (item.source === 'remote' ? templateText.downloadImport : templateText.insertSection)"
                                            class="h-8 shrink-0 rounded border border-blue-200 bg-white px-3 text-xs font-semibold text-blue-700 inline-flex items-center justify-center gap-1.5 hover:border-blue-600 hover:bg-blue-600 hover:text-white disabled:bg-gray-100 disabled:border-gray-200 disabled:text-gray-400 disabled:cursor-not-allowed">
                                        <i class="ti text-sm" :class="templateInserting === item.key ? 'ti-loader-2 animate-spin' : (item.locked ? 'ti-lock' : (item.source === 'remote' ? 'ti-cloud-download' : 'ti-plus'))"></i>
                                        <span x-text="item.source === 'remote' ? templateText.downloadImport : templateText.insertSection"></span>
                                    </button>
                                </span>
                            </template>
                            <template x-if="templateEntry !== 'sections'">
                                <span class="flex flex-1 min-w-0 flex-col p-3">
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
                                <button type="button" x-show="item.type === 'page' && pageMode" @click="replaceWithTemplate(item)"
                                        :disabled="templateInserting !== '' || !!item.locked"
                                        data-testid="blox-template-replace"
                                        :title="item.locked ? templateLockLabel(item) : templateText.usePage"
                                        class="h-8 flex-1 rounded border border-blue-600 bg-blue-600 px-3 text-xs font-medium text-white inline-flex items-center justify-center gap-1.5 hover:border-blue-500 hover:bg-blue-500 disabled:bg-gray-100 disabled:border-gray-200 disabled:text-gray-400 disabled:cursor-not-allowed">
                                    <i class="ti text-sm" :class="templateInserting === item.key ? 'ti-loader-2 animate-spin' : (item.locked ? 'ti-lock' : 'ti-wand')"></i>
                                    <span x-text="templateText.usePage"></span>
                                </button>
                                <button type="button" x-show="item.type !== 'page' || !pageMode" @click="insertTemplate(item)"
                                        :disabled="templateInserting !== '' || !!item.locked"
                                        data-testid="blox-template-insert"
                                        :title="item.locked ? templateLockLabel(item) : (item.source === 'remote' ? templateText.downloadImport : templateText.insert)"
                                        class="h-8 rounded px-3 text-xs font-medium inline-flex items-center justify-center gap-1.5 disabled:bg-gray-100 disabled:border-gray-200 disabled:text-gray-400 disabled:cursor-not-allowed"
                                        :class="item.source === 'remote'
                                            ? 'w-auto border border-gray-200 bg-white text-gray-600 hover:border-blue-300 hover:bg-blue-50 hover:text-blue-600'
                                            : 'flex-1 border border-blue-600 bg-blue-600 text-white hover:border-blue-500 hover:bg-blue-500'">
                                    <i class="ti text-sm" :class="templateInserting === item.key ? 'ti-loader-2 animate-spin' : (item.locked ? 'ti-lock' : (item.source === 'remote' ? 'ti-cloud-download' : 'ti-plus'))"></i>
                                    <span x-text="item.source === 'remote' ? templateText.downloadImport : (templateEntry === 'sections' ? templateText.insertSection : templateText.insert)"></span>
                                </button>
                                <button type="button" x-show="item.type === 'page' && pageMode && sections.length > 0"
                                        @click="insertTemplate(item)" :disabled="templateInserting !== '' || !!item.locked"
                                        data-testid="blox-template-append" :title="templateText.append" :aria-label="templateText.append + ': ' + item.name"
                                        class="h-8 w-8 shrink-0 rounded border border-gray-200 text-gray-500 inline-flex items-center justify-center hover:border-blue-300 hover:text-blue-600 disabled:opacity-40 disabled:cursor-not-allowed">
                                    <i class="ti ti-plus text-sm"></i>
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
                            </template>
                        </article>
                    </template>
                </div>
            </div>
            <div class="h-12 px-4 flex items-center justify-between border-t border-gray-100 shrink-0">
                <span class="text-xs text-gray-400" aria-live="polite"
                      x-text="templateText.resultCount.replace(':shown', filteredTemplates().length).replace(':total', templateEntryItems().length)"></span>
                <span class="inline-flex items-center gap-4">
                    <button type="button" x-show="templateEntry === 'sections'" @click="openTemplates()"
                            class="text-xs text-gray-500 hover:text-blue-700 inline-flex items-center gap-1">
                        <i class="ti ti-template"></i><span x-text="templateText.allTemplates"></span>
                    </button>
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
         role="status" aria-live="polite" aria-atomic="true"
         class="pointer-events-none fixed bottom-5 left-1/2 -translate-x-1/2 bg-gray-900 text-white text-sm px-4 py-2 rounded-lg shadow-lg z-50"
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
