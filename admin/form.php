<?php
/**
 * ikaiCMS - 表单管理
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

$pageTitle = __('admin_form');
$currentMenu = 'form';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/form.php" class="px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary"><?php echo __('inq_tab_data'); ?></a>
        <a href="/admin/form_design.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300"><?php echo __('inq_tab_design'); ?></a>
    </div>
</div>

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
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                <?php echo __('admin_filter'); ?>
            </button>
        </form>
    </div>
</div>

<!-- 列表 -->
<div class="bg-white rounded-lg shadow">
    <form id="listForm">
        <div class="overflow-x-auto">
            <table class="w-full">
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
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                <?php echo __('inq_btn_view'); ?></button>
                            <button type="button" onclick="deleteForm(<?php echo $item['id']; ?>)"
                                    class="text-red-600 hover:underline text-sm inline-flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
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
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
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
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    <?php echo __('list_prev_page'); ?></a>
                <?php endif; ?>
                <span class="text-sm"><?php echo sprintf(__('inq_page_x_of_y'), $page, $totalPages); ?></span>
                <?php if ($page < $totalPages): ?>
                <a href="<?php echo $baseUrl; ?>page=<?php echo $page + 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-100 inline-flex items-center gap-1">
                    <?php echo __('list_next_page'); ?>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
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
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow-xl w-full max-w-lg">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h3 class="font-bold text-gray-800"><?php echo __('inq_detail_title'); ?></h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <div class="p-6" id="detailContent"></div>
        <div class="px-6 py-4 border-t">
            <form id="statusForm" class="flex gap-4 items-center">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="id" id="detailId">
                <select name="status" class="border rounded px-3 py-2">
                    <?php foreach ($statusLabels as $k => $v): ?>
                    <option value="<?php echo $k; ?>"><?php echo $v; ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="note" placeholder="<?php echo __('inq_note_placeholder'); ?>" class="flex-1 border rounded px-3 py-2">
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
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

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
