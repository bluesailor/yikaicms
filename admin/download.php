<?php
/**
 * Yikai CMS - 下载管理
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('content');

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'delete') {
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

$offset = ($page - 1) * $perPage;
$filters = array_filter(['status' => $status, 'keyword' => $keyword], fn($v) => $v !== '');
$result = downloadModel()->getList($categoryId, $filters, $perPage, $offset);
$total = $result['total'];
$items = $result['items'];

$pageTitle = '下载管理';
$currentMenu = 'download';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex flex-wrap gap-4 items-center justify-between">
        <form class="flex flex-wrap gap-3 items-center">
            <select name="category_id" class="border rounded px-3 py-2">
                <option value="">全部分类</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?php echo $cat['id']; ?>" <?php echo $categoryId === (int)$cat['id'] ? 'selected' : ''; ?>>
                    <?php echo e($cat['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select name="status" class="border rounded px-3 py-2">
                <option value="">全部状态</option>
                <option value="1" <?php echo $status === '1' ? 'selected' : ''; ?>>已发布</option>
                <option value="0" <?php echo $status === '0' ? 'selected' : ''; ?>>已隐藏</option>
            </select>

            <input type="text" name="keyword" value="<?php echo e($keyword); ?>"
                   class="border rounded px-3 py-2" placeholder="搜索文件名...">

            <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                筛选
            </button>
        </form>

        <div class="flex gap-2">
            <a href="/admin/download_category.php" class="border border-gray-300 hover:bg-gray-100 px-4 py-2 rounded inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path></svg>
                分类管理
            </a>
            <a href="/admin/download_edit.php" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                添加下载
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
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">分类</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">类型</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">大小</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">下载</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">排序</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">状态</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">操作</th>
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
                                <?php echo $item['status'] ? '已发布' : '已隐藏'; ?>
                            </button>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($item['file_url']): ?>
                            <a href="<?php echo e($item['file_url']); ?>" target="_blank"
                               class="text-gray-500 hover:text-primary text-sm mr-2 inline-flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                                下载
                            </a>
                            <?php endif; ?>
                            <a href="/admin/download_edit.php?id=<?php echo $item['id']; ?>"
                               class="text-primary hover:underline text-sm mr-2">编辑</a>
                            <button onclick="deleteItem(<?php echo $item['id']; ?>)"
                                    class="text-red-600 hover:underline text-sm">删除</button>
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
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                批量删除
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
                <a href="<?php echo $baseUrl; ?>page=<?php echo $page - 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-100">上一页</a>
                <?php endif; ?>
                <span class="text-sm">第 <?php echo $page; ?>/<?php echo $totalPages; ?> 页</span>
                <?php if ($page < $totalPages): ?>
                <a href="<?php echo $baseUrl; ?>page=<?php echo $page + 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-100">下一页</a>
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
        showMessage('删除成功');
        setTimeout(() => location.reload(), 1000);
    }
}

async function batchDelete() {
    const checked = document.querySelectorAll('input[name="ids[]"]:checked');
    if (checked.length === 0) { showMessage('请选择要删除的项', 'error'); return; }
    if (!confirm(`确定要删除选中的 ${checked.length} 项吗？`)) return;
    const formData = new FormData();
    formData.append('action', 'batch_delete');
    checked.forEach(el => formData.append('ids[]', el.value));
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('删除成功');
        setTimeout(() => location.reload(), 1000);
    }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
