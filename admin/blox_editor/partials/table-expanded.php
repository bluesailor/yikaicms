<?php
declare(strict_types=1);
?>
<div x-show="tableExpanded" x-cloak x-ref="tableExpandedDialog" tabindex="-1"
     @keydown="dialogKeydown($event, $refs.tableExpandedDialog, () => closeTableExpanded())"
     role="dialog" aria-modal="true" aria-labelledby="blox-table-expanded-title" data-testid="blox-table-expanded"
     class="fixed inset-0 z-[145] flex items-center justify-center p-2 md:p-4 bg-black/50">
    <section class="w-full max-w-[1600px] max-h-[96vh] flex flex-col rounded-lg bg-white shadow-xl">
        <header class="flex shrink-0 items-center justify-between gap-3 border-b border-gray-200 px-4 py-3">
            <h2 id="blox-table-expanded-title" class="text-base font-semibold text-gray-900"><?= e(__('blox_table_expand')) ?></h2>
            <button type="button" @click="closeTableExpanded()" title="<?= e(__('cancel')) ?>" aria-label="<?= e(__('cancel')) ?>"
                    class="w-9 h-9 inline-flex items-center justify-center rounded text-gray-600 hover:bg-gray-100"><i class="ti ti-x" aria-hidden="true"></i></button>
        </header>
        <div class="min-h-0 overflow-y-auto p-4">
            <template x-if="tableExpanded"><?php require __DIR__ . '/table-control.php'; ?></template>
        </div>
        <footer class="flex shrink-0 justify-end gap-2 border-t border-gray-200 px-4 py-3">
            <button type="button" @click="closeTableExpanded()" class="rounded border border-gray-300 px-4 py-2 text-sm text-gray-700"><?= e(__('cancel')) ?></button>
            <button type="button" @click="applyTableExpanded()" data-testid="blox-table-apply" class="inline-flex items-center gap-2 rounded bg-blue-600 px-4 py-2 text-sm text-white hover:bg-blue-700"><i class="ti ti-check" aria-hidden="true"></i><?= e(__('blox_table_apply')) ?></button>
        </footer>
    </section>
</div>
