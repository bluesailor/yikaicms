<?php
/**
 * Yikai CMS - 案例管理
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

$contentType = 'case';

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'delete') {
        $id = postInt('id');
        contentModel()->deleteById($id);
        adminLog('case', 'delete', '删除案例ID：' . $id);
        success();
    }

    if ($action === 'batch_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!empty($ids)) {
            contentModel()->deleteByIds($ids);
            adminLog('case', 'batch_delete', '批量删除：' . implode(',', $ids));
        }
        success();
    }

    if ($action === 'toggle') {
        $id = postInt('id');
        $field = post('field');
        $value = postInt('value');
        if (in_array($field, ['status', 'is_top', 'is_recommend', 'is_hot'])) {
            contentModel()->updateById($id, [$field => $value]);
        }
        success();
    }

    exit;
}

// 获取案例类型的栏目
$channels = channelModel()->where(['type' => 'case', 'status' => 1], 'sort_order ASC');

// 查询参数
$channelId = getInt('channel_id');
$status = get('status', '');
$keyword = get('keyword');
$page = max(1, getInt('page', 1));
$perPage = 20;

// 构建查询
$where = ['c.type = ?'];
$params = [$contentType];

if ($channelId > 0) {
    $where[] = 'c.channel_id = ?';
    $params[] = $channelId;
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

$whereClause = 'WHERE ' . implode(' AND ', $where);

$total = (int) contentModel()->queryColumn(
    "SELECT COUNT(*) FROM " . contentModel()->tableName() . " c $whereClause",
    $params
);

$offset = (int)(($page - 1) * $perPage);
$perPage = (int)$perPage;
$params[] = $perPage;
$params[] = $offset;
$items = contentModel()->query(
    "SELECT c.*, ch.name as channel_name FROM " . contentModel()->tableName() . " c
     LEFT JOIN " . channelModel()->tableName() . " ch ON c.channel_id = ch.id
     $whereClause ORDER BY c.is_top DESC, c.id DESC LIMIT ? OFFSET ?",
    $params
);

$pageTitle = '案例管理';
$currentMenu = 'case';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex flex-wrap gap-4 items-center justify-between">
        <form class="flex flex-wrap gap-3 items-center">
            <select name="channel_id" class="border rounded px-3 py-2">
                <option value="">全部栏目</option>
                <?php foreach ($channels as $ch): ?>
                <option value="<?php echo $ch['id']; ?>" <?php echo $channelId === (int)$ch['id'] ? 'selected' : ''; ?>>
                    <?php echo e($ch['name']); ?>
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

        <a href="/admin/content_edit.php?type=case" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            添加案例
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
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">标题</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">栏目</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">浏览</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">推荐</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">状态</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">发布时间</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($items as $item): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3"><input type="checkbox" name="ids[]" value="<?php echo $item['id']; ?>"></td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <?php if ($item['cover']): ?>
                                <img src="<?php echo e($item['cover']); ?>" class="w-16 h-12 object-cover rounded">
                                <?php endif; ?>
                                <div class="font-medium"><?php echo e(cutStr($item['title'], 40)); ?></div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="text-xs bg-blue-100 text-blue-600 px-2 py-1 rounded">
                                <?php echo e($item['channel_name'] ?: '-'); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-gray-500"><?php echo number_format((int)$item['views']); ?></td>
                        <td class="px-4 py-3 text-center">
                            <button onclick="toggle(<?php echo $item['id']; ?>, 'is_recommend', <?php echo $item['is_recommend'] ? 0 : 1; ?>, this)"
                                    class="text-xs px-2 py-1 rounded <?php echo $item['is_recommend'] ? 'bg-purple-100 text-purple-600' : 'bg-gray-100 text-gray-400'; ?>">
                                <?php echo $item['is_recommend'] ? '推荐' : '普通'; ?>
                            </button>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <button onclick="toggle(<?php echo $item['id']; ?>, 'status', <?php echo $item['status'] ? 0 : 1; ?>, this)"
                                    class="text-xs px-2 py-1 rounded <?php echo $item['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                                <?php echo $item['status'] ? '已发布' : '草稿'; ?>
                            </button>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-gray-500">
                            <?php echo $item['publish_time'] ? date('Y-m-d', (int)$item['publish_time']) : '-'; ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <a href="/admin/content_edit.php?id=<?php echo $item['id']; ?>" class="text-primary hover:underline text-sm mr-2">编辑</a>
                            <button onclick="deleteItem(<?php echo $item['id']; ?>)" class="text-red-600 hover:underline text-sm">删除</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($items)): ?>
                    <tr><td colspan="8" class="px-4 py-8 text-center text-gray-500">暂无数据</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="px-6 py-4 border-t flex flex-wrap gap-4 items-center justify-between">
            <button type="button" onclick="batchDelete()" class="border px-3 py-1 rounded text-sm hover:bg-gray-100">批量删除</button>
            <?php if ($total > $perPage): ?>
            <div class="flex items-center gap-2">
                <span class="text-sm text-gray-500">共 <?php echo $total; ?> 条</span>
                <?php
                $totalPages = ceil($total / $perPage);
                $queryString = http_build_query(array_filter(['channel_id' => $channelId, 'status' => $status, 'keyword' => $keyword]));
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

async function toggle(id, field, value, btn) {
    const formData = new FormData();
    formData.append('action', 'toggle');
    formData.append('id', id);
    formData.append('field', field);
    formData.append('value', value);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        location.reload();
    }
}

async function deleteItem(id) {
    if (!confirm('确定要删除吗？')) return;
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
