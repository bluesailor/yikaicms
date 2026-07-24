<?php
/**
 * 「历史版本」面板 —— 文章/单页编辑页引入。
 * 引入前需设置：$revType（'article'|'page'）、$revTargetId（>0 的目标ID）。
 * 目标不存在（新建未保存）时不渲染。数据/恢复走 /admin/revision.php。
 */
declare(strict_types=1);

$revType = $revType ?? '';
$revTargetId = (int) ($revTargetId ?? 0);
if (!in_array($revType, ['article', 'page'], true) || $revTargetId <= 0) {
    return;
}
$revLabels = [
    'none'    => __('revision_none'),
    'fail'    => __('revision_load_fail'),
    'preview' => __('revision_preview'),
    'restore' => __('revision_restore'),
    'confirm' => __('revision_restore_confirm'),
    'ok'      => __('revision_restored'),
    'rfail'   => __('revision_restore_fail'),
    'close'   => __('revision_close'),
    'by'      => __('revision_by'),
];
?>
<div class="bg-white rounded-lg shadow mt-6" id="revPanel"
     data-rev-type="<?php echo e($revType); ?>" data-rev-id="<?php echo (int) $revTargetId; ?>">
    <div class="px-6 py-4 border-b flex items-center justify-between">
        <h2 class="font-bold text-gray-800 flex items-center gap-2">
            <i class="ti ti-history text-base"></i><?php echo __('revision_history'); ?>
        </h2>
        <button type="button" id="revReload" class="text-gray-400 hover:text-primary text-sm inline-flex items-center gap-1">
            <i class="ti ti-refresh"></i>
        </button>
    </div>
    <div class="p-6">
        <p class="text-xs text-gray-400 mb-3"><?php echo __('revision_tip'); ?></p>
        <div id="revList" class="text-sm text-gray-500">…</div>
    </div>
</div>

<!-- 预览弹层 -->
<div id="revPreviewModal" class="fixed inset-0 hidden items-center justify-center" style="z-index:9999;background:rgba(0,0,0,.5)">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl flex flex-col mx-4" style="max-height:calc(100vh - 4rem)">
        <div class="px-5 py-3 border-b flex items-center justify-between">
            <span class="font-medium text-gray-700 text-sm" id="revPvTitle"></span>
            <button type="button" id="revPvClose" class="text-gray-400 hover:text-gray-700"><i class="ti ti-x"></i></button>
        </div>
        <iframe id="revPvFrame" class="w-full flex-1" style="min-height:60vh;border:0"></iframe>
    </div>
</div>

<script>
(function () {
    var panel = document.getElementById('revPanel');
    if (!panel) return;
    var L = <?php echo json_encode($revLabels, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var type = panel.dataset.revType, id = panel.dataset.revId;
    var listEl = document.getElementById('revList');

    function esc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; }

    function load() {
        listEl.textContent = '…';
        fetch('/admin/revision.php?action=list&type=' + encodeURIComponent(type) + '&id=' + encodeURIComponent(id))
            .then(function (r) { return r.json(); })
            .then(function (res) {
                var items = (res && res.data && res.data.items) || [];
                if (!items.length) { listEl.innerHTML = '<span class="text-gray-400">' + esc(L.none) + '</span>'; return; }
                var html = '<div class="divide-y">';
                items.forEach(function (it) {
                    html += '<div class="flex items-center justify-between py-2 gap-3">'
                        + '<div class="min-w-0">'
                        + '<div class="text-gray-700 truncate">' + esc(it.summary || '—') + '</div>'
                        + '<div class="text-xs text-gray-400">' + esc(it.time_text) + (it.admin_name ? ' · ' + esc(L.by) + ' ' + esc(it.admin_name) : '') + '</div>'
                        + '</div>'
                        + '<div class="flex items-center gap-2 shrink-0">'
                        + '<button type="button" class="revPv px-2 py-1 text-xs border rounded hover:bg-gray-50" data-rev="' + it.id + '">' + esc(L.preview) + '</button>'
                        + '<button type="button" class="revRs px-2 py-1 text-xs bg-primary text-white rounded hover:bg-secondary" data-rev="' + it.id + '">' + esc(L.restore) + '</button>'
                        + '</div></div>';
                });
                html += '</div>';
                listEl.innerHTML = html;
            })
            .catch(function () { listEl.innerHTML = '<span class="text-red-400">' + esc(L.fail) + '</span>'; });
    }

    var modal = document.getElementById('revPreviewModal');
    function openPreview(revId) {
        fetch('/admin/revision.php?action=preview&type=' + encodeURIComponent(type) + '&id=' + encodeURIComponent(id) + '&rev_id=' + encodeURIComponent(revId))
            .then(function (r) { return r.json(); })
            .then(function (res) {
                var d = (res && res.data) || {};
                document.getElementById('revPvTitle').textContent = (d.summary || '') + '  ' + (d.time_text || '');
                var frame = document.getElementById('revPvFrame');
                frame.srcdoc = '<!doctype html><meta charset="utf-8"><link rel="stylesheet" href="/assets/css/tailwind.css"><link rel="stylesheet" href="/assets/css/style.css"><base target="_blank"><div style="padding:16px">' + (d.html || '') + '</div>';
                modal.classList.remove('hidden'); modal.classList.add('flex');
            });
    }
    function closePreview() { modal.classList.add('hidden'); modal.classList.remove('flex'); document.getElementById('revPvFrame').srcdoc = ''; }

    function restore(revId, btn) {
        if (!window.confirm(L.confirm)) return;
        btn.disabled = true;
        var fd = new FormData();
        fd.append('action', 'restore'); fd.append('type', type); fd.append('id', id); fd.append('rev_id', revId);
        fetch('/admin/revision.php', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.code === 0) { alert(L.ok); location.reload(); }
                else { alert((res && res.msg) || L.rfail); btn.disabled = false; }
            })
            .catch(function () { alert(L.rfail); btn.disabled = false; });
    }

    listEl.addEventListener('click', function (e) {
        var pv = e.target.closest('.revPv'), rs = e.target.closest('.revRs');
        if (pv) openPreview(pv.dataset.rev);
        if (rs) restore(rs.dataset.rev, rs);
    });
    document.getElementById('revReload').addEventListener('click', load);
    document.getElementById('revPvClose').addEventListener('click', closePreview);
    modal.addEventListener('click', function (e) { if (e.target === modal) closePreview(); });

    load();
})();
</script>
