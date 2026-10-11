/**
 * 后台列表拖动排序（admin/includes/row_sort.php 输出）。
 *
 * 只能在同一上级的行之间移动。表格是拍平的树：放下后按「上级 → 新顺序」重排整表，下级跟着上级走；
 * 上级不在表里的行当顶层。保存失败就刷新回服务器上的顺序。
 */
(function () {
    'use strict';

    window.ykRowSort = function (tbody, text) {
        if (!tbody || typeof Sortable === 'undefined') return;
        var rows = function () { return Array.prototype.slice.call(tbody.querySelectorAll('tr[data-sort-row]')); };
        var relayout = function () {
            var all = rows();
            var ids = {};
            var byParent = {};
            all.forEach(function (row) { ids[row.dataset.id] = true; });
            all.forEach(function (row) {
                var parent = ids[row.dataset.parent] ? row.dataset.parent : '';
                (byParent[parent] = byParent[parent] || []).push(row);
            });
            var walk = function (parent) {
                (byParent[parent] || []).forEach(function (row) { tbody.appendChild(row); walk(row.dataset.id); });
            };
            walk('');
        };
        new Sortable(tbody, {
            handle: '.yk-row-drag',
            draggable: 'tr[data-sort-row]',
            animation: 150,
            ghostClass: 'opacity-30',
            onMove: function (evt) { return evt.related.dataset.parent === evt.dragged.dataset.parent; },
            onEnd: function (evt) {
                if (evt.oldIndex === evt.newIndex) return;
                var parent = evt.item.dataset.parent;
                relayout();
                var siblings = rows().filter(function (row) { return row.dataset.parent === parent; });
                var fd = new FormData();
                fd.append('action', 'sort');
                siblings.forEach(function (row) { fd.append('ids[]', row.dataset.id); });
                fetch('', { method: 'POST', body: fd })
                    .then(function (response) { return safeJson(response); })
                    .then(function (data) {
                        if (!data || data.code !== 0) throw new Error((data && data.msg) || text.failed);
                        siblings.forEach(function (row, index) {
                            var cell = row.querySelector('[data-sort-cell]');
                            if (cell) cell.textContent = index;
                        });
                        showMessage(text.saved);
                    })
                    .catch(function (err) {
                        showMessage(err.message || text.failed, 'error');
                        setTimeout(function () { location.reload(); }, 800);
                    });
            },
        });
    };
})();
