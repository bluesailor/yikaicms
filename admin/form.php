<?php
/**
 * YikaiCMS - 表单管理
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('form');

$statusLabels = [
    0 => __('inq_status_new'),
    1 => __('inq_status_contacted'),
    2 => __('inq_status_following'),
    3 => __('inq_status_won'),
    4 => __('inq_status_lost'),
];

$statusColors = [
    0 => 'bg-blue-100 text-blue-600',
    1 => 'bg-yellow-100 text-yellow-600',
    2 => 'bg-purple-100 text-purple-600',
    3 => 'bg-green-100 text-green-600',
    4 => 'bg-gray-100 text-gray-500',
];

// 处理 AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if (in_array($action, ['ip_info', 'block_ip', 'unblock_ip'], true)) {
        try {
            if ($action === 'ip_info') success(formModerationModel()->inspect(postInt('id')));
            if ($action === 'unblock_ip') {
                if (!is_string($_POST['ip'] ?? null)) error(__('form_ip_invalid'), 400);
                $ip = $_POST['ip'];
                formModerationModel()->unblock($ip);
                adminLog('form', 'unblock_ip', 'Form IP unblocked: ' . $ip);
                success([], __('form_ip_unblocked'));
            }
            $delete = $_POST['delete_messages'] ?? '0';
            if (!in_array($delete, ['0', '1'], true)) error(__('form_ip_invalid'), 400);
            $result = formModerationModel()->blockFromForm(postInt('id'), $delete === '1', postInt('expected_count'), (int) $_SESSION['admin_id']);
            adminLog('form', 'block_ip', 'Form IP blocked: ' . $result['ip'] . '; deleted: ' . $result['deleted']);
            success($result, __('form_ip_done', ['count' => $result['deleted']]));
        } catch (RuntimeException $error) {
            $key = $error->getMessage();
            if (in_array($key, ['form_ip_missing', 'form_ip_invalid', 'form_ip_changed'], true)) error(__($key), $key === 'form_ip_changed' ? 409 : 400);
            error(__('form_ip_failed'), 500);
        }
    }

    if ($action === 'update_status') {
        $id = postInt('id');
        $status = postInt('status');
        $note = post('note');

        formModel()->updateById($id, [
            'status' => $status,
            'follow_admin' => $_SESSION['admin_id'],
            'follow_note' => $note,
        ]);

        adminLog('form', 'update_status', "处理表单ID: $id");
        success();
    }

    if ($action === 'delete') {
        $id = postInt('id');
        formModel()->deleteById($id);
        adminLog('form', 'delete', "删除表单ID: $id");
        success();
    }

    if ($action === 'batch_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!empty($ids)) {
            formModel()->deleteByIds($ids);
            adminLog('form', 'batch_delete', '批量删除：' . implode(',', $ids));
        }
        success();
    }

    exit;
}

// 自动弹窗查看
$viewId = getInt('view');
$viewItem = null;
if ($viewId) {
    $viewItem = formModel()->find($viewId);
}

// 查询参数
$type = get('type');
$status = get('status', '');
$source = get('source', '');
$keyword = get('keyword');
$page = max(1, getInt('page', 1));
$perPage = 20;

$offset = ($page - 1) * $perPage;
$filters = array_filter(['type' => $type, 'status' => $status, 'source' => $source, 'keyword' => $keyword], fn($v) => $v !== '');

// 状态统计
$statusCounts = formModel()->getStatusCounts();
$totalAll = array_sum($statusCounts);
$result = formModel()->getList($filters, $perPage, $offset);
$total = $result['total'];
$forms = $result['items'];
$blockedIps = formModerationModel()->blockedIps();

$pageTitle = __('admin_form');
$currentMenu = 'form';

require_once ROOT_PATH . '/admin/includes/header.php';
require ROOT_PATH . '/admin/includes/workflow_nav.php';
?>


<!-- 状态快捷筛选 -->
<div class="flex gap-2 mb-4 flex-wrap">
    <a href="?<?php echo $source ? 'source=' . e($source) . '&' : ''; ?>"
       class="px-3 py-1.5 text-sm rounded-lg <?php echo $status === '' ? 'bg-gray-800 text-white' : 'bg-white text-gray-600 border hover:bg-gray-50'; ?>">
        <?php echo __('all'); ?> <span class="text-xs opacity-70">(<?php echo $totalAll; ?>)</span>
    </a>
    <?php foreach ($statusLabels as $k => $v): ?>
    <a href="?status=<?php echo $k; ?><?php echo $source ? '&source=' . e($source) : ''; ?>"
       class="px-3 py-1.5 text-sm rounded-lg <?php echo $status === (string)$k ? 'bg-gray-800 text-white' : 'bg-white text-gray-600 border hover:bg-gray-50'; ?>">
        <?php echo $v; ?> <span class="text-xs opacity-70">(<?php echo $statusCounts[$k] ?? 0; ?>)</span>
    </a>
    <?php endforeach; ?>
</div>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex flex-wrap gap-4 items-center">
        <form class="flex flex-wrap gap-3 items-center">
            <select name="source" class="border rounded px-3 py-2">
                <option value=""><?php echo __('inq_filter_all_src'); ?></option>
                <option value="product" <?php echo $source === 'product' ? 'selected' : ''; ?>><?php echo __('inq_source_product'); ?></option>
                <option value="contact" <?php echo $source === 'contact' ? 'selected' : ''; ?>><?php echo __('inq_source_contact'); ?></option>
            </select>

            <?php if ($status !== ''): ?>
            <input type="hidden" name="status" value="<?php echo e($status); ?>">
            <?php endif; ?>

            <input type="text" name="keyword" value="<?php echo e($keyword); ?>"
                   class="border rounded px-3 py-2" placeholder="<?php echo __('admin_search'); ?>...">

            <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <i class="ti ti-search text-base"></i>
                <?php echo __('admin_filter'); ?>
            </button>
        </form>
    </div>
</div>

<!-- 列表 -->
<details class="bg-white rounded-lg shadow mb-6 p-4" data-testid="form-ip-blocklist">
    <summary class="cursor-pointer font-medium"><?= e(__('form_ip_list')) ?> (<?= count($blockedIps) ?>)</summary>
    <p class="text-sm text-gray-500 my-3"><?= e(__('form_ip_scope')) ?></p>
    <?php foreach ($blockedIps as $blocked): ?>
    <div class="flex items-center justify-between gap-3 border-t py-2">
        <span class="text-sm break-all"><?= e($blocked['ip']) ?></span>
        <button type="button" class="text-primary text-sm shrink-0" onclick="unblockFormIp(<?= e(json_encode($blocked['ip'])) ?>)"><?= e(__('form_ip_unblock')) ?></button>
    </div>
    <?php endforeach; ?>
</details>
<div class="bg-white rounded-lg shadow">
    <form id="listForm">
        <div class="overflow-x-auto">
        <table class="w-full admin-workflow-table">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left">
                            <input type="checkbox" id="checkAll">
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('label_source'); ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_product'); ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('inq_th_name'); ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('inq_th_phone'); ?></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('inq_th_content'); ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_status'); ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_created_at'); ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_action'); ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($forms as $item): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <input type="checkbox" name="ids[]" value="<?php echo $item['id']; ?>">
                        </td>
                        <td class="px-4 py-3">
                            <?php $itemSource = $item['source'] ?? 'contact'; ?>
                            <span class="text-xs px-2 py-1 rounded <?php echo $itemSource === 'product' ? 'bg-orange-100 text-orange-600' : 'bg-blue-100 text-blue-600'; ?>">
                                <?php echo $itemSource === 'product' ? __('inq_source_product') : __('inq_source_contact'); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm max-w-[150px] truncate">
                            <?php if (!empty($item['product_id']) && (int)$item['product_id'] > 0): ?>
                            <a href="/product/<?php echo (int)$item['product_id']; ?>.html" target="_blank" class="text-primary hover:underline" title="<?php echo e($item['product_title'] ?? ''); ?>">
                                <?php echo e(cutStr($item['product_title'] ?? '', 20)); ?>
                            </a>
                            <?php else: ?>
                            <span class="text-gray-400">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 font-medium"><?php echo e($item['name']); ?></td>
                        <td class="px-4 py-3"><?php echo e($item['phone']); ?></td>
                        <td class="px-4 py-3 text-sm text-gray-500 max-w-xs truncate">
                            <?php echo e(cutStr($item['content'] ?? '', 50)); ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="text-xs px-2 py-1 rounded <?php echo $statusColors[(int)$item['status']] ?? ''; ?>">
                                <?php echo $statusLabels[(int)$item['status']] ?? '-'; ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-gray-500">
                            <?php echo date('Y-m-d H:i', (int)$item['created_at']); ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <button type="button" onclick="showDetail(<?php echo htmlspecialchars(json_encode($item, JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES); ?>)"
                                    class="text-primary hover:underline text-sm mr-2 inline-flex items-center gap-1">
                                <i class="ti ti-eye text-sm"></i>
                                <?php echo __('inq_btn_view'); ?></button>
                            <button type="button" onclick="deleteForm(<?php echo $item['id']; ?>)"
                                    class="text-red-600 hover:underline text-sm inline-flex items-center gap-1">
                                <i class="ti ti-trash text-sm"></i>
                                <?php echo __('admin_delete'); ?></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($forms)): ?>
                    <tr>
                        <td colspan="9" class="px-4 py-8 text-center text-gray-500"><?php echo __('admin_no_data'); ?></td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="px-6 py-4 border-t flex flex-wrap gap-4 items-center justify-between">
            <div class="flex gap-2">
                <button type="button" onclick="batchDelete()" class="border px-3 py-1 rounded text-sm hover:bg-gray-100 inline-flex items-center gap-1">
                    <i class="ti ti-trash text-base"></i>
                    <?php echo __('admin_batch_delete'); ?>
                </button>
            </div>

            <?php if ($total > $perPage): ?>
            <div class="flex items-center gap-2">
                <span class="text-sm text-gray-500"><?php echo sprintf(__('list_total'), $total); ?></span>
                <?php
                $totalPages = ceil($total / $perPage);
                $queryString = http_build_query(array_filter(['type' => $type, 'status' => $status, 'source' => $source, 'keyword' => $keyword]));
                $baseUrl = '?' . ($queryString ? $queryString . '&' : '');
                ?>
                <?php if ($page > 1): ?>
                <a href="<?php echo $baseUrl; ?>page=<?php echo $page - 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-100 inline-flex items-center gap-1">
                    <i class="ti ti-chevron-left text-base"></i>
                    <?php echo __('list_prev_page'); ?></a>
                <?php endif; ?>
                <span class="text-sm"><?php echo sprintf(__('inq_page_x_of_y'), $page, $totalPages); ?></span>
                <?php if ($page < $totalPages): ?>
                <a href="<?php echo $baseUrl; ?>page=<?php echo $page + 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-100 inline-flex items-center gap-1">
                    <?php echo __('list_next_page'); ?>
                    <i class="ti ti-chevron-right text-base"></i>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- 详情弹窗 -->
<div id="detailModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeModal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto" role="dialog" aria-modal="true" aria-label="<?= e(__('inq_detail_title')) ?>">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h3 class="font-bold text-gray-800"><?php echo __('inq_detail_title'); ?></h3>
            <button type="button" onclick="closeModal()" class="w-11 h-11 shrink-0 inline-flex items-center justify-center rounded text-gray-600 hover:text-gray-900 hover:bg-gray-100 focus-visible:outline-2 focus-visible:outline-primary" aria-label="<?= e(__('close')) ?>" title="<?= e(__('close')) ?>"><i class="ti ti-x text-2xl" aria-hidden="true"></i></button>
        </div>
        <div class="p-6" id="detailContent"></div>
        <div class="px-6 pb-4">
            <button type="button" id="blockIpButton" onclick="prepareIpBlock()" class="text-red-600 text-sm inline-flex items-center gap-1"><i class="ti ti-ban" aria-hidden="true"></i><?= e(__('form_ip_block')) ?></button>
            <div id="ipBlockConfirm" class="hidden border-t mt-3 pt-3 space-y-3" data-testid="form-ip-confirm">
                <p id="ipBlockSummary" class="text-sm break-all"></p>
                <p class="text-sm text-gray-500"><?= e(__('form_ip_scope')) ?></p>
                <label class="flex gap-2 items-start text-sm"><input type="checkbox" id="ipDeleteMessages" class="mt-1"><span><?= e(__('form_ip_delete')) ?></span></label>
                <p class="text-sm text-red-600"><?= e(__('form_ip_delete_warning')) ?></p>
                <div class="flex gap-3">
                    <button type="button" id="ipBlockApply" onclick="applyIpBlock()" class="bg-red-600 text-white rounded px-3 py-2 text-sm"><?= e(__('form_ip_confirm')) ?></button>
                    <button type="button" onclick="cancelIpBlock()" class="border rounded px-3 py-2 text-sm"><?= e(__('cancel')) ?></button>
                </div>
            </div>
        </div>
        <div class="px-6 py-4 border-t">
            <form id="statusForm" class="flex flex-wrap gap-4 items-center">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="id" id="detailId">
                <select name="status" class="border rounded px-3 py-2">
                    <?php foreach ($statusLabels as $k => $v): ?>
                    <option value="<?php echo $k; ?>"><?php echo $v; ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="note" placeholder="<?php echo __('inq_note_placeholder'); ?>" class="flex-1 border rounded px-3 py-2">
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
                    <i class="ti ti-check text-base"></i>
                    <?php echo __('inq_btn_update'); ?></button>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('checkAll').addEventListener('change', function() {
    document.querySelectorAll('input[name="ids[]"]').forEach(el => el.checked = this.checked);
});

function escapeHtml(str) {
    if (!str) return '-';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

function showDetail(item) {
    cancelIpBlock();
    document.getElementById('blockIpButton').disabled = !item.ip;
    document.getElementById('detailId').value = item.id;
    let productLine = '';
    if (item.product_id && parseInt(item.product_id) > 0) {
        productLine = `<p><span class="text-gray-500"><?php echo __('inq_field_product'); ?>：</span><a href="/product/${item.product_id}.html" target="_blank" class="text-primary hover:underline">${escapeHtml(item.product_title)}</a></p>`;
    }
    document.getElementById('detailContent').innerHTML = `
        <div class="space-y-3">
            ${productLine}
            <p><span class="text-gray-500"><?php echo __('inq_th_name'); ?>：</span>${escapeHtml(item.name)}</p>
            <p><span class="text-gray-500"><?php echo __('inq_th_phone'); ?>：</span>${escapeHtml(item.phone)}</p>
            <p><span class="text-gray-500"><?php echo __('inq_th_email'); ?>：</span>${escapeHtml(item.email)}</p>
            <p><span class="text-gray-500"><?php echo __('inq_th_company'); ?>：</span>${escapeHtml(item.company)}</p>
            <p><span class="text-gray-500"><?php echo __('inq_th_content'); ?>：</span>${escapeHtml(item.content)}</p>
            <p><span class="text-gray-500"><?php echo __('inq_field_ip'); ?>：</span>${escapeHtml(item.ip)}</p>
            <p><span class="text-gray-500"><?php echo __('inq_field_note'); ?>：</span>${escapeHtml(item.follow_note)}</p>
        </div>
    `;
    document.querySelector('#statusForm select').value = item.status;
    document.getElementById('detailModal').classList.remove('hidden');
}

function closeModal() {
    document.getElementById('detailModal').classList.add('hidden');
}

let ipBlockPlan = null;
function cancelIpBlock() {
    ipBlockPlan = null;
    document.getElementById('ipBlockConfirm').classList.add('hidden');
    document.getElementById('ipDeleteMessages').checked = false;
}
async function formIpRequest(values) {
    const body = new FormData();
    Object.entries(values).forEach(([key, value]) => body.append(key, value));
    const result = await safeJson(await fetch('/admin/form.php', { method: 'POST', body }));
    if (result.code !== 0) throw new Error(result.msg);
    return result;
}
async function prepareIpBlock() {
    const id = document.getElementById('detailId').value;
    const button = document.getElementById('blockIpButton');
    button.disabled = true;
    try {
        const result = await formIpRequest({ action: 'ip_info', id });
        if (document.getElementById('detailId').value !== id) return;
        ipBlockPlan = { ...result.data, id };
        document.getElementById('ipBlockSummary').textContent = <?= json_encode(__('form_ip_summary'), JSON_HEX_TAG) ?>.replace(':ip', result.data.ip).replace(':count', result.data.count);
        document.getElementById('ipDeleteMessages').checked = false;
        document.getElementById('ipBlockConfirm').classList.remove('hidden');
        document.getElementById('ipBlockApply').focus();
    } catch (error) { showMessage(error.message, 'error'); }
    finally { button.disabled = false; }
}
async function applyIpBlock() {
    if (!ipBlockPlan) return;
    const button = document.getElementById('ipBlockApply');
    const plan = ipBlockPlan;
    button.disabled = true;
    try {
        const result = await formIpRequest({ action: 'block_ip', id: plan.id, expected_count: plan.count, delete_messages: document.getElementById('ipDeleteMessages').checked ? '1' : '0' });
        showMessage(result.msg);
        const listUrl = new URL(location.href);
        listUrl.searchParams.delete('view');
        history.replaceState(null, '', listUrl);
        setTimeout(() => location.reload(), 800);
    } catch (error) { showMessage(error.message, 'error'); }
    finally { button.disabled = false; }
}
async function unblockFormIp(ip) {
    if (!confirm(<?= json_encode(__('form_ip_unblock_confirm'), JSON_HEX_TAG) ?>.replace(':ip', ip))) return;
    try {
        const result = await formIpRequest({ action: 'unblock_ip', ip });
        showMessage(result.msg);
        setTimeout(() => location.reload(), 800);
    } catch (error) { showMessage(error.message, 'error'); }
}

document.getElementById('statusForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('<?php echo __('inq_update_success'); ?>');
        setTimeout(() => location.reload(), 1000);
    } else {
        showMessage(data.msg, 'error');
    }
});

async function deleteForm(id) {
    if (!confirm('<?php echo __('admin_confirm_delete'); ?>')) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('<?php echo __('admin_deleted'); ?>');
        setTimeout(() => location.reload(), 1000);
    } else {
        showMessage(data.msg, 'error');
    }
}

async function batchDelete() {
    const checked = document.querySelectorAll('input[name="ids[]"]:checked');
    if (checked.length === 0) {
        showMessage('<?php echo __('admin_please_select'); ?>', 'error');
        return;
    }
    if (!confirm(`<?php echo sprintf(__('inq_confirm_batch_del'), 0); ?>`.replace('0', checked.length))) return;
    const formData = new FormData();
    formData.append('action', 'batch_delete');
    checked.forEach(el => formData.append('ids[]', el.value));
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('<?php echo __('admin_deleted'); ?>');
        setTimeout(() => location.reload(), 1000);
    } else {
        showMessage(data.msg, 'error');
    }
}

<?php if ($viewItem): ?>
// 自动打开详情
showDetail(<?php echo json_encode($viewItem, JSON_HEX_TAG | JSON_HEX_AMP); ?>);
<?php endif; ?>
</script>

<?php adminModuleEnd(); ?>
<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
