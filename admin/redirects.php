<?php
/**
 * YikaiCMS - 301 跳转管理（核心免费版，2.0.4）
 *
 * 规则存在网址登记表与 metas（见 includes/Redirects.php），前台由登记网址分发直接发出跳转。
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    if (!productRouteModel()->available()) error(__('product_url_upgrade'));

    if ($action === 'save') {
        $id = postInt('id');
        try {
            $id = Redirects::save($id, (string) post('source'), (string) post('target'), postInt('code', 301));
        } catch (InvalidArgumentException $e) {
            error(__($e->getMessage()));
        }
        adminLog('redirect', 'save', '保存跳转：' . post('source') . ' → ' . post('target'));
        success(['id' => $id]);
    }

    if ($action === 'delete') {
        $id = postInt('id');
        Redirects::delete($id);
        adminLog('redirect', 'delete', '删除跳转 ID：' . $id);
        success();
    }

    if ($action === 'import') {
        $result = Redirects::import((string) ($_POST['lines'] ?? ''));
        $errors = array_map(static fn (array $e): array => [
            'line' => __('redirect_line', ['line' => (string) $e['line']]), 'text' => $e['text'], 'error' => __($e['error']),
        ], $result['errors']);
        adminLog('redirect', 'import', '批量导入跳转：' . $result['saved'] . ' 条成功，' . count($errors) . ' 条失败');
        success(['saved' => $result['saved'], 'errors' => $errors]);
    }

    error(__('admin_fail'));
}

$search = trim((string) get('q', ''));
$page = max(1, getInt('page', 1));
$perPage = 50;
$available = productRouteModel()->available();
$total = $available ? Redirects::count($search) : 0;
$rows = $available ? Redirects::list($search, $perPage, ($page - 1) * $perPage) : [];
$totalPages = max(1, (int) ceil($total / $perPage));
$pageLink = static fn (int $p): string => '/admin/redirects.php?' . http_build_query(array_filter(['q' => $search, 'page' => $p > 1 ? $p : null]));

$pageTitle = __('admin_redirects');
$currentMenu = 'redirects';
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-gray-500 max-w-3xl"><?php echo e(__('redirect_hint')); ?></p>
        <div class="flex gap-2">
            <button type="button" onclick="openImportModal()" class="border px-4 py-2 rounded hover:bg-gray-100 inline-flex items-center gap-1" data-testid="redirect-import-open">
                <i class="ti ti-file-import text-base"></i><?php echo e(__('redirect_import')); ?>
            </button>
            <button type="button" onclick="openEditModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1" data-testid="redirect-add">
                <i class="ti ti-plus text-base"></i><?php echo e(__('redirect_add')); ?>
            </button>
        </div>
    </div>
    <form method="get" class="px-4 pb-4 flex flex-wrap items-center gap-2">
        <input type="search" name="q" value="<?php echo e($search); ?>" placeholder="<?php echo e(__('redirect_search_ph')); ?>"
               aria-label="<?php echo e(__('redirect_search_ph')); ?>" class="border rounded px-3 py-2 w-72 max-w-full">
        <button type="submit" class="border px-3 py-2 rounded hover:bg-gray-100"><i class="ti ti-search"></i></button>
        <span class="text-sm text-gray-500"><?php echo e(__('redirect_total', ['count' => (string) $total])); ?></span>
    </form>
</div>

<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="w-full" data-testid="redirect-table">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo e(__('redirect_source')); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo e(__('redirect_target')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('redirect_code')); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo e(__('redirect_last_hit')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('admin_action')); ?></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($rows as $row): ?>
                <?php $sourceShown = rawurldecode($row['source']); ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-mono text-sm break-all"><?php echo e($sourceShown); ?></td>
                    <td class="px-4 py-3 font-mono text-sm break-all"><?php echo e($row['target']); ?></td>
                    <td class="px-4 py-3 text-center text-sm"><?php echo (int) $row['code']; ?></td>
                    <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap"><?php echo $row['last_hit'] > 0 ? e(date('Y-m-d H:i', $row['last_hit'])) : e(__('redirect_never')); ?></td>
                    <td class="px-4 py-3 text-center whitespace-nowrap">
                        <a href="<?php echo e($row['source']); ?>" target="_blank" rel="noopener" class="text-gray-600 hover:underline text-sm mr-2 inline-flex items-center gap-1">
                            <i class="ti ti-external-link text-sm"></i><?php echo e(__('redirect_open')); ?></a>
                        <button type="button" onclick='openEditModal(<?php echo json_encode(['id' => $row['id'], 'source' => $sourceShown, 'target' => $row['target'], 'code' => $row['code']], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>)'
                                class="text-primary hover:underline text-sm mr-2 inline-flex items-center gap-1">
                            <i class="ti ti-pencil text-sm"></i><?php echo e(__('admin_edit')); ?></button>
                        <button type="button" onclick="deleteRedirect(<?php echo (int) $row['id']; ?>)"
                                class="text-red-600 hover:underline text-sm inline-flex items-center gap-1">
                            <i class="ti ti-trash text-sm"></i><?php echo e(__('admin_delete')); ?></button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500"><?php echo e($available ? __('redirect_empty') : __('product_url_upgrade')); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="p-4 flex flex-wrap justify-center gap-2">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <a href="<?php echo e($pageLink($i)); ?>" class="px-3 py-1 border rounded text-sm <?php echo $i === $page ? 'bg-primary text-white border-primary' : 'hover:bg-gray-100'; ?>"><?php echo $i; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php /* 编辑弹窗 */ ?>
