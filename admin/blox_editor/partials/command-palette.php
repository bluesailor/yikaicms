<?php declare(strict_types=1); ?>
<?php if (!defined('ROOT_PATH')) exit('Access Denied'); ?>
<?php /* 命令面板（Ctrl+K，2.0.4）：方法见 command-palette-methods.php */ ?>
<div x-show="cmdOpen" x-cloak class="fixed inset-0 z-[90] flex items-start justify-center bg-gray-900/30 px-4 pt-[12vh]"
     @mousedown.self="closeCommandPalette()" data-testid="blox-command-palette">
    <div role="dialog" aria-modal="true" :aria-label="cmdText.placeholder"
         class="w-full max-w-xl overflow-hidden rounded-xl border border-gray-200 bg-white shadow-2xl">
        <template x-if="cmdMode === ''">
            <div>
                <div class="flex items-center gap-2 border-b border-gray-100 px-4">
                    <i class="ti ti-search text-gray-400" aria-hidden="true"></i>
                    <input type="text" x-ref="cmdInput" x-model="cmdQuery" @input="cmdIndex = 0" @keydown="commandKeydown($event)"
                           :placeholder="cmdText.placeholder" data-testid="blox-command-input"
                           role="combobox" aria-expanded="true" aria-controls="blox-command-list" aria-autocomplete="list"
                           :aria-activedescendant="commandResults().length ? 'blox-cmd-' + cmdIndex : null"
                           class="h-12 min-w-0 flex-1 border-0 bg-transparent text-sm outline-none focus:ring-0">
                    <kbd class="rounded border border-gray-200 px-1.5 py-0.5 text-[10px] text-gray-400">Esc</kbd>
                </div>
                <ul id="blox-command-list" role="listbox" class="max-h-[50vh] overflow-y-auto py-1 blox-scroll">
                    <template x-for="(item, index) in commandResults()" :key="item.id">
                        <li>
                            <div x-show="index === 0 || commandResults()[index - 1].group !== item.group"
                                 class="px-4 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wide text-gray-400" x-text="cmdText.groups[item.group]"></div>
                            <button type="button" role="option" :id="'blox-cmd-' + index" :aria-selected="index === cmdIndex ? 'true' : 'false'"
                                    @click="runPaletteCommand(item)" @mousemove="cmdIndex = index"
                                    :data-testid="'blox-command-' + item.id"
                                    class="flex w-full items-center gap-3 px-4 py-2 text-left text-sm"
                                    :class="index === cmdIndex ? 'bg-blue-50 text-blue-700' : 'text-gray-700'">
                                <i class="ti shrink-0 text-base" :class="'ti-' + item.icon" aria-hidden="true"></i>
                                <span class="min-w-0 flex-1 truncate" x-text="item.label"></span>
                                <i x-show="item.locked" class="ti ti-lock text-xs text-amber-500" aria-hidden="true"></i>
                            </button>
                        </li>
                    </template>
                    <li x-show="commandResults().length === 0" class="px-4 py-6 text-center text-sm text-gray-400" x-text="cmdText.empty"></li>
                </ul>
                <p class="border-t border-gray-100 px-4 py-2 text-[11px] text-gray-400" x-text="cmdText.hint"></p>
            </div>
        </template>
        <template x-if="cmdMode === 'replace'">
            <form class="space-y-3 p-4" @submit.prevent="applyReplace()" @keydown.escape.prevent="closeCommandPalette()" data-testid="blox-command-replace">
                <p class="text-sm font-semibold text-gray-800" x-text="cmdText.replace"></p>
                <label class="block text-xs text-gray-500"><span x-text="cmdText.find"></span>
                    <input type="text" x-ref="cmdFind" x-model="cmdFind" data-testid="blox-command-find"
                           class="mt-1 w-full rounded border border-gray-200 px-2 py-1.5 text-sm"></label>
                <label class="block text-xs text-gray-500"><span x-text="cmdText.replaceWith"></span>
                    <input type="text" x-model="cmdReplace" data-testid="blox-command-replace-with"
                           class="mt-1 w-full rounded border border-gray-200 px-2 py-1.5 text-sm"></label>
                <div class="flex items-center justify-between gap-3">
                    <span class="text-xs text-gray-500" data-testid="blox-command-replace-count" x-text="cmdText.replaceCount.replace(':n', replaceMatchCount())"></span>
                    <button type="submit" :disabled="!cmdFind || replaceMatchCount() === 0" data-testid="blox-command-replace-apply"
                            class="rounded bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-500 disabled:opacity-40" x-text="cmdText.replaceApply"></button>
                </div>
            </form>
        </template>
    </div>
</div>
