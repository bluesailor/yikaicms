<?php
/**
 * YikaiCMS - 招聘管理
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/admin/includes/list_ui.php';   // 列表共享组件：行内操作 / 批量下拉 / 封面占位

checkLogin();
requirePermission('edit_job');

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'delete') {
        requirePermission('delete_job');
        $id = postInt('id');
        jobModel()->deleteById($id);
        adminLog('job', 'delete', '删除职位ID：' . $id);
        success();
    }

    if ($action === 'batch_publish' || $action === 'batch_unpublish') {
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        if ($ids) {
            $val = $action === 'batch_publish' ? 1 : 0;
            foreach ($ids as $bid) {
                jobModel()->updateById($bid, ['status' => $val, 'updated_at' => time()]);
            }
            adminLog('job', $action, '批量' . ($val ? '发布' : '下架') . '：' . implode(',', $ids));
        }
        success();
    }

    if ($action === 'batch_delete') {
        requirePermission('delete_job');
        $ids = $_POST['ids'] ?? [];
        if (!empty($ids)) {
            jobModel()->deleteByIds($ids);
            adminLog('job', 'batch_delete', '批量删除：' . implode(',', $ids));
        }
        success();
    }

    if ($action === 'toggle') {
        $id = postInt('id');
        $field = post('field');
        $value = postInt('value');
        if (in_array($field, ['status', 'is_top'])) {
            jobModel()->updateById($id, [$field => $value]);
        }
        success();
    }

    exit;
}

// 查询参数
$status = get('status', '');
$keyword = get('keyword');
$page = max(1, getInt('page', 1));
$perPage = adminListPageSize('job', $page);
$offset = ($page - 1) * $perPage;

// 视图语言
$_lang        = adminLangView();
$_defaultLang = $_lang['default'];
$_viewLang    = $_lang['view'];
$_enabledList = $_lang['enabled'];
$_langLabels  = availableLanguages();

// 构建筛选条件
$filters = ['lang' => $_viewLang];
if ($status !== '') {
    $filters['status'] = $status;
}
if ($keyword) {
    $filters['keyword'] = $keyword;
}

$result = jobModel()->getList($filters, $perPage, $offset);
$items = $result['items'];
$total = $result['total'];

$pageTitle = __('admin_job');
$currentMenu = 'job';

require_once ROOT_PATH . '/admin/includes/trans_pills.php';
$transStatus = loadTransStatus('jobs');

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<?php echo renderAdminLangSwitcher($_viewLang); ?>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex flex-wrap gap-4 items-center justify-between">
        <form class="flex flex-wrap gap-3 items-center">
            <input type="hidden" name="lang" value="<?php echo e($_viewLang); ?>">
            <?php echo renderAdminPageSize($perPage); ?>
            <select name="status" class="border rounded px-3 py-2">
                <option value=""><?php echo __('admin_all'); ?></option>
                <option value="1" <?php echo $status === '1' ? 'selected' : ''; ?>><?php echo e(__('job_status_open')); ?></option>
                <option value="0" <?php echo $status === '0' ? 'selected' : ''; ?>><?php echo e(__('job_status_closed')); ?></option>
            </select>

            <input type="text" name="keyword" value="<?php echo e($keyword); ?>"
                   class="border rounded px-3 py-2" placeholder="<?php echo __('admin_search'); ?>...">

            <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <i class="ti ti-search text-base"></i>
                <?php echo __('admin_filter'); ?>
            </button>
        </form>

        <a href="/admin/job_edit.php" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
            <i class="ti ti-plus text-base"></i>
            <?php echo __('admin_add'); ?>
        </a>
    </div>
</div>

<!-- 列表 -->
<div class="bg-white rounded-lg shadow">
    <form id="listForm">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left"><input type="checkbox" id="checkAll"></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('label_job_title'); ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('label_job_location'); ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('job_salary')); ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('detail_views'); ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_top'); ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_date'); ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('admin_translation')); ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($items as $item): ?>
                    <tr class="group hover:bg-gray-50">
                        <td class="px-4 py-3"><input type="checkbox" name="ids[]" value="<?php echo $item['id']; ?>"></td>
                        <td class="px-4 py-3">
                            <div class="font-medium"><?php echo e($item['title']); ?></div>
                            <?php echo renderRowActions([
                                'id'   => (int) $item['id'],
                                'edit' => '/admin/job_edit.php?id=' . (int) $item['id'],
                                'view' => '/job/' . (int) $item['id'] . '.html',
                            ]); ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="text-xs bg-blue-100 text-blue-600 px-2 py-1 rounded">
                                <?php echo e($item['location'] ?: __('job_location_any')); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-orange-600 font-medium">
                            <?php echo e($item['salary'] ?: __('job_salary_negotiable')); ?>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-gray-500"><?php echo number_format((int)$item['views']); ?></td>
                        <td class="px-4 py-3 text-center">
                            <button onclick="toggle(<?php echo $item['id']; ?>, 'is_top', <?php echo $item['is_top'] ? 0 : 1; ?>, this)"
                                    class="text-xs px-2 py-1 rounded <?php echo $item['is_top'] ? 'bg-orange-100 text-orange-600' : 'bg-gray-100 text-gray-400'; ?>">
                                <?php echo $item['is_top'] ? __('admin_top') : '-'; ?>
                            </button>
                        </td>
                        <td class="px-4 py-3 text-center whitespace-nowrap">
                            <button onclick="toggle(<?php echo $item['id']; ?>, 'status', <?php echo $item['status'] ? 0 : 1; ?>, this)" class="text-sm block mx-auto">
                                <?php echo $item['status']
                                    ? '<span class="text-green-600">' . __('admin_published') . '</span>'
                                    : '<span class="text-gray-400">' . __('admin_disabled') . '</span>'; ?>
                            </button>
                            <span class="text-gray-400 text-xs">
                                <?php $_ts = (int) ($item['publish_time'] ?: $item['updated_at'] ?? 0); ?>
                                <?php echo $_ts ? date('Y-m-d H:i', $_ts) : '-'; ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php
                            $_pillSrcId = (int) ($item['translation_group_id'] ?: $item['id']);
                            echo renderTransPills($_pillSrcId, $transStatus, '/admin/job_edit.php');
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($items)): ?>
                    <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500"><?php echo e(__('job_empty')); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="px-6 py-4 border-t flex flex-wrap gap-4 items-center justify-between">
            <?php echo renderBulkBar([
                'batch_publish'   => __('admin_published'),
                'batch_unpublish' => __('admin_disabled'),
                'batch_delete'    => __('admin_move_to_trash'),
            ]); ?>
            <?php if ($total > $perPage): ?>
            <div class="flex items-center gap-2">
                <span class="text-sm text-gray-500"><?php echo str_replace(':n', (string) $total, e(__('admin_total_n'))); ?></span>
                <?php
                $totalPages = ceil($total / $perPage);
                $queryString = http_build_query(array_filter(['status' => $status, 'keyword' => $keyword, 'lang' => $_viewLang], static fn($value) => $value !== '' && $value !== null));
                $baseUrl = '?' . ($queryString ? $queryString . '&' : '');
                ?>
                <?php if ($page > 1): ?>
                <a href="<?php echo $baseUrl; ?>page=<?php echo $page - 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-100"><?php echo __('list_prev_page'); ?></a>
                <?php endif; ?>
                <span class="text-sm"><?php echo str_replace([':p', ':t'], [(string) $page, (string) $totalPages], e(__('admin_page_of'))); ?></span>
                <?php if ($page < $totalPages): ?>
                <a href="<?php echo $baseUrl; ?>page=<?php echo $page + 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-100"><?php echo __('list_next_page'); ?></a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </form>
</div>

<script>
document.getElementById('checkAll').addEventListener('change', function() {
    document.querySelectorAll('input[name="ids[]"]').forEach(el => el.checked = this.checked);
});

async function toggle(id, field, value, btn) {
    const formData = new FormData();
    formData.append('action', 'toggle');
    formData.append('id', id);
    formData.append('field', field);
    formData.append('value', value);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) { location.reload(); }
}

async function deleteItem(id) {
    if (!confirm('<?php echo __('admin_confirm_delete'); ?>')) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('<?php echo __('admin_deleted'); ?>');
        setTimeout(() => location.reload(), 1000);
    }
}

async function batchAction(action) {
    const checked = document.querySelectorAll('input[name="ids[]"]:checked');
    if (checked.length === 0) { showMessage('<?php echo __('admin_please_select'); ?>', 'error'); return; }
    if (!confirm('<?php echo __('admin_bulk_confirm_prefix'); ?> ' + checked.length + ' <?php echo __('admin_bulk_confirm_suffix'); ?>')) return;
    const formData = new FormData();
    formData.append('action', action);
    checked.forEach(el => formData.append('ids[]', el.value));
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) { showMessage('<?php echo __('admin_success'); ?>'); setTimeout(() => location.reload(), 1000); }
    else { showMessage(data.msg, 'error'); }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
