<?php
/**
 * YikaiCMS - 下载管理
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('edit_download');

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'delete') {
        requirePermission('delete_download');
        $id = postInt('id');
        $download = downloadModel()->find($id);
        if ($download && !$download['is_external'] && $download['file_url']) {
            $filePath = realpath(ROOT_PATH . $download['file_url']);
            if ($filePath && str_starts_with($filePath, realpath(UPLOADS_PATH)) && file_exists($filePath)) {
                @unlink($filePath);
            }
        }
        downloadModel()->deleteById($id);
        adminLog('download', 'delete', '删除下载ID：' . $id);
        success();
    }

    if ($action === 'batch_delete') {
        requirePermission('delete_download');
        $ids = $_POST['ids'] ?? [];
        if (!empty($ids)) {
            $downloads = downloadModel()->getFileInfoByIds($ids);
            foreach ($downloads as $d) {
                if (!$d['is_external'] && $d['file_url']) {
                    $filePath = realpath(ROOT_PATH . $d['file_url']);
                    if ($filePath && str_starts_with($filePath, realpath(UPLOADS_PATH)) && file_exists($filePath)) {
                        @unlink($filePath);
                    }
                }
            }
            downloadModel()->deleteByIds($ids);
            adminLog('download', 'batch_delete', '批量删除：' . implode(',', $ids));
        }
        success();
    }

    if ($action === 'toggle_status') {
        $id = postInt('id');
        $newStatus = downloadModel()->toggle($id, 'status');
        success(['status' => $newStatus]);
    }

    if ($action === 'update_sort') {
        $id = postInt('id');
        $sort = postInt('sort_order');
        downloadModel()->updateById($id, ['sort_order' => $sort, 'updated_at' => time()]);
        success();
    }

    exit;
}

// 获取分类
$categories = downloadCategoryModel()->getActive();

// 查询参数
$categoryId = getInt('category_id');
$status = get('status', '');
$keyword = get('keyword');
$page = max(1, getInt('page', 1));
$perPage = 20;

// 视图语言
$_lang        = adminLangView();
$_defaultLang = $_lang['default'];
$_viewLang    = $_lang['view'];
$_enabledList = $_lang['enabled'];
$_langLabels  = availableLanguages();

$offset = ($page - 1) * $perPage;
$filters = array_filter(['status' => $status, 'keyword' => $keyword, 'lang' => $_viewLang], fn($v) => $v !== '');
$result = downloadModel()->getList($categoryId, $filters, $perPage, $offset);
$total = $result['total'];
$items = $result['items'];

$pageTitle = __('admin_download');
$currentMenu = 'download';

require_once ROOT_PATH . '/admin/includes/trans_pills.php';
$transStatus = loadTransStatus('downloads');

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<?php echo renderAdminLangSwitcher($_viewLang); ?>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex flex-wrap gap-4 items-center justify-between">
        <form class="flex flex-wrap gap-3 items-center">
            <select name="category_id" class="border rounded px-3 py-2">
                <option value=""><?php echo __('admin_all'); ?></option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?php echo $cat['id']; ?>" <?php echo $categoryId === (int)$cat['id'] ? 'selected' : ''; ?>>
                    <?php echo e($cat['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select name="status" class="border rounded px-3 py-2">
                <option value=""><?php echo __('admin_all'); ?></option>
                <option value="1" <?php echo $status === '1' ? 'selected' : ''; ?>><?php echo __('status_published'); ?></option>
                <option value="0" <?php echo $status === '0' ? 'selected' : ''; ?>>已隐藏</option>
            </select>

            <input type="text" name="keyword" value="<?php echo e($keyword); ?>"
                   class="border rounded px-3 py-2" placeholder="<?php echo __('admin_search'); ?>...">

            <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <i class="ti ti-search text-base"></i>
                <?php echo __('admin_filter'); ?>
            </button>
        </form>

        <div class="flex gap-2">
            <a href="/admin/download_category.php" class="border border-gray-300 hover:bg-gray-100 px-4 py-2 rounded inline-flex items-center gap-1">
                <i class="ti ti-tag text-base"></i>
                分类管理
            </a>
            <a href="/admin/download_edit.php" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <i class="ti ti-plus text-base"></i>
                <?php echo __('admin_add'); ?>
            </a>
        </div>
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
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">文件信息</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_category'); ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_type'); ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">大小</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">下载</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_sort_order'); ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_status'); ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">翻译</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_action'); ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($items as $item): ?>
                    <?php
                    $extIcon = match(strtolower($item['file_ext'])) {
                        'pdf' => ['bg-red-100 text-red-600', 'PDF'],
                        'doc', 'docx' => ['bg-blue-100 text-blue-600', 'DOC'],
                        'xls', 'xlsx' => ['bg-green-100 text-green-600', 'XLS'],
                        'ppt', 'pptx' => ['bg-orange-100 text-orange-600', 'PPT'],
                        'zip', 'rar', '7z' => ['bg-purple-100 text-purple-600', 'ZIP'],
                        'exe', 'msi' => ['bg-gray-100 text-gray-600', 'EXE'],
                        default => ['bg-gray-100 text-gray-500', strtoupper($item['file_ext']) ?: '?'],
                    };
                    ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3"><input type="checkbox" name="ids[]" value="<?php echo $item['id']; ?>"></td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 <?php echo $extIcon[0]; ?> rounded flex items-center justify-center text-xs font-bold">
                                    <?php echo $extIcon[1]; ?>
                                </div>
                                <div>
                                    <div class="font-medium"><?php echo e($item['title']); ?></div>
                                    <?php if ($item['file_name']): ?>
                                    <div class="text-xs text-gray-400"><?php echo e($item['file_name']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="text-xs bg-blue-100 text-blue-600 px-2 py-1 rounded">
                                <?php echo e($item['category_name'] ?: '未分类'); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($item['is_external']): ?>
                            <span class="text-xs bg-yellow-100 text-yellow-600 px-2 py-1 rounded">外链</span>
                            <?php else: ?>
                            <span class="text-xs bg-green-100 text-green-600 px-2 py-1 rounded">本地</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-gray-500">
                            <?php echo $item['file_size'] > 0 ? formatFileSize((int)$item['file_size']) : '-'; ?>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-gray-500">
                            <?php echo number_format((int)$item['download_count']); ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <input type="number" value="<?php echo $item['sort_order']; ?>"
                                   onchange="updateSort(<?php echo $item['id']; ?>, this.value)"
                                   class="w-16 text-center border rounded px-2 py-1 text-sm">
                        </td>
                        <td class="px-4 py-3 text-center">
                            <button onclick="toggleStatus(<?php echo $item['id']; ?>, this)"
                                    class="text-xs px-2 py-1 rounded <?php echo $item['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                                <?php echo $item['status'] ? __('admin_published') : __('admin_hide'); ?>
                            </button>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php
                            $_pillSrcId = (int) ($item['translation_group_id'] ?? $item['id']);
                            echo renderTransPills($_pillSrcId, $transStatus, '/admin/download_edit.php');
                            ?>
                        </td>
                        <td class="px-4 py-3 text-center whitespace-nowrap">
                            <?php if ($item['file_url']): ?>
                            <a href="<?php echo e($item['file_url']); ?>" target="_blank"
                               class="text-gray-500 hover:text-primary text-sm mr-2 inline-flex items-center gap-1">
                                <i class="ti ti-download text-sm"></i>
                                下载
                            </a>
                            <?php endif; ?>
                            <a href="/admin/download_edit.php?id=<?php echo $item['id']; ?>"
                               class="text-blue-500 hover:text-blue-700 text-sm inline-flex items-center gap-1 mr-2" title="<?php echo __('admin_edit'); ?>"><i class="ti ti-pencil text-base"></i> <?php echo __('admin_edit'); ?></a>
                            <button onclick="deleteItem(<?php echo $item['id']; ?>)"
                                    class="text-red-500 hover:text-red-700 text-sm inline-flex items-center gap-1" title="<?php echo __('admin_delete'); ?>"><i class="ti ti-trash text-base"></i> <?php echo __('admin_delete'); ?></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($items)): ?>
                    <tr><td colspan="9" class="px-4 py-8 text-center text-gray-500">暂无下载数据</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="px-6 py-4 border-t flex flex-wrap gap-4 items-center justify-between">
            <button type="button" onclick="batchDelete()" class="border px-3 py-1 rounded text-sm hover:bg-gray-100 inline-flex items-center gap-1">
                <i class="ti ti-trash text-base"></i>
                <?php echo __('admin_batch_delete'); ?>
            </button>
            <?php if ($total > $perPage): ?>
            <div class="flex items-center gap-2">
                <span class="text-sm text-gray-500">共 <?php echo $total; ?> 条</span>
                <?php
                $totalPages = ceil($total / $perPage);
                $queryString = http_build_query(array_filter(['category_id' => $categoryId, 'status' => $status, 'keyword' => $keyword]));
                $baseUrl = '?' . ($queryString ? $queryString . '&' : '');
                ?>
                <?php if ($page > 1): ?>
                <a href="<?php echo $baseUrl; ?>page=<?php echo $page - 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-100"><?php echo __('list_prev_page'); ?></a>
                <?php endif; ?>
                <span class="text-sm">第 <?php echo $page; ?>/<?php echo $totalPages; ?> 页</span>
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

async function toggleStatus(id, btn) {
    const formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        if (data.data.status) {
            btn.className = 'text-xs px-2 py-1 rounded bg-green-100 text-green-600';
            btn.textContent = '已发布';
        } else {
            btn.className = 'text-xs px-2 py-1 rounded bg-gray-100 text-gray-500';
            btn.textContent = '已隐藏';
        }
        showMessage('状态已更新');
    }
}

async function updateSort(id, value) {
    const formData = new FormData();
    formData.append('action', 'update_sort');
    formData.append('id', id);
    formData.append('sort_order', value);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('排序已更新');
    }
}

async function deleteItem(id) {
    if (!confirm('确定要删除吗？删除后文件也将被移除。')) return;
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

async function batchDelete() {
    const checked = document.querySelectorAll('input[name="ids[]"]:checked');
    if (checked.length === 0) { showMessage('<?php echo __('admin_please_select'); ?>', 'error'); return; }
    if (!confirm(`确定要删除选中的 ${checked.length} 项吗？`)) return;
    const formData = new FormData();
    formData.append('action', 'batch_delete');
    checked.forEach(el => formData.append('ids[]', el.value));
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('<?php echo __('admin_deleted'); ?>');
        setTimeout(() => location.reload(), 1000);
    }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
