<?php
/**
 * Yikai CMS - 内容管理
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

// 内容类型
$contentTypes = [
    'article' => '文章',
    'product' => '产品',
    'case' => '案例',
    'download' => '下载',
    'job' => '招聘',
];

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'delete') {
        $id = postInt('id');
        contentModel()->deleteById($id);
        adminLog('content', 'delete', '删除内容ID：' . $id);
        success();
    }

    if ($action === 'batch_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!empty($ids)) {
            contentModel()->deleteByIds($ids);
            adminLog('content', 'batch_delete', '批量删除：' . implode(',', $ids));
        }
        success();
    }

    if ($action === 'toggle') {
        $id = postInt('id');
        $field = post('field');
        $value = postInt('value');

        if (!in_array($field, ['status', 'is_top', 'is_recommend', 'is_hot'])) {
            error('非法操作');
        }

        contentModel()->updateById($id, [$field => $value]);
        success();
    }

    exit;
}

// 查询参数
$channelId = getInt('channel_id');
$type = get('type');
$status = get('status', '');
$keyword = get('keyword');
$page = max(1, getInt('page', 1));
$perPage = 20;

// 构建查询
$where = [];
$params = [];

if ($channelId > 0) {
    $where[] = 'c.channel_id = ?';
    $params[] = $channelId;
}

if ($type) {
    $where[] = 'c.type = ?';
    $params[] = $type;
}

if ($status !== '') {
    $where[] = 'c.status = ?';
    $params[] = (int)$status;
}

if ($keyword) {
    $where[] = '(c.title LIKE ? OR c.summary LIKE ?)';
    $params[] = '%' . $keyword . '%';
    $params[] = '%' . $keyword . '%';
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// 获取总数
$total = (int) contentModel()->queryColumn(
    "SELECT COUNT(*) FROM " . contentModel()->tableName() . " c $whereClause",
    $params
);

// 获取列表
$offset = (int)(($page - 1) * $perPage);
$perPage = (int)$perPage;
$params[] = $perPage;
$params[] = $offset;
$contents = contentModel()->query(
    "SELECT c.*, ch.name as channel_name
     FROM " . contentModel()->tableName() . " c
     LEFT JOIN " . channelModel()->tableName() . " ch ON c.channel_id = ch.id
     $whereClause
     ORDER BY c.is_top DESC, c.id DESC
     LIMIT ? OFFSET ?",
    $params
);

// 获取栏目列表
$channels = channelModel()->all('sort_order DESC');

$pageTitle = '内容管理';
$currentMenu = 'content';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex flex-wrap gap-4 items-center justify-between">
        <form class="flex flex-wrap gap-3 items-center">
            <select name="channel_id" class="border rounded px-3 py-2">
                <option value="">全部栏目</option>
                <?php foreach ($channels as $ch): ?>
                <option value="<?php echo $ch['id']; ?>" <?php echo $channelId == $ch['id'] ? 'selected' : ''; ?>>
                    <?php echo e($ch['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select name="type" class="border rounded px-3 py-2">
                <option value="">全部类型</option>
                <?php foreach ($contentTypes as $key => $label): ?>
                <option value="<?php echo $key; ?>" <?php echo $type === $key ? 'selected' : ''; ?>>
                    <?php echo $label; ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select name="status" class="border rounded px-3 py-2">
                <option value="">全部状态</option>
                <option value="1" <?php echo $status === '1' ? 'selected' : ''; ?>>已发布</option>
                <option value="0" <?php echo $status === '0' ? 'selected' : ''; ?>>草稿</option>
            </select>

            <input type="text" name="keyword" value="<?php echo e($keyword); ?>"
                   class="border rounded px-3 py-2" placeholder="搜索标题...">

            <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                筛选
            </button>
        </form>

        <a href="/admin/content_edit.php" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            添加内容
        </a>
    </div>
</div>

<!-- 内容列表 -->
<div class="bg-white rounded-lg shadow">
    <form id="listForm">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left">
                            <input type="checkbox" id="checkAll">
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">标题</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">栏目</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">类型</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">浏览</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">置顶</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">状态</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">时间</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($contents as $item): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <input type="checkbox" name="ids[]" value="<?php echo $item['id']; ?>">
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <?php if ($item['cover']): ?>
                                <img src="<?php echo e($item['cover']); ?>" class="w-20 h-14 object-cover rounded flex-shrink-0">
                                <?php else: ?>
                                <div class="w-20 h-14 bg-gray-100 rounded flex-shrink-0 flex items-center justify-center">
                                    <svg class="w-6 h-6 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                </div>
                                <?php endif; ?>
                                <div>
                                    <a href="/admin/content_edit.php?id=<?php echo $item['id']; ?>" class="text-gray-800 hover:text-primary font-medium">
                                        <?php echo e($item['title']); ?>
                                    </a>
                                    <?php if ($item['is_recommend']): ?>
                                    <span class="text-xs bg-blue-100 text-blue-600 px-1 rounded ml-1">荐</span>
                                    <?php endif; ?>
                                    <?php if ($item['is_hot']): ?>
                                    <span class="text-xs bg-red-100 text-red-600 px-1 rounded ml-1">热</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-500">
                            <?php echo e($item['channel_name'] ?? '-'); ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded">
                                <?php echo $contentTypes[$item['type']] ?? $item['type']; ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-gray-500">
                            <?php echo number_format((int)$item['views']); ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <button onclick="toggleField(<?php echo $item['id']; ?>, 'is_top', <?php echo $item['is_top'] ? 0 : 1; ?>)"
                                    class="<?php echo $item['is_top'] ? 'text-primary' : 'text-gray-400'; ?>">
                                <?php echo $item['is_top'] ? '★' : '☆'; ?>
                            </button>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <button onclick="toggleField(<?php echo $item['id']; ?>, 'status', <?php echo $item['status'] ? 0 : 1; ?>)"
                                    class="text-xs px-2 py-1 rounded <?php echo $item['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                                <?php echo $item['status'] ? '已发布' : '草稿'; ?>
                            </button>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-gray-500">
                            <?php echo date('Y-m-d', (int)$item['created_at']); ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <a href="/admin/content_edit.php?id=<?php echo $item['id']; ?>" class="text-primary hover:underline text-sm mr-2 inline-flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                编辑</a>
                            <button onclick="deleteContent(<?php echo $item['id']; ?>)" class="text-red-600 hover:underline text-sm inline-flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                删除</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($contents)): ?>
                    <tr>
                        <td colspan="9" class="px-4 py-8 text-center text-gray-500">暂无内容</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 批量操作和分页 -->
        <div class="px-6 py-4 border-t flex flex-wrap gap-4 items-center justify-between">
            <div class="flex gap-2">
                <button type="button" onclick="batchDelete()" class="border px-3 py-1 rounded text-sm hover:bg-gray-100 inline-flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                    批量删除
                </button>
            </div>

            <?php if ($total > $perPage): ?>
            <div class="flex items-center gap-2">
                <span class="text-sm text-gray-500">共 <?php echo $total; ?> 条</span>
                <?php
                $queryString = http_build_query(array_filter([
                    'channel_id' => $channelId,
                    'type' => $type,
                    'status' => $status,
                    'keyword' => $keyword,
                ]));
                $baseUrl = '?' . ($queryString ? $queryString . '&' : '');
                $totalPages = ceil($total / $perPage);
                ?>
                <?php if ($page > 1): ?>
                <a href="<?php echo $baseUrl; ?>page=<?php echo $page - 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-100 inline-flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    上一页</a>
                <?php endif; ?>
                <span class="text-sm">第 <?php echo $page; ?>/<?php echo $totalPages; ?> 页</span>
                <?php if ($page < $totalPages): ?>
                <a href="<?php echo $baseUrl; ?>page=<?php echo $page + 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-100 inline-flex items-center gap-1">
                    下一页
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </form>
</div>

<script>
// 全选
document.getElementById('checkAll').addEventListener('change', function() {
    document.querySelectorAll('input[name="ids[]"]').forEach(el => el.checked = this.checked);
});

// 切换字段
async function toggleField(id, field, value) {
    const formData = new FormData();
    formData.append('action', 'toggle');
    formData.append('id', id);
    formData.append('field', field);
    formData.append('value', value);

    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);

    if (data.code === 0) {
        location.reload();
    } else {
        showMessage(data.msg, 'error');
    }
}

// 删除内容
async function deleteContent(id) {
    if (!confirm('确定要删除吗？')) return;

    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);

    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);

    if (data.code === 0) {
        showMessage('删除成功');
        setTimeout(() => location.reload(), 1000);
    } else {
        showMessage(data.msg, 'error');
    }
}

// 批量删除
async function batchDelete() {
    const checked = document.querySelectorAll('input[name="ids[]"]:checked');
    if (checked.length === 0) {
        showMessage('请选择要删除的项', 'error');
        return;
    }

    if (!confirm(`确定要删除选中的 ${checked.length} 项吗？`)) return;

    const formData = new FormData();
    formData.append('action', 'batch_delete');
    checked.forEach(el => formData.append('ids[]', el.value));

    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);

    if (data.code === 0) {
        showMessage('删除成功');
        setTimeout(() => location.reload(), 1000);
    } else {
        showMessage(data.msg, 'error');
    }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
