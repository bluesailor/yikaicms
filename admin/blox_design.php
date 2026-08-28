<?php
/** Blox 全站设计系统独立管理页。 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

$advancedBloxEnabled = bloxAdvancedFeaturesEnabled();
$designState = BloxDesignSystem::snapshot();
$designUsage = BloxDesignDependencies::usageSnapshot();

$GLOBALS['pageTitle'] = __('blox_design_system');
$GLOBALS['currentMenu'] = 'blox_design';
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="min-w-0 max-w-full space-y-7 overflow-hidden" x-data="bloxDesignManager()" data-testid="blox-design-page">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="mb-1 flex items-center gap-2 text-xs text-gray-400">
                <a href="/admin/site_design.php" class="hover:text-primary"><?php echo e(__('site_design_title')); ?></a>
                <i class="ti ti-chevron-right"></i>
                <span><?php echo e(__('blox_design_system')); ?></span>
            </div>
            <h1 class="text-xl font-semibold text-gray-900"><?php echo e(__('blox_design_system')); ?></h1>
            <p class="mt-1 max-w-3xl text-sm text-gray-500"><?php echo e(__('blox_design_page_intro')); ?></p>
        </div>
        <a href="/admin/site_design.php" class="inline-flex h-10 items-center gap-2 border border-gray-300 bg-white px-4 text-sm text-gray-700 hover:bg-gray-50">
            <i class="ti ti-arrow-left"></i><?php echo e(__('blox_design_back_to_site')); ?>
        </a>
    </div>

    <div x-show="notice" x-cloak class="border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status" x-text="notice"></div>
    <div x-show="error" x-cloak class="border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert" x-text="error"></div>

    <div class="flex flex-wrap items-end gap-1 border-b border-gray-200" role="tablist" aria-label="<?php echo e(__('blox_design_system')); ?>">
        <button type="button" role="tab" data-testid="blox-design-page-tab-colors"
                @click="tab = 'colors'" :aria-selected="tab === 'colors'"
                class="inline-flex h-11 items-center gap-2 border-b-2 px-4 text-sm font-medium"
                :class="tab === 'colors' ? 'border-emerald-500 text-emerald-700' : 'border-transparent text-gray-500 hover:text-gray-900'">
            <i class="ti ti-color-swatch"></i><?php echo e(__('blox_design_colors')); ?>
            <span class="bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500" x-text="activeTokens().length"></span>
        </button>
        <button type="button" role="tab" data-testid="blox-design-page-tab-styles"
                @click="advanced && (tab = 'styles')" :aria-selected="tab === 'styles'" :aria-disabled="advanced ? 'false' : 'true'"
                class="inline-flex h-11 items-center gap-2 border-b-2 px-4 text-sm font-medium"
                :class="tab === 'styles' ? 'border-emerald-500 text-emerald-700' : (advanced ? 'border-transparent text-gray-500 hover:text-gray-900' : 'cursor-not-allowed border-transparent text-gray-300')">
            <i class="ti ti-components"></i><?php echo e(__('blox_design_styles')); ?>
            <i x-show="!advanced" class="ti ti-lock text-xs"></i>
            <span x-show="advanced" class="bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500" x-text="activeStyles().length"></span>
        </button>
        <span x-show="busy" class="ml-auto mb-3 inline-flex items-center gap-1 text-xs text-gray-400">
            <i class="ti ti-loader-2 animate-spin"></i><?php echo e(__('loading')); ?>
        </span>
    </div>

    <section x-show="tab === 'colors'" data-testid="blox-design-page-colors">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold text-gray-900"><?php echo e(__('blox_design_colors')); ?></h2>
                <p class="mt-1 text-xs text-gray-500"><?php echo e(__('blox_design_tokens_hint')); ?></p>
            </div>
        </div>

        <form @submit.prevent="addToken()" class="grid min-w-0 max-w-full gap-3 border-y border-gray-200 bg-gray-50 px-4 py-4 md:grid-cols-[minmax(0,1.2fr)_minmax(0,.8fr)_minmax(0,1fr)_auto] md:items-end">
            <label class="block text-xs font-medium text-gray-600">
                <?php echo e(__('blox_design_name')); ?>
                <input type="text" x-model="newToken.name" maxlength="60" required data-testid="blox-design-page-new-token-name"
                       class="mt-1 h-10 w-full border border-gray-300 bg-white px-3 text-sm">
            </label>
            <label class="block text-xs font-medium text-gray-600">
                <?php echo e(__('blox_design_category')); ?>
                <input type="text" x-model="newToken.category" maxlength="32" class="mt-1 h-10 w-full border border-gray-300 bg-white px-3 text-sm">
            </label>
            <label class="block text-xs font-medium text-gray-600">
                <?php echo e(__('blox_design_value')); ?>
                <button type="button" @click="openColorPicker($event, text.newColor, newToken.value, value => newToken.value = value)"
                        data-testid="blox-design-page-new-token-color" :aria-expanded="picker.open && picker.key === 'new'"
                        class="mt-1 flex h-10 w-full items-center gap-2 border border-gray-300 bg-white px-2 text-left hover:border-gray-400 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-100">
                    <span class="h-7 w-9 shrink-0 border border-black/10" :style="'background:' + newToken.value"></span>
                    <span class="min-w-0 flex-1 font-mono text-sm text-gray-700" x-text="newToken.value"></span>
                    <i class="ti ti-chevron-down text-sm text-gray-400"></i>
                </button>
            </label>
            <button type="submit" :disabled="busy || !newToken.name.trim()" data-testid="blox-design-page-add-token"
                    class="inline-flex h-10 items-center justify-center gap-2 bg-emerald-600 px-4 text-sm font-medium text-white hover:bg-emerald-500 disabled:cursor-not-allowed disabled:opacity-40">
                <i class="ti ti-plus"></i><?php echo e(__('blox_design_add')); ?>
            </button>
        </form>

        <div class="mt-4 max-w-full overflow-x-auto border-y border-gray-200 bg-white">
            <table class="w-full min-w-[760px] text-left text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500">
                    <tr>
                        <th class="w-14 px-4 py-3"><?php echo e(__('blox_design_value')); ?></th>
                        <th class="px-3 py-3"><?php echo e(__('blox_design_name')); ?></th>
                        <th class="px-3 py-3"><?php echo e(__('blox_design_category')); ?></th>
                        <th class="px-3 py-3"><?php echo e(__('blox_design_value')); ?></th>
                        <th class="px-3 py-3"><?php echo e(__('blox_design_usage')); ?></th>
                        <th class="px-4 py-3 text-right"><?php echo e(__('blox_tpl_col_actions')); ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <template x-for="token in activeTokens()" :key="token.id">
                        <tr data-testid="blox-design-page-token-row">
                            <td class="px-4 py-3"><span class="block h-8 w-8 border border-gray-200" :style="'background:' + token.value"></span></td>
                            <td class="px-3 py-3"><input type="text" x-model="token.name" :disabled="token.system || token.locked" maxlength="60" class="h-9 w-full min-w-36 border border-gray-300 px-2 text-sm disabled:bg-gray-50 disabled:text-gray-500"></td>
                            <td class="px-3 py-3"><input type="text" x-model="token.category" :disabled="token.system || token.locked" maxlength="32" class="h-9 w-full min-w-28 border border-gray-300 px-2 text-sm disabled:bg-gray-50 disabled:text-gray-500"></td>
                            <td class="px-3 py-3">
                                <button type="button" @click="openColorPicker($event, token.name, token.value, value => token.value = value, token)"
                                        :disabled="token.system || token.locked" :data-testid="'blox-design-page-token-color-' + token.id"
                                        class="flex h-9 min-w-44 items-center gap-2 border border-gray-300 bg-white px-2 text-left hover:border-gray-400 focus:border-emerald-500 focus:outline-none focus:ring-2 focus:ring-emerald-100 disabled:cursor-not-allowed disabled:bg-gray-50 disabled:text-gray-500">
                                    <span class="h-6 w-8 shrink-0 border border-black/10" :style="'background:' + token.value"></span>
                                    <span class="min-w-0 flex-1 font-mono text-xs" x-text="token.value"></span>
                                    <i class="ti ti-chevron-down text-sm text-gray-400"></i>
                                </button>
                            </td>
                            <td class="px-3 py-3 text-xs text-gray-500" x-text="usageLabel('token', token.id)"></td>
                            <td class="px-4 py-3 text-right">
                                <a x-show="token.system" href="/admin/setting.php?group=basic" class="inline-flex h-8 w-8 items-center justify-center text-gray-400 hover:text-primary" title="<?php echo e(__('blox_design_system_color')); ?>"><i class="ti ti-external-link"></i></a>
                                <button x-show="!token.system" type="button" @click="toggleLock('token', token)" class="inline-flex h-8 w-8 items-center justify-center text-gray-400 hover:text-amber-600" :title="token.locked ? text.unlock : text.lock"><i class="ti" :class="token.locked ? 'ti-lock' : 'ti-lock-open'"></i></button>
                                <button x-show="!token.system && !token.locked" type="button" @click="updateToken(token)" class="inline-flex h-8 w-8 items-center justify-center text-gray-400 hover:text-emerald-600" title="<?php echo e(__('blox_design_save')); ?>"><i class="ti ti-device-floppy"></i></button>
                                <button x-show="!token.system && !token.locked" type="button" @click="archiveItem('token', token)" class="inline-flex h-8 w-8 items-center justify-center text-gray-400 hover:text-red-600" title="<?php echo e(__('blox_design_archive')); ?>"><i class="ti ti-trash"></i></button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <details x-show="archivedTokens().length" class="mt-4 border-y border-gray-200 bg-white">
            <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-gray-600"><?php echo e(__('blox_design_archived')); ?> <span class="text-xs text-gray-400" x-text="'(' + archivedTokens().length + ')' "></span></summary>
            <template x-for="token in archivedTokens()" :key="'archived-token-' + token.id">
                <div class="flex items-center gap-3 border-t border-gray-100 px-4 py-3">
                    <span class="h-7 w-7 border border-gray-200 opacity-60" :style="'background:' + token.value"></span>
                    <span class="min-w-0 flex-1 text-sm text-gray-600" x-text="token.name"></span>
                    <span class="text-xs text-gray-400" x-text="usageLabel('token', token.id)"></span>
                    <button type="button" @click="restoreItem('token', token)" class="inline-flex items-center gap-1 text-sm text-emerald-700 hover:text-emerald-600"><i class="ti ti-restore"></i><?php echo e(__('blox_design_restore')); ?></button>
                </div>
            </template>
        </details>
    </section>

    <div x-show="picker.open" x-cloak @keydown.escape.window="closeColorPicker()" class="fixed inset-0 z-[130]" data-testid="blox-color-picker-layer">
        <button type="button" class="absolute inset-0 cursor-default bg-transparent" @click="closeColorPicker()" tabindex="-1" aria-hidden="true"></button>
        <section role="dialog" aria-modal="false" :aria-label="picker.title" data-testid="blox-color-picker"
                 class="absolute w-[304px] max-w-[calc(100vw-24px)] border border-gray-200 bg-white shadow-xl"
                 :style="picker.style">
            <header class="flex h-11 items-center justify-between border-b border-gray-100 px-3">
                <div class="min-w-0">
                    <h3 class="truncate text-sm font-semibold text-gray-900" x-text="picker.title"></h3>
                </div>
                <button type="button" @click="closeColorPicker()" class="inline-flex h-8 w-8 items-center justify-center text-gray-400 hover:bg-gray-100 hover:text-gray-700" :aria-label="text.close" :title="text.close">
                    <i class="ti ti-x"></i>
                </button>
            </header>
            <div class="max-h-[min(520px,calc(100vh-90px))] overflow-y-auto p-3">
                <div>
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <span class="text-xs font-medium text-gray-600" x-text="text.siteColors"></span>
                        <span class="text-[11px] text-gray-400" x-text="activeTokens().length"></span>
                    </div>
                    <div class="grid grid-cols-8 gap-2" data-testid="blox-color-picker-site-colors">
                        <template x-for="token in activeTokens()" :key="'picker-token-' + token.id">
                            <button type="button" @click="applyPickerColor(token.value)" :title="token.name + ' · ' + token.value" :aria-label="token.name + ' ' + token.value"
                                    class="relative h-7 w-7 border border-black/10 hover:scale-110 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2"
                                    :class="picker.value === token.value ? 'ring-2 ring-emerald-500 ring-offset-2' : ''" :style="'background:' + token.value">
                                <i x-show="picker.value === token.value" class="ti ti-check absolute inset-0 flex items-center justify-center text-sm" :class="pickerCheckClass(token.value)"></i>
                            </button>
                        </template>
                    </div>
                </div>

                <div class="mt-4 border-t border-gray-100 pt-3">
                    <span class="mb-2 block text-xs font-medium text-gray-600" x-text="text.recommended"></span>
                    <div class="space-y-2">
                        <template x-for="group in paletteGroups" :key="group.id">
                            <div class="grid grid-cols-8 gap-2">
                                <template x-for="color in group.colors" :key="group.id + '-' + color">
                                    <button type="button" @click="applyPickerColor(color)" :title="color" :aria-label="color"
                                            class="relative h-7 w-7 border border-black/10 hover:scale-110 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-1"
                                            :class="picker.value === color ? 'ring-2 ring-emerald-500 ring-offset-1' : ''" :style="'background:' + color">
                                        <i x-show="picker.value === color" class="ti ti-check absolute inset-0 flex items-center justify-center text-sm" :class="pickerCheckClass(color)"></i>
                                    </button>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>

                <div x-show="recentColors.length" class="mt-4 border-t border-gray-100 pt-3">
                    <span class="mb-2 block text-xs font-medium text-gray-600" x-text="text.recent"></span>
                    <div class="flex flex-wrap gap-2">
                        <template x-for="color in recentColors" :key="'recent-' + color">
                            <button type="button" @click="applyPickerColor(color)" :title="color" :aria-label="color"
                                    class="h-7 w-7 border border-black/10 hover:scale-110 focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2" :style="'background:' + color"></button>
                        </template>
                    </div>
                </div>

                <div class="mt-4 border-t border-gray-100 pt-3">
                    <label class="mb-2 block text-xs font-medium text-gray-600" for="blox-design-custom-color" x-text="text.custom"></label>
                    <div class="flex h-10 items-center gap-2 border bg-white px-2 focus-within:ring-2"
                         :class="picker.invalid ? 'border-red-400 focus-within:border-red-500 focus-within:ring-red-100' : 'border-gray-300 focus-within:border-emerald-500 focus-within:ring-emerald-100'">
                        <input id="blox-design-custom-color" type="color" :value="picker.value" @input="applyPickerColor($event.target.value)"
                               class="h-7 w-9 shrink-0 cursor-pointer border-0 bg-transparent p-0" data-testid="blox-color-picker-native">
                        <input type="text" :value="picker.value" @input="picker.invalid = false" @change="applyPickerText($event.target.value, $event.target)" @keydown.enter.prevent="applyPickerText($event.target.value, $event.target)"
                               x-bind:aria-invalid="picker.invalid" pattern="#[0-9a-fA-F]{6}" maxlength="7" spellcheck="false" class="min-w-0 flex-1 border-0 bg-transparent font-mono text-sm uppercase outline-none" data-testid="blox-color-picker-text">
                    </div>
                    <p x-show="picker.invalid" class="mt-2 text-[11px] leading-4 text-red-600" role="alert" x-text="text.invalidColor"></p>
                    <p class="mt-2 text-[11px] leading-4 text-gray-400" x-text="text.pickerHint"></p>
                </div>
            </div>
        </section>
    </div>

    <section x-show="tab === 'styles' && advanced" x-cloak data-testid="blox-design-page-styles">
        <div class="mb-4">
            <h2 class="text-sm font-semibold text-gray-900"><?php echo e(__('blox_design_styles')); ?></h2>
            <p class="mt-1 text-xs text-gray-500"><?php echo e(__('blox_design_styles_hint')); ?></p>
        </div>

        <form @submit.prevent="addStyle()" class="grid min-w-0 max-w-full gap-3 border-y border-gray-200 bg-gray-50 px-4 py-4 lg:grid-cols-[1.1fr_.8fr_repeat(3,1fr)_.8fr_auto] lg:items-end">
            <label class="block text-xs font-medium text-gray-600"><?php echo e(__('blox_design_name')); ?><input type="text" x-model="newStyle.name" maxlength="60" required class="mt-1 h-10 w-full border border-gray-300 bg-white px-2 text-sm"></label>
            <label class="block text-xs font-medium text-gray-600"><?php echo e(__('blox_design_category')); ?><input type="text" x-model="newStyle.category" maxlength="32" class="mt-1 h-10 w-full border border-gray-300 bg-white px-2 text-sm"></label>
            <template x-for="field in styleColorFields" :key="'new-' + field.key">
                <label class="block text-xs font-medium text-gray-600"><span x-text="field.label"></span><select x-model="newStyle[field.key]" class="mt-1 h-10 w-full border border-gray-300 bg-white px-2 text-sm"><option value=""><?php echo e(__('none')); ?></option><template x-for="token in activeTokens()" :key="'new-option-' + field.key + '-' + token.id"><option :value="tokenRef(token.id)" x-text="token.name"></option></template></select></label>
            </template>
            <label class="block text-xs font-medium text-gray-600"><?php echo e(__('blox_design_radius')); ?><select x-model="newStyle.radius" class="mt-1 h-10 w-full border border-gray-300 bg-white px-2 text-sm"><option value="none"><?php echo e(__('blox_spacing_none')); ?></option><option value="sm"><?php echo e(__('blox_spacing_sm')); ?></option><option value="md"><?php echo e(__('blox_spacing_md')); ?></option><option value="lg"><?php echo e(__('blox_spacing_lg')); ?></option><option value="full"><?php echo e(__('blox_design_radius_full')); ?></option></select></label>
            <button type="submit" :disabled="busy || !newStyle.name.trim()" data-testid="blox-design-page-add-style"
                    title="<?php echo e(__('blox_design_add')); ?>" aria-label="<?php echo e(__('blox_design_add')); ?>"
                    class="inline-flex h-10 items-center justify-center bg-emerald-600 px-4 text-white hover:bg-emerald-500 disabled:opacity-40"><i class="ti ti-plus"></i></button>
        </form>

        <div class="mt-4 space-y-3">
            <template x-for="style in activeStyles()" :key="style.id">
                <div class="grid gap-3 border-y border-gray-200 bg-white px-4 py-4 lg:grid-cols-[1.1fr_.8fr_repeat(3,1fr)_.8fr_auto] lg:items-center" data-testid="blox-design-page-style-row">
                    <input type="text" x-model="style.name" :disabled="style.locked" class="h-9 min-w-0 border border-gray-300 px-2 text-sm disabled:bg-gray-50">
                    <input type="text" x-model="style.category" :disabled="style.locked" class="h-9 min-w-0 border border-gray-300 px-2 text-sm disabled:bg-gray-50">
                    <template x-for="field in styleColorFields" :key="style.id + '-' + field.key">
                        <select x-model="style[field.key]" :disabled="style.locked" :title="field.label" class="h-9 min-w-0 border border-gray-300 bg-white px-2 text-sm disabled:bg-gray-50"><option value="" x-text="field.label + ' · ' + text.none"></option><template x-for="token in tokenOptions(style[field.key])" :key="style.id + '-' + field.key + '-' + token.id"><option :value="tokenRef(token.id)" x-text="tokenLabel(token)"></option></template></select>
                    </template>
                    <select x-model="style.radius" :disabled="style.locked" class="h-9 border border-gray-300 bg-white px-2 text-sm disabled:bg-gray-50"><option value="none"><?php echo e(__('blox_spacing_none')); ?></option><option value="sm"><?php echo e(__('blox_spacing_sm')); ?></option><option value="md"><?php echo e(__('blox_spacing_md')); ?></option><option value="lg"><?php echo e(__('blox_spacing_lg')); ?></option><option value="full"><?php echo e(__('blox_design_radius_full')); ?></option></select>
                    <div class="flex items-center justify-end gap-1">
                        <span class="mr-1 text-xs text-gray-400" x-text="usageLabel('style', style.id)"></span>
                        <button type="button" @click="toggleLock('style', style)" class="inline-flex h-8 w-8 items-center justify-center text-gray-400 hover:text-amber-600" :title="style.locked ? text.unlock : text.lock"><i class="ti" :class="style.locked ? 'ti-lock' : 'ti-lock-open'"></i></button>
                        <button x-show="!style.locked" type="button" @click="updateStyle(style)" class="inline-flex h-8 w-8 items-center justify-center text-gray-400 hover:text-emerald-600" title="<?php echo e(__('blox_design_save')); ?>"><i class="ti ti-device-floppy"></i></button>
                        <button x-show="!style.locked" type="button" @click="archiveItem('style', style)" class="inline-flex h-8 w-8 items-center justify-center text-gray-400 hover:text-red-600" title="<?php echo e(__('blox_design_archive')); ?>"><i class="ti ti-trash"></i></button>
                    </div>
                </div>
            </template>
        </div>

        <details x-show="archivedStyles().length" class="mt-4 border-y border-gray-200 bg-white">
            <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-gray-600"><?php echo e(__('blox_design_archived')); ?> <span class="text-xs text-gray-400" x-text="'(' + archivedStyles().length + ')' "></span></summary>
            <template x-for="style in archivedStyles()" :key="'archived-style-' + style.id">
                <div class="flex items-center gap-3 border-t border-gray-100 px-4 py-3"><i class="ti ti-components text-gray-400"></i><span class="min-w-0 flex-1 text-sm text-gray-600" x-text="style.name"></span><span class="text-xs text-gray-400" x-text="usageLabel('style', style.id)"></span><button type="button" @click="restoreItem('style', style)" class="inline-flex items-center gap-1 text-sm text-emerald-700 hover:text-emerald-600"><i class="ti ti-restore"></i><?php echo e(__('blox_design_restore')); ?></button></div>
            </template>
        </details>
    </section>

    <div x-show="!advanced" class="border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" data-testid="blox-design-page-advanced-locked">
        <strong><?php echo e(__('blox_design_styles')); ?></strong>
        <span class="ml-1"><?php echo e(__('blox_design_advanced_hint')); ?></span>
    </div>
</div>

<script src="/assets/js/blox-color-picker.js"></script>
<script>
function bloxDesignManager() {
    var colorPicker = window.YikaiBloxColorPicker;
    return {
        tab: 'colors',
        advanced: <?php echo $advancedBloxEnabled ? 'true' : 'false'; ?>,
        state: <?php echo json_encode($designState, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        usage: <?php echo json_encode($designUsage, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        csrf: <?php echo json_encode(csrfToken()); ?>,
        busy: false,
        notice: '',
        error: '',
        newToken: { name: '', category: 'brand', value: '#3b82f6' },
        paletteGroups: colorPicker.paletteGroups,
        recentColors: colorPicker.loadRecent(),
        picker: { open: false, key: '', title: '', value: '#3b82f6', style: '', apply: null, invalid: false },
        newStyle: { name: '', category: 'general', color: '', background: '', border_color: '', radius: 'none' },
        styleColorFields: [
            { key: 'color', label: <?php echo json_encode(__('blox_design_text_color'), JSON_UNESCAPED_UNICODE); ?> },
            { key: 'background', label: <?php echo json_encode(__('blox_design_background'), JSON_UNESCAPED_UNICODE); ?> },
            { key: 'border_color', label: <?php echo json_encode(__('blox_design_border'), JSON_UNESCAPED_UNICODE); ?> }
        ],
        text: <?php echo json_encode([
            'saved' => __('blox_design_saved'),
            'failed' => __('blox_save_failed'),
            'used' => __('blox_design_used_count'),
            'unused' => __('blox_design_unused'),
            'archiveUsed' => __('blox_design_archive_used_confirm'),
            'lock' => __('blox_design_lock'),
            'unlock' => __('blox_design_unlock'),
            'archived' => __('blox_design_archived'),
            'none' => __('none'),
            'newColor' => __('blox_color_new'),
            'siteColors' => __('blox_color_site_colors'),
            'recommended' => __('blox_color_recommended'),
            'recent' => __('blox_color_recent'),
            'custom' => __('blox_design_custom'),
            'pickerHint' => __('blox_color_picker_hint'),
            'invalidColor' => __('blox_color_invalid'),
            'close' => __('close'),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>,
        activeTokens() { return (this.state.tokens || []).filter((item) => item.status !== 'archived'); },
        archivedTokens() { return (this.state.tokens || []).filter((item) => item.status === 'archived'); },
        activeStyles() { return (this.state.styles || []).filter((item) => item.status !== 'archived'); },
        archivedStyles() { return (this.state.styles || []).filter((item) => item.status === 'archived'); },
        tokenRef(id) { return 'var(--yk-color-' + String(id || '') + ')'; },
        tokenId(value) {
            var match = String(value || '').match(/^var\(--yk-color-([a-z][a-z0-9_-]{0,47})\)$/);
            return match ? match[1] : '';
        },
        tokenOptions(value) {
            var items = this.activeTokens();
            var id = this.tokenId(value);
            if (!id || items.some((item) => item.id === id)) return items;
            var archived = (this.state.tokens || []).find((item) => item.id === id);
            return archived ? items.concat([archived]) : items;
        },
        tokenLabel(token) { return token.status === 'archived' ? token.name + ' · ' + this.text.archived : token.name; },
        openColorPicker(event, title, value, apply, token) {
            var rect = event.currentTarget.getBoundingClientRect();
            var width = 304;
            var left = Math.min(rect.left, window.innerWidth - width - 12);
            var top = rect.bottom + 8;
            if (top + 520 > window.innerHeight) top = Math.max(56, window.innerHeight - 532);
            if (window.innerWidth < 640) {
                this.picker.style = 'left:12px;right:12px;bottom:12px;top:auto;width:auto';
            } else {
                this.picker.style = 'left:' + Math.max(12, left) + 'px;top:' + top + 'px';
            }
            this.picker.key = token ? String(token.id || '') : 'new';
            this.picker.title = String(title || this.text.custom);
            this.picker.value = colorPicker.normalizeHex(value, '#3b82f6');
            this.picker.apply = apply;
            this.picker.invalid = false;
            this.picker.open = true;
        },
        closeColorPicker() {
            this.picker.open = false;
            this.picker.apply = null;
        },
        applyPickerColor(value) {
            var normalized = colorPicker.normalizeHex(value, '');
            if (!normalized || typeof this.picker.apply !== 'function') return;
            this.picker.value = normalized;
            this.picker.invalid = false;
            this.picker.apply(normalized);
            this.recentColors = colorPicker.remember(normalized);
        },
        applyPickerText(value, input) {
            var normalized = colorPicker.normalizeHex(value, '');
            if (!normalized) {
                this.picker.invalid = true;
                if (input) input.value = this.picker.value;
                return;
            }
            this.applyPickerColor(normalized);
        },
        pickerCheckClass(value) {
            var color = colorPicker.normalizeHex(value, '#ffffff');
            var red = parseInt(color.slice(1, 3), 16);
            var green = parseInt(color.slice(3, 5), 16);
            var blue = parseInt(color.slice(5, 7), 16);
            return ((red * 299 + green * 587 + blue * 114) / 1000) > 150 ? 'text-gray-900' : 'text-white';
        },
        usageEntry(kind, id) {
            var bucket = kind === 'style' ? 'styles' : 'tokens';
            return (this.usage[bucket] && this.usage[bucket][id]) || { count: 0, sources: [] };
        },
        usageLabel(kind, id) {
            var count = Number(this.usageEntry(kind, id).count || 0);
            return count > 0 ? this.text.used.replace(':count', String(count)) : this.text.unused;
        },
        async mutate(action, input) {
            if (this.busy) return false;
            this.busy = true;
            this.notice = '';
            this.error = '';
            var body = new URLSearchParams();
            body.set('action', action);
            body.set('revision', String(this.state.revision || 0));
            body.set('_token', this.csrf);
            Object.entries(input || {}).forEach(([key, value]) => body.set(key, value === true ? '1' : (value === false ? '0' : String(value ?? ''))));
            try {
                var response = await fetch('/admin/blox_design_api.php', { method: 'POST', body: body });
                var result = await response.json();
                if (!result || Number(result.code) !== 0 || !result.data) throw new Error((result && (result.msg || result.message)) || this.text.failed);
                this.state = result.data;
                this.notice = this.text.saved;
                window.setTimeout(() => { this.notice = ''; }, 2200);
                return true;
            } catch (error) {
                this.error = error && error.message ? error.message : this.text.failed;
                return false;
            } finally {
                this.busy = false;
            }
        },
        async addToken() {
            if (await this.mutate('token_add', this.newToken)) this.newToken = { name: '', category: 'brand', value: '#3b82f6' };
        },
        updateToken(token) { return this.mutate('token_update', token); },
        async addStyle() {
            if (await this.mutate('style_add', this.newStyle)) this.newStyle = { name: '', category: 'general', color: '', background: '', border_color: '', radius: 'none' };
        },
        updateStyle(style) { return this.mutate('style_update', style); },
        toggleLock(kind, item) { return this.mutate(kind + '_lock', { id: item.id, locked: !item.locked }); },
        archiveItem(kind, item) {
            var count = Number(this.usageEntry(kind, item.id).count || 0);
            if (count > 0 && !window.confirm(this.text.archiveUsed.replace(':count', String(count)))) return;
            return this.mutate(kind + '_archive', { id: item.id });
        },
        restoreItem(kind, item) { return this.mutate(kind + '_restore', { id: item.id }); }
    };
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