<div id="editModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeModals()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow-xl w-full max-w-lg">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h3 class="font-bold text-gray-800" id="modalTitle"><?php echo e(__('redirect_add')); ?></h3>
            <button type="button" onclick="closeModals()" class="text-gray-400 hover:text-gray-600 hover:bg-gray-100 text-xl leading-none w-8 h-8 rounded-full flex items-center justify-center transition-colors">&times;</button>
        </div>
        <form id="editForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="editId" value="0">
            <div>
                <label for="editSource" class="block text-gray-700 mb-1"><?php echo e(__('redirect_source')); ?> <span class="text-red-500">*</span></label>
                <input type="text" name="source" id="editSource" required maxlength="1500" class="w-full border rounded px-4 py-2 font-mono" placeholder="/old-page/">
                <p class="text-xs text-gray-400 mt-1"><?php echo e(__('redirect_source_hint')); ?></p>
            </div>
            <div>
                <label for="editTarget" class="block text-gray-700 mb-1"><?php echo e(__('redirect_target')); ?> <span class="text-red-500">*</span></label>
                <input type="text" name="target" id="editTarget" required maxlength="1000" class="w-full border rounded px-4 py-2 font-mono" placeholder="/new-page/">
                <p class="text-xs text-gray-400 mt-1"><?php echo e(__('redirect_target_hint')); ?></p>
            </div>
            <div>
                <label for="editCode" class="block text-gray-700 mb-1"><?php echo e(__('redirect_code')); ?></label>
                <select name="code" id="editCode" class="w-full border rounded px-4 py-2">
                    <option value="301"><?php echo e(__('redirect_301')); ?></option>
                    <option value="302"><?php echo e(__('redirect_302')); ?></option>
                </select>
                <p class="text-xs text-gray-400 mt-1"><?php echo e(__('redirect_code_hint')); ?></p>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeModals()" class="border px-4 py-2 rounded hover:bg-gray-100"><?php echo e(__('admin_cancel')); ?></button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded inline-flex items-center gap-1">
                    <i class="ti ti-check text-base"></i><?php echo e(__('admin_save')); ?></button>
            </div>
        </form>
    </div>
</div>

