<?php
declare(strict_types=1);
?>
<div x-show="tableCreate" x-cloak x-ref="tableCreateDialog" tabindex="-1"
     @keydown="dialogKeydown($event, $refs.tableCreateDialog, () => closeTableCreate())"
     role="dialog" aria-modal="true" aria-labelledby="blox-table-create-title" data-testid="blox-table-create"
     class="fixed inset-0 z-[160] flex items-center justify-center p-2 bg-black/50">
    <section class="w-full max-w-lg max-h-[96vh] flex flex-col rounded-lg bg-white shadow-xl">
        <header class="flex shrink-0 items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
            <h2 id="blox-table-create-title" class="text-base font-semibold text-gray-900"><?= e(__('blox_table_create')) ?></h2>
            <button type="button" @click="closeTableCreate()" aria-label="<?= e(__('cancel')) ?>" title="<?= e(__('cancel')) ?>" class="w-9 h-9 inline-flex items-center justify-center rounded text-gray-600 hover:bg-gray-100"><i class="ti ti-x" aria-hidden="true"></i></button>
        </header>
        <template x-if="tableCreate">
            <div class="min-h-0 overflow-y-auto p-4 space-y-4">
                <div class="grid grid-cols-2 gap-3">
                    <label class="text-sm text-gray-700"><?= e(__('blox_table_rows')) ?>
                        <input type="number" min="1" max="50" step="1" :value="tableCreate.rows" @change="tableCreateSize($event.target.value, tableCreate.columns)" class="mt-1 w-full rounded border border-gray-300 px-3 py-2">
                    </label>
                    <label class="text-sm text-gray-700"><?= e(__('blox_table_columns')) ?>
                        <input type="number" min="1" max="12" step="1" :value="tableCreate.columns" @change="tableCreateSize(tableCreate.rows, $event.target.value)" class="mt-1 w-full rounded border border-gray-300 px-3 py-2">
                    </label>
                </div>
                <div class="space-y-2">
                    <output class="block text-sm font-medium text-gray-900" x-text="<?= e(json_encode(__('blox_table_size_label'))) ?>.replace(':rows', tableCreate.hoverRows || tableCreate.rows).replace(':columns', tableCreate.hoverColumns || tableCreate.columns)"></output>
                    <div class="grid gap-1 w-full max-w-[332px]" style="grid-template-columns:repeat(12,minmax(0,1fr))" @mouseleave="tableCreate.hoverRows = 0; tableCreate.hoverColumns = 0">
                        <template x-for="cell in 72" :key="cell">
                            <button type="button" class="aspect-square min-w-0 rounded-sm border focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-600"
                                :class="Math.ceil(cell / 12) <= (tableCreate.hoverRows || tableCreate.rows) && (cell - 1) % 12 + 1 <= (tableCreate.hoverColumns || tableCreate.columns) ? 'bg-blue-100 border-blue-600' : 'bg-white border-gray-300'"
                                :aria-label="<?= e(json_encode(__('blox_table_size_label'))) ?>.replace(':rows', Math.ceil(cell / 12)).replace(':columns', (cell - 1) % 12 + 1)"
                                :aria-pressed="tableCreate.rows === Math.ceil(cell / 12) && tableCreate.columns === (cell - 1) % 12 + 1"
                                @mouseenter="tableCreate.hoverRows = Math.ceil(cell / 12); tableCreate.hoverColumns = (cell - 1) % 12 + 1"
                                @click="tableCreateSize(Math.ceil(cell / 12), (cell - 1) % 12 + 1)"></button>
                        </template>
                    </div>
                </div>
                <fieldset>
                    <legend class="mb-2 text-sm text-gray-700"><?= e(__('blox_table_style')) ?></legend>
                    <div class="grid grid-cols-3 gap-2">
                        <?php foreach (['lines', 'bordered', 'striped', 'brand', 'dark'] as $preset): ?>
                        <label class="relative flex min-w-0 cursor-pointer flex-col items-center gap-2 rounded border p-2" :class="tableCreate.style === '<?= e($preset) ?>' ? 'border-blue-600 bg-blue-50' : 'border-gray-300 bg-white'">
                            <input type="radio" name="table-create-style" value="<?= e($preset) ?>" x-model="tableCreate.style" class="absolute top-2 right-2 accent-blue-600" aria-label="<?= e(__('blox_table_' . $preset)) ?>">
                            <table aria-hidden="true" class="w-full mt-5" style="border-collapse:collapse;table-layout:fixed">
                                <?php for ($r = 0; $r < 3; $r++): ?><tr>
                                    <?php for ($c = 0; $c < 3; $c++): ?><td style="<?= $r === 0 && $preset === 'brand' ? 'background:var(--yk-color-primary,#2563eb)' : ($r === 0 && $preset === 'dark' ? 'background:#1f2937' : '') ?>" class="h-3 <?= $preset === 'bordered' ? 'border border-gray-400' : 'border-b border-gray-300' ?> <?= $r === 0 || ($preset === 'striped' && $r === 2) ? 'bg-gray-200' : 'bg-white' ?>"></td><?php endfor; ?>
                                </tr><?php endfor; ?>
                            </table>
                            <span class="text-xs text-gray-800 text-center"><?= e(__('blox_table_' . $preset)) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <label class="flex items-center gap-2 text-sm text-gray-700"><input type="checkbox" x-model="tableCreate.header" class="accent-blue-600"><?= e(__('blox_table_header_row')) ?></label>
            </div>
        </template>
        <footer class="flex shrink-0 justify-end gap-2 border-t border-gray-200 px-4 py-3">
            <button type="button" @click="closeTableCreate()" class="rounded border border-gray-300 px-4 py-2 text-sm text-gray-700"><?= e(__('cancel')) ?></button>
            <button type="button" @click="applyTableCreate()" class="inline-flex items-center gap-2 rounded bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700"><i class="ti ti-plus" aria-hidden="true"></i><?= e(__('blox_table_create')) ?></button>
        </footer>
    </section>
</div>
