<?php
declare(strict_types=1);

// Share the canvas breadcrumb hierarchy; the preview shows labels without navigable links.
$pageHeroBreadcrumbParents = $pageHero['available'] ? array_column(array_slice(getBreadcrumb((int) $pageHero['id']), 0, -1), 'name') : [];
?>
    <?php /* 页面标题区：系统区域不进入正文文档，单独保存来源、背景与显示开关。 */ ?>
    <div x-show="pageHeroOpen" x-cloak x-ref="pageHeroDialog" tabindex="-1"
         data-testid="blox-page-hero-dialog"
         @keydown="dialogKeydown($event, $refs.pageHeroDialog, () => closePageHeroSettings())"
         role="dialog" aria-modal="true" aria-labelledby="blox-page-hero-dialog-title"
         class="fixed inset-0 z-[145] flex items-center justify-center p-4 sm:p-6">
        <div class="absolute inset-0 bg-black/50" @click="closePageHeroSettings()"></div>
        <div class="relative flex max-h-[calc(100vh-2rem)] blox-hero-dialog-card max-w-full flex-col overflow-hidden rounded-lg bg-white shadow-2xl">
            <header class="flex min-h-14 items-center gap-3 border-b border-gray-100 px-4 py-3">
                <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded bg-gray-900 text-white">
                    <i aria-hidden="true" class="ti ti-layout-navbar-collapse text-lg"></i>
                </span>
                <span class="min-w-0 flex-1">
                    <strong id="blox-page-hero-dialog-title" class="block text-sm font-semibold text-gray-900" x-text="pageHeroText.title"></strong>
                    <span class="mt-0.5 block text-xs leading-5 text-gray-500" x-text="pageHero.name"></span>
                </span>
                <button type="button" @click="closePageHeroSettings()" :disabled="pageHeroSaving"
                        class="inline-flex h-8 w-8 items-center justify-center text-gray-400 hover:bg-gray-100 hover:text-gray-700 disabled:opacity-40"
                        aria-label="<?= e(__('close')) ?>" title="<?= e(__('close')) ?>">
                    <i aria-hidden="true" class="ti ti-x"></i>
                </button>
            </header>
            <div class="min-h-0 flex-1 overflow-y-auto p-4 sm:p-5 blox-hero-dialog-body">
                <label class="blox-hero-toggle">
                    <span>
                        <strong class="block text-sm font-medium text-gray-900"><?= e(__('blox_page_hero_dialog_visibility')) ?></strong>
                        <span id="blox-hero-visibility-hint" class="block text-xs leading-5 text-gray-600"><?= e(__('blox_page_hero_dialog_visibility_hint')) ?></span>
                    </span>
                    <input type="checkbox" role="switch" x-model="pageHero.show_hero" data-dialog-initial
                           aria-label="<?= e(__('blox_page_hero_dialog_visibility')) ?>" aria-describedby="blox-hero-visibility-hint"
                           class="h-5 w-5 shrink-0 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                </label>
                <div x-show="pageHero.show_hero" class="blox-hero-dialog-grid">
                    <div class="space-y-4 min-w-0">
                        <fieldset>
                            <legend class="mb-2 text-sm font-medium text-gray-800"><?= e(__('blox_page_hero_dialog_source')) ?></legend>
                            <div class="blox-hero-source-choices" :data-has-parent="pageHero.can_inherit">
                                <label class="blox-hero-source-choice border text-sm transition"
                                       data-testid="blox-page-hero-mode-self"
                                       :class="pageHero.style_source === 'self' ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 text-gray-600 hover:border-gray-400'">
                                    <input type="radio" name="blox-hero-source" value="self" x-model="pageHero.style_source" class="sr-only">
                                    <span><?= e(__('blox_page_hero_dialog_mode_self')) ?></span>
                                </label>
                                <label class="blox-hero-source-choice border text-sm transition"
                                       x-show="pageHero.can_inherit" data-testid="blox-page-hero-mode-parent"
                                       :class="pageHero.style_source === 'parent' ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 text-gray-600 hover:border-gray-400'">
                                    <input type="radio" name="blox-hero-source" value="parent" x-model="pageHero.style_source" :disabled="!pageHero.can_inherit" class="sr-only">
                                    <span><?= e(__('blox_page_hero_dialog_mode_parent')) ?></span>
                                </label>
                                <label class="blox-hero-source-choice border text-sm transition"
                                       data-testid="blox-page-hero-mode-global"
                                       :class="pageHero.style_source === 'global' ? 'border-gray-900 bg-gray-900 text-white' : 'border-gray-200 text-gray-600 hover:border-gray-400'">
                                    <input type="radio" name="blox-hero-source" value="global" x-model="pageHero.style_source" class="sr-only">
                                    <span><?= e(__('blox_page_hero_dialog_mode_global')) ?></span>
                                </label>
                            </div>
                        </fieldset>
                        <div x-show="pageHero.style_source !== 'self'" class="blox-hero-source-note" data-testid="blox-page-hero-effective-source">
                            <span class="min-w-0 flex-1">
                                <strong class="text-xs font-medium text-gray-700" x-text="pageHeroEffectiveSourceLabel()"></strong>
                                <span x-show="pageHeroInheritancePathLabel()" class="mt-1 block break-words text-xs leading-5 text-gray-500" x-text="pageHeroInheritancePathLabel()"></span>
                            </span>
                            <button type="button" @click="copyPageHeroToSelf()"
                                    data-testid="blox-page-hero-copy-self" :title="pageHeroText.copyToSelfHint"
                                    class="blox-hero-text-action">
                                <i aria-hidden="true" class="ti ti-copy"></i><span x-text="pageHeroText.copyToSelf"></span>
                            </button>
                        </div>

                        <div x-show="pageHero.style_source === 'self'">
                            <?php $breadcrumbInPageHero = true; require __DIR__ . '/breadcrumb-settings.php'; unset($breadcrumbInPageHero); ?>
                        </div>
                    </div>
                    <section class="blox-hero-preview-pane space-y-2 min-w-0">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-xs font-medium text-gray-600"><?= e(__('blox_page_hero_dialog_preview')) ?></span>
                            <span class="inline-flex border border-gray-200 bg-gray-50 p-1" role="group" :aria-label="pageHeroText.previewDevice">
                                <button type="button" @click="pageHeroPreviewDevice = 'desktop'" :aria-pressed="pageHeroPreviewDevice === 'desktop' ? 'true' : 'false'"
                                        data-testid="blox-page-hero-preview-desktop" class="inline-flex h-7 w-9 items-center justify-center"
                                        :class="pageHeroPreviewDevice === 'desktop' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-400 hover:text-gray-700'" :title="pageHeroText.previewDesktop" :aria-label="pageHeroText.previewDesktop"><i aria-hidden="true" class="ti ti-device-desktop"></i></button>
                                <button type="button" @click="pageHeroPreviewDevice = 'mobile'" :aria-pressed="pageHeroPreviewDevice === 'mobile' ? 'true' : 'false'"
                                        data-testid="blox-page-hero-preview-mobile" class="inline-flex h-7 w-9 items-center justify-center"
                                        :class="pageHeroPreviewDevice === 'mobile' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-400 hover:text-gray-700'" :title="pageHeroText.previewMobile" :aria-label="pageHeroText.previewMobile"><i aria-hidden="true" class="ti ti-device-mobile"></i></button>
                            </span>
                        </div>
                        <div class="flex justify-center overflow-hidden border border-gray-200 rounded-md bg-gray-100 p-2" data-testid="blox-page-hero-style-preview">
                            <div class="w-full overflow-hidden bg-white transition-[max-width] duration-200"
                                 data-testid="blox-page-hero-preview-frame"
                                 :style="'max-width:' + (pageHeroPreviewDevice === 'mobile' ? 'min(65%, 390px)' : '100%')">
                                <template x-if="pageHeroPreviewOptions().layout === 'compact'">
                                    <?php $breadcrumbParents = $pageHeroBreadcrumbParents; require __DIR__ . '/breadcrumb-preview.php'; ?>
                                </template>
                                <template x-if="pageHeroPreviewOptions().layout !== 'compact'">
                                <div class="relative bg-cover px-5 transition-[height] duration-200"
                                     :class="[
                                        pageHeroPreviewOptions().layout === 'compact' ? 'h-12' : (pageHeroPreviewHeight() === 'large' ? 'h-36' : (pageHeroPreviewHeight() === 'compact' ? 'h-20' : 'h-28')),
                                        pageHeroPreviewOptions().layout !== 'compact' && !pageHeroPreviewBackground() && !pageHeroPreviewOptions().background_color ? 'bg-gradient-to-r from-gray-900 via-gray-800 to-gray-900' : ''
                                     ]"
                                     :style="pageHeroPreviewStyle()">
                                    <template x-if="pageHeroPreviewOptions().layout !== 'compact' && pageHeroPreviewBackground() && Number(pageHeroPreviewOptions().overlay_opacity || 0) > 0">
                                    <div
                                         class="absolute inset-0 bg-black"
                                         :style="{ opacity: Number(pageHeroPreviewOptions().overlay_opacity || 0) / 100 }"></div>
                                    </template>
                                    <div class="relative flex h-full min-w-0 flex-col justify-center"
                                         :class="pageHeroPreviewOptions().layout !== 'compact' && pageHeroPreviewOptions().alignment === 'center' ? 'items-center text-center' : 'items-start text-left'">
                                        <span class="max-w-full truncate text-[10px]"
                                              :class="pageHeroPreviewTone() === 'light' ? 'text-white/65' : 'text-gray-500'"><?= e(implode(' / ', array_merge([__('breadcrumb_home')], $pageHeroBreadcrumbParents))) ?> / <span x-text="pageHero.name"></span></span>
                                        <strong x-show="pageHeroPreviewOptions().layout !== 'compact'" class="mt-1 max-w-full truncate text-base"
                                                :class="pageHeroPreviewTone() === 'light' ? 'text-white' : 'text-gray-900'"
                                                x-text="pageHero.name"></strong>
                                        <span x-show="pageHeroPreviewOptions().layout !== 'compact' && pageHero.description" class="mt-1 max-w-[80%] truncate text-[11px]"
                                              :class="pageHeroPreviewTone() === 'light' ? 'text-white/75' : 'text-gray-600'"
                                              x-text="pageHero.description"></span>
                                    </div>
                                </div>
                                </template>
                            </div>
                        </div>
                    </section>
                    <section x-show="pageHero.style_source === 'self' && pageHeroPreviewOptions().layout !== 'compact'"
                             data-testid="blox-page-hero-banner-controls" class="blox-hero-custom space-y-4 min-w-0">
                        <div>
                            <div class="mb-2 flex items-center justify-between gap-3">
                                <span class="text-sm font-medium text-gray-800" x-text="pageHeroText.presets"></span>
                            </div>
                            <div class="blox-hero-presets">
                                <button type="button" @click="applyPageHeroPreset('standard')" :disabled="pageHero.style_source !== 'self'"
                                        data-testid="blox-page-hero-preset-standard"
                                        class="min-h-9 rounded border border-gray-200 px-2 py-1.5 text-xs text-gray-700 hover:border-gray-400 disabled:cursor-not-allowed" x-text="pageHeroText.presetStandard"></button>
                                <button type="button" @click="applyPageHeroPreset('compact')" :disabled="pageHero.style_source !== 'self'"
                                        data-testid="blox-page-hero-preset-compact"
                                        class="min-h-9 rounded border border-gray-200 px-2 py-1.5 text-xs text-gray-700 hover:border-gray-400 disabled:cursor-not-allowed" x-text="pageHeroText.presetCompact"></button>
                                <button type="button" @click="applyPageHeroPreset('statement')" :disabled="pageHero.style_source !== 'self'"
                                        data-testid="blox-page-hero-preset-statement"
                                        class="min-h-9 rounded border border-gray-200 px-2 py-1.5 text-xs text-gray-700 hover:border-gray-400 disabled:cursor-not-allowed" x-text="pageHeroText.presetStatement"></button>
                                <button type="button" @click="applyPageHeroPreset('minimal')" :disabled="pageHero.style_source !== 'self'"
                                        data-testid="blox-page-hero-preset-minimal"
                                        class="min-h-9 rounded border border-gray-200 px-2 py-1.5 text-xs text-gray-700 hover:border-gray-400 disabled:cursor-not-allowed" x-text="pageHeroText.presetMinimal"></button>
                            </div>
                        </div>

                        <div data-testid="blox-page-hero-background-controls">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <h3 class="text-sm font-medium text-gray-800" x-text="pageHeroText.background"></h3>
                                    <p class="text-xs leading-5 text-gray-600" x-text="pageHeroSourceLabel()"></p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <button type="button" @click="pickPageHeroBackground()" class="blox-hero-image-button">
                                        <i class="ti ti-photo" aria-hidden="true"></i><?= e(__('blox_page_hero_dialog_choose_image')) ?>
                                    </button>
                                    <button x-show="pageHero.hero_bg" type="button" @click="pageHero.hero_bg = ''" class="blox-hero-text-action">
                                        <?= e(__('blox_page_hero_dialog_remove_image')) ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <details class="blox-hero-details" data-testid="blox-page-hero-details">
                            <summary><?= e(__('blox_page_hero_dialog_details')) ?></summary>
                            <div class="space-y-4 p-3">
                                <div>
                                    <label class="mb-1.5 block text-xs font-medium text-gray-600" for="blox-page-hero-bg"><?= e(__('blox_page_hero_dialog_image_url')) ?></label>
                                    <input id="blox-page-hero-bg" type="text" x-model="pageHero.hero_bg"
                                           placeholder="/uploads/images/page-hero.jpg"
                                           class="w-full rounded border border-gray-300 px-3 py-2 text-sm">
                                    <p class="mt-1 text-xs leading-5 text-gray-600" x-text="pageHeroText.backgroundHint"></p>
                                </div>
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    <div>
                                        <label for="blox-page-hero-color" class="mb-1.5 block text-xs font-medium text-gray-600" x-text="pageHeroText.backgroundColor"></label>
                                        <button type="button"
                                                @click="openEditorColorPicker($event, 'page-hero-bg', pageHeroText.backgroundColor, pageHero.style_options.background_color, '#111827', true, value => pageHero.style_options.background_color = value)"
                                                :disabled="pageHero.style_source !== 'self'"
                                                id="blox-page-hero-color" data-testid="blox-page-hero-color-picker"
                                                class="flex h-10 w-full items-center gap-2 border border-gray-200 bg-white px-2 text-left hover:border-blue-300 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:cursor-not-allowed">
                                            <span class="h-7 w-9 shrink-0 border border-black/10" :style="'background:' + colorFieldPreview(pageHero.style_options.background_color, '#111827')"></span>
                                            <span class="min-w-0 flex-1 truncate text-sm text-gray-700" x-text="colorFieldLabel(pageHero.style_options.background_color, designText.clear)"></span>
                                            <i aria-hidden="true" class="ti ti-chevron-down text-sm text-gray-400"></i>
                                        </button>
                                    </div>
                                    <div x-show="pageHeroPreviewBackground()">
                                        <div class="mb-1.5 flex items-center justify-between gap-2 text-xs font-medium text-gray-600">
                                            <label for="blox-page-hero-overlay" x-text="pageHeroText.overlay"></label>
                                            <span class="tabular-nums" x-text="String(pageHero.style_options.overlay_opacity) + '%' "></span>
                                        </div>
                                        <input id="blox-page-hero-overlay" type="range" min="0" max="90" step="5"
                                               x-model.number="pageHero.style_options.overlay_opacity" :disabled="pageHero.style_source !== 'self'"
                                               class="h-10 w-full accent-gray-900 disabled:cursor-not-allowed">
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
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
                                            <label class="text-[11px] text-gray-500"><span class="flex justify-between"><span x-text="pageHeroText.focusX"></span><span x-text="pageHero.style_options.focal_x + '%'"></span></span><input type="range" min="0" max="100" step="5" x-model.number="pageHero.style_options.focal_x" :disabled="pageHero.style_source !== 'self'" data-testid="blox-page-hero-focus-x" class="h-7 w-full accent-gray-900"></label>
                                            <label class="text-[11px] text-gray-500"><span class="flex justify-between"><span x-text="pageHeroText.focusY"></span><span x-text="pageHero.style_options.focal_y + '%'"></span></span><input type="range" min="0" max="100" step="5" x-model.number="pageHero.style_options.focal_y" :disabled="pageHero.style_source !== 'self'" data-testid="blox-page-hero-focus-y" class="h-7 w-full accent-gray-900"></label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </details>
                    </section>
                </div>
                <details x-show="pageHero.cover_applicable" class="blox-hero-details" data-testid="blox-page-cover-setting">
                    <summary><?= e(__('blox_page_hero_dialog_cover')) ?></summary>
                    <div class="p-3">
                        <label class="flex items-center justify-between gap-4">
                            <strong class="block text-sm font-medium text-gray-800"><?= e(__('blox_page_cover_toggle')) ?></strong>
                            <input type="checkbox" x-model="pageHero.show_cover" data-testid="blox-page-cover-toggle"
                                   class="h-5 w-5 shrink-0 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        </label>
                        <p class="mt-1.5 text-xs leading-5 text-gray-600"><?= e(__('blox_page_hero_dialog_cover_hint')) ?></p>
                    </div>
                </details>
            </div>
            <footer class="blox-hero-dialog-footer border-t border-gray-100 px-4 py-3">
                <p class="text-xs leading-5 text-gray-600"><?= e(__('blox_page_hero_dialog_save_hint')) ?></p>
                <div class="flex shrink-0 items-center justify-end gap-2">
                    <button type="button" @click="closePageHeroSettings()" :disabled="pageHeroSaving"
                            class="h-9 border border-gray-300 px-4 text-sm text-gray-600 hover:bg-gray-50 disabled:opacity-40"><?= e(__('cancel')) ?></button>
                    <button type="button" @click="savePageHeroSettings()" :disabled="pageHeroSaving"
                            class="h-9 bg-gray-900 px-4 text-sm font-medium text-white hover:bg-gray-800 disabled:cursor-wait disabled:opacity-60 inline-flex items-center gap-2">
                        <i aria-hidden="true" class="ti" :class="pageHeroSaving ? 'ti-loader-2 animate-spin' : 'ti-device-floppy'"></i>
                        <span x-text="pageHeroSaving ? pageHeroText.saving : pageHeroText.save"></span>
                    </button>
                </div>
            </footer>
        </div>
    </div>