<?php /* 批量导入弹窗 */ ?>
<div id="importModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeModals()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h3 class="font-bold text-gray-800"><?php echo e(__('redirect_import')); ?></h3>
            <button type="button" onclick="closeModals()" class="text-gray-400 hover:text-gray-600 hover:bg-gray-100 text-xl leading-none w-8 h-8 rounded-full flex items-center justify-center transition-colors">&times;</button>
        </div>
        <form id="importForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="import">
            <p class="text-sm text-gray-500"><?php echo e(__('redirect_import_hint')); ?></p>
            <textarea name="lines" id="importLines" rows="12" class="w-full border rounded px-3 py-2 font-mono text-sm"
                      aria-label="<?php echo e(__('redirect_import')); ?>" placeholder="/old-page/ /new-page/&#10;/product/gear/ /product/worm-gear/ 301"></textarea>
            <div id="importResult" class="text-sm hidden" data-testid="redirect-import-result"></div>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeModals()" class="border px-4 py-2 rounded hover:bg-gray-100"><?php echo e(__('admin_cancel')); ?></button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded inline-flex items-center gap-1">
                    <i class="ti ti-file-import text-base"></i><?php echo e(__('redirect_import_submit')); ?></button>
            </div>
        </form>
    </div>
</div>

<script>
const REDIRECT_I18N = <?php echo json_encode([
    'add' => __('redirect_add'), 'edit' => __('redirect_edit'), 'saved' => __('admin_saved'), 'deleted' => __('admin_deleted'),
    'confirmDelete' => __('admin_confirm_delete'), 'imported' => __('redirect_import_result'), 'importErrors' => __('redirect_import_errors'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;

function openEditModal(item = null) {
    document.getElementById('modalTitle').textContent = item ? REDIRECT_I18N.edit : REDIRECT_I18N.add;
    document.getElementById('editId').value = item?.id || 0;
    document.getElementById('editSource').value = item?.source || '';
    document.getElementById('editTarget').value = item?.target || '';
    document.getElementById('editCode').value = String(item?.code || 301);
    document.getElementById('editModal').classList.remove('hidden');
    document.getElementById('editSource').focus();
}

function openImportModal() {
    document.getElementById('importResult').classList.add('hidden');
    document.getElementById('importModal').classList.remove('hidden');
    document.getElementById('importLines').focus();
}

function closeModals() {
    document.getElementById('editModal').classList.add('hidden');
    document.getElementById('importModal').classList.add('hidden');
}

document.getElementById('editForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const data = await safeJson(await fetch('', { method: 'POST', body: new FormData(this) }));
    if (data.code === 0) {
        showMessage(REDIRECT_I18N.saved);
        setTimeout(() => location.reload(), 600);
    } else {
        showMessage(data.msg, 'error');
    }
});

document.getElementById('importForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const data = await safeJson(await fetch('', { method: 'POST', body: new FormData(this) }));
    const box = document.getElementById('importResult');
    box.replaceChildren();
    if (data.code !== 0) { showMessage(data.msg, 'error'); return; }
    const summary = document.createElement('p');
    summary.className = 'text-green-700 font-medium';
    summary.textContent = REDIRECT_I18N.imported.replace(':saved', String(data.data.saved));
    box.appendChild(summary);
    if (data.data.errors.length) {
        const head = document.createElement('p');
        head.className = 'text-red-600 mt-2';
        head.textContent = REDIRECT_I18N.importErrors.replace(':count', String(data.data.errors.length));
        const list = document.createElement('ul');
        list.className = 'mt-1 space-y-1 text-red-600';
        for (const err of data.data.errors) {
            const li = document.createElement('li');
            li.textContent = err.line + '：' + err.error + (err.text ? '（' + err.text + '）' : '');
            list.appendChild(li);
        }
        box.append(head, list);
    }
    box.classList.remove('hidden');
    if (data.data.saved > 0 && !data.data.errors.length) setTimeout(() => location.reload(), 1200);
});

async function deleteRedirect(id) {
    if (!confirm(REDIRECT_I18N.confirmDelete)) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    const data = await safeJson(await fetch('', { method: 'POST', body: formData }));
    if (data.code === 0) {
        showMessage(REDIRECT_I18N.deleted);
        setTimeout(() => location.reload(), 600);
    } else {
        showMessage(data.msg, 'error');
    }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
