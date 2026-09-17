<?php declare(strict_types=1); ?>
<?php if (!defined('ROOT_PATH')) exit('Access Denied'); ?>
<?php // BLOX Pro 作者端模块：表格网格编辑（由核心 table-expanded 弹窗经 blox_editor_panel('table_grid') 挂载） ?>
<div data-testid="blox-table-editor" class="min-w-0 space-y-2" x-data="{ tableRow: 0, tableCol: 0 }"
     x-effect="tableRow = Math.min(tableRow, tableGrid().rows.length - 1); tableCol = Math.min(tableCol, tableGrid().widths.length - 1)">
    <?php foreach (['row' => 'tableRow', 'column' => 'tableCol'] as $axis => $selection):
        $count = $axis === 'row' ? 'tableGrid().rows.length' : 'tableGrid().widths.length';
        $limit = $axis === 'row' ? 50 : 12;
    ?>
    <div class="flex items-center gap-1 max-w-md">
        <label class="text-xs text-gray-600 shrink-0"><?= e(__('blox_table_' . $axis)) ?></label>
        <select x-model.number="<?= e($selection) ?>" aria-label="<?= e(__('blox_table_' . $axis)) ?>" data-testid="blox-table-<?= e($axis) ?>-select"
                class="min-w-0 flex-1 h-8 rounded border border-gray-300 bg-white px-1 text-xs">
            <template x-for="n in <?= e($count) ?>" :key="n"><option :value="n - 1" x-text="n"></option></template>
        </select>
        <?php foreach (['add' => 'plus', 'previous' => ($axis === 'row' ? 'arrow-up' : 'arrow-left'), 'next' => ($axis === 'row' ? 'arrow-down' : 'arrow-right'), 'delete' => 'trash'] as $action => $icon):
            $disabled = match ($action) {
                'add' => $count . ' >= ' . $limit,
                'delete' => $count . ' <= 1',
                'previous' => $selection . ' <= 0',
                default => $selection . ' >= ' . $count . ' - 1',
            };
        ?>
        <button type="button" @click="<?= e($selection) ?> = tableAction('<?= e($axis) ?>', '<?= e($action) ?>', <?= e($selection) ?>)"
                :disabled="<?= e($disabled) ?>" data-testid="blox-table-<?= e($axis . '-' . $action) ?>"
                title="<?= e(__('blox_table_' . $axis . '_' . $action)) ?>" aria-label="<?= e(__('blox_table_' . $axis . '_' . $action)) ?>"
                class="w-8 h-8 shrink-0 rounded border border-gray-200 text-gray-600 hover:bg-blue-50 hover:text-blue-600 disabled:opacity-30 disabled:cursor-not-allowed inline-flex items-center justify-center">
            <i class="ti ti-<?= e($icon) ?>" aria-hidden="true"></i>
        </button>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <div class="max-w-full max-h-[60vh] overflow-auto rounded border border-gray-300" data-testid="blox-table-grid">
        <table class="w-full border-collapse text-xs">
            <tbody>
                <template x-for="(row, ri) in tableGrid().rows" :key="ri">
                    <tr>
                        <template x-for="(cell, ci) in row" :key="ci">
                            <td class="border border-gray-200 p-0" :class="ri === 0 && tableExpanded.headerRow ? 'bg-gray-100 font-semibold sticky top-0 z-10' : (ci === 0 && tableExpanded.headerColumn ? 'bg-gray-100 font-semibold' : 'bg-white')">
                                <textarea :value="cell" @input="tableCell(ri, ci, $event.target.value)" @focus="tableRow = ri; tableCol = ci" @change="flushHistory(true)"
                                          :aria-label="<?= e(json_encode(__('blox_table_cell'), JSON_UNESCAPED_UNICODE)) ?>.replace(':row', ri + 1).replace(':column', ci + 1)"
                                          :data-testid="'blox-table-cell-' + ri + '-' + ci" rows="2" maxlength="2000"
                                          class="block w-full min-w-[112px] resize-y bg-transparent p-2 text-xs leading-5 text-gray-700 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-blue-500"></textarea>
                            </td>
                        </template>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
    <label class="flex items-center gap-2 max-w-md text-xs text-gray-600">
        <span class="flex-1"><?= e(__('blox_table_width')) ?> <span x-text="tableCol + 1"></span></span>
        <input type="number" min="0" max="800" step="10" :value="tableGrid().widths[tableCol]" @change="tableWidth(tableCol, $event.target.value)"
               data-testid="blox-table-column-width" class="w-20 rounded border border-gray-300 px-2 py-1.5">
    </label>
</div>
