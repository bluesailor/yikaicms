(function (global) {
    'use strict';
    function normalize(value) {
        var rows = value && Array.isArray(value.rows) ? value.rows.slice(0, 50).filter(Array.isArray).map(function (row) {
            return row.slice(0, 12).map(function (cell) {
                return ['string', 'number', 'boolean'].includes(typeof cell) ? String(cell).slice(0, 2000) : '';
            });
        }) : [];
        if (!rows.length) rows = [['']];
        var count = Math.max(1, ...rows.map(function (row) { return row.length; }));
        rows.forEach(function (row) { while (row.length < count) row.push(''); });
        var widths = Array.from({length: count}, function (_, i) {
            var n = Number(value && value.widths && value.widths[i]);
            return Number.isFinite(n) && n > 0 ? Math.max(80, Math.min(800, Math.floor(n))) : 0;
        });
        return { rows: rows, widths: widths };
    }
    var methods = {
        openTableCreate(element, target) {
            this.tableCreate = {element: JSON.parse(JSON.stringify(element)), target: target ? JSON.parse(JSON.stringify(target)) : null,
                base: this.historyData(), rows: 3, columns: 3, hoverRows: 0, hoverColumns: 0, style: 'lines', header: true};
            this.focusDialog(this.$refs.tableCreateDialog, 'input');
        },
        closeTableCreate() {
            this.tableCreate = null;
            this.releaseDialog(this.$refs.tableCreateDialog);
        },
        tableCreateSize(rows, columns) {
            if (!this.tableCreate) return;
            var limit = function (value, max) { var n = Number(value); return Number.isFinite(n) ? Math.max(1, Math.min(max, Math.floor(n))) : 1; };
            this.tableCreate.rows = limit(rows, 50);
            this.tableCreate.columns = limit(columns, 12);
        },
        applyTableCreate() {
            var draft = this.tableCreate;
            if (!draft) return;
            if (this.historyData() !== draft.base) { this.toast(this.uiText.saveConflict || this.uiText.previewFailed, 'error'); return; }
            this.tableCreateSize(draft.rows, draft.columns);
            var grid = {rows: Array.from({length: draft.rows}, function () { return Array(draft.columns).fill(''); }), widths: Array(draft.columns).fill(0)};
            draft.element.defaults = Object.assign({}, draft.element.defaults, {grid: grid, header_row: !!draft.header,
                table_style: ['lines', 'striped', 'bordered', 'brand', 'dark'].includes(draft.style) ? draft.style : 'lines'});
            var before = this.historyData();
            this.addElement(draft.element, draft.target, true);
            var inserted = this.historyData() !== before && this.selEl && this.selEl.type === 'table';
            this.closeTableCreate();
            if (inserted) this.$nextTick(() => this.openTableExpanded());
        },
        tableNodeById(id) {
            var node = this.elementAtPath(this.elementPathById(id));
            return node && node.type === 'table' ? node : null;
        },
        gridForTable(node) {
            var control = this.elSchema('table').controls.find(function (item) { return item.key === 'grid'; });
            return normalize(node.data.grid === undefined ? control.default : node.data.grid);
        },
        tableGrid() {
            if (this.tableExpanded) return normalize(this.tableExpanded.grid);
            if (!this.selEl || this.selEl.type !== 'table') return normalize(null);
            return this.gridForTable(this.selEl);
        },
        tableStore(grid, discrete) {
            if (this.tableExpanded) { this.tableExpanded.grid = normalize(grid); return; }
            if (!this.selEl || this.selEl.type !== 'table') return;
            if (discrete) this.flushHistory(true);
            var self = this;
            this.runCommand('edit-table', function () { self.selEl.data.grid = normalize(grid); });
            if (discrete) this.flushHistory(true);
        },
        tableCell(row, col, value) {
            var grid = this.tableGrid();
            if (!grid.rows[row] || col < 0 || col >= grid.widths.length) return;
            grid.rows[row][col] = value;
            this.tableStore(grid, false);
        },
        tableWidth(col, value) {
            var grid = this.tableGrid();
            if (col < 0 || col >= grid.widths.length) return;
            grid.widths[col] = value;
            this.tableStore(grid, true);
        },
        tableAction(axis, action, index) {
            var grid = this.tableGrid(), rowAxis = axis === 'row';
            var count = rowAxis ? grid.rows.length : grid.widths.length;
            index = Math.max(0, Math.min(count - 1, Number(index) || 0));
            if (action === 'add') {
                if (count >= (rowAxis ? 50 : 12)) return index;
                if (rowAxis) grid.rows.splice(index + 1, 0, Array(grid.widths.length).fill(''));
                else {
                    grid.rows.forEach(function (row) { row.splice(index + 1, 0, ''); });
                    grid.widths.splice(index + 1, 0, 0);
                }
                index++;
            } else if (action === 'delete') {
                if (count <= 1) return index;
                if (rowAxis) grid.rows.splice(index, 1);
                else {
                    grid.rows.forEach(function (row) { row.splice(index, 1); });
                    grid.widths.splice(index, 1);
                }
                index = Math.min(index, count - 2);
            } else {
                var to = index + (action === 'previous' ? -1 : action === 'next' ? 1 : 0);
                if (to === index || to < 0 || to >= count) return index;
                var move = function (items) { items.splice(to, 0, items.splice(index, 1)[0]); };
                if (rowAxis) move(grid.rows);
                else { grid.rows.forEach(move); move(grid.widths); }
                index = to;
            }
            this.tableStore(grid, true);
            return index;
        },
        openTableExpanded(id) {
            var node = id ? this.tableNodeById(id) : this.selEl;
            if (!node || node.type !== 'table') return;
            var grid = this.gridForTable(node);
            this.tableExpanded = {id: node.id, base: JSON.stringify(grid), grid: grid, headerRow: [true, 1, '1'].includes(node.data.header_row ?? true), headerColumn: [true, 1, '1'].includes(node.data.header_column)};
            this.focusDialog(this.$refs.tableExpandedDialog, 'textarea');
        },
        closeTableExpanded() {
            this.tableExpanded = null;
            this.releaseDialog(this.$refs.tableExpandedDialog);
        },
        applyTableExpanded() {
            var edit = this.tableExpanded;
            var node = edit && this.tableNodeById(edit.id);
            if (!node || JSON.stringify(this.gridForTable(node)) !== edit.base) {
                this.toast(this.uiText.saveConflict || this.uiText.previewFailed, 'error');
                return;
            }
            var grid = normalize(edit.grid);
            if (JSON.stringify(grid) !== edit.base) {
                this.flushHistory(true);
                this.runCommand('edit-table', function () { node.data.grid = grid; });
                this.flushHistory(true);
            }
            this.closeTableExpanded();
        },
        applyTableCanvasCell(data) {
            var node = this.tableNodeById(data.id);
            if (!node) return;
            var grid = this.gridForTable(node);
            if (!grid.rows[data.row] || grid.rows[data.row][data.column] !== data.base) return;
            grid.rows[data.row][data.column] = data.value;
            node.data.grid = normalize(grid);
        },
        handleTableCanvasAction(data) {
            if (data.action === 'blur') {
                this.tableCanvasEditing = false;
                this.flushHistory(true);
                this.schedulePreview();
                return;
            }
            var node = this.tableNodeById(data.id);
            if (!node || this.tableExpanded) return;
            if (data.action === 'focus') {
                this.flushHistory(true);
                this.tableCanvasEditing = true;
                this.previewClient().cancel();
                return;
            }
            this.tableCanvasEditing = false;
            if (data.action === 'expand') { this.openTableExpanded(data.id); return; }
            this.selectPath(this.elementPathById(data.id), false);
            var parts = data.action.split('-');
            this.tableAction(parts[0], parts[1], parts[0] === 'row' ? data.row : data.column);
            this.schedulePreview();
        },
    };
    global.BloxTableControl = { methods: methods, normalize: normalize };
    if (typeof module !== 'undefined' && module.exports) module.exports = global.BloxTableControl;
})(typeof window === 'undefined' ? globalThis : window);
