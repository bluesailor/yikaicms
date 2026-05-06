<?php
/**
 * Yikai CMS - 招聘管理
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
        jobModel()->deleteById($id);
        adminLog('job', 'delete', '删除职位ID：' . $id);
        success();
    }

    if ($action === 'batch_delete') {
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

$defaultLang = config('site_lang', 'zh-CN');
$hasMultiLang = isMultiLangEnabled('jobs');
$filterLang = get('lang', $defaultLang);
$allLangs = availableLanguages();
if ($hasMultiLang && !isset($allLangs[$filterLang])) $filterLang = $defaultLang;

// 查询参数
$status = get('status', '');
$keyword = get('keyword');
$page = max(1, getInt('page', 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// 构建筛选条件
$filters = [];
if ($status !== '') {
    $filters['status'] = $status;
}
if ($keyword) {
    $filters['keyword'] = $keyword;
}
if ($hasMultiLang) {
    $filters['lang'] = $filterLang;
}

$result = jobModel()->getList($filters, $perPage, $offset);
$items = $result['items'];
$total = $result['total'];

$pageTitle = '招聘管理';
$currentMenu = 'job';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<?php if ($hasMultiLang && count($allLangs) > 1): ?>
<div class="mb-4 flex items-center gap-2 flex-wrap">
    <span class="text-sm text-gray-500">语言：</span>
    <?php foreach ($allLangs as $lc => $ll): ?>
    <a href="?lang=<?php echo e($lc); ?>" class="px-4 py-1.5 rounded-full text-sm border transition <?php echo $lc === $filterLang ? 'bg-primary text-white border-primary' : 'text-gray-600 border-gray-200 hover:border-primary hover:text-primary'; ?>"><?php echo e($ll); ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex flex-wrap gap-4 items-center justify-between">
        <form class="flex flex-wrap gap-3 items-center">
            <select name="status" class="border rounded px-3 py-2">
                <option value="">全部状态</option>
                <option value="1" <?php echo $status === '1' ? 'selected' : ''; ?>>招聘中</option>
                <option value="0" <?php echo $status === '0' ? 'selected' : ''; ?>>已关闭</option>
            </select>

            <input type="text" name="keyword" value="<?php echo e($keyword); ?>"
                   class="border rounded px-3 py-2" placeholder="搜索职位...">

            <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                筛选
            </button>
        </form>

        <a href="/admin/job_edit.php" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            发布职位
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
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">职位名称</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">工作地点</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">薪资</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">浏览</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">置顶</th>
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
                            <div class="font-medium"><?php echo e($item['title']); ?></div>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="text-xs bg-blue-100 text-blue-600 px-2 py-1 rounded">
                                <?php echo e($item['location'] ?: '不限'); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-orange-600 font-medium">
                            <?php echo e($item['salary'] ?: '面议'); ?>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-gray-500"><?php echo number_format((int)$item['views']); ?></td>
                        <td class="px-4 py-3 text-center">
                            <button onclick="toggle(<?php echo $item['id']; ?>, 'is_top', <?php echo $item['is_top'] ? 0 : 1; ?>, this)"
                                    class="text-xs px-2 py-1 rounded <?php echo $item['is_top'] ? 'bg-orange-100 text-orange-600' : 'bg-gray-100 text-gray-400'; ?>">
                                <?php echo $item['is_top'] ? '置顶' : '普通'; ?>
                            </button>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <button onclick="toggle(<?php echo $item['id']; ?>, 'status', <?php echo $item['status'] ? 0 : 1; ?>, this)"
                                    class="text-xs px-2 py-1 rounded <?php echo $item['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                                <?php echo $item['status'] ? '招聘中' : '已关闭'; ?>
                            </button>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-gray-500">
                            <?php echo $item['publish_time'] ? date('Y-m-d', (int)$item['publish_time']) : '-'; ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <a href="/admin/job_edit.php?id=<?php echo $item['id']; ?>" class="text-blue-500 hover:text-blue-700 text-sm inline-flex items-center gap-1 mr-2" title="编辑"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg> 编辑</a>
                            <button onclick="deleteItem(<?php echo $item['id']; ?>)" class="text-red-500 hover:text-red-700" title="删除"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($items)): ?>
                    <tr><td colspan="9" class="px-4 py-8 text-center text-gray-500">暂无职位数据</td></tr>
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
                $queryString = http_build_query(array_filter(['status' => $status, 'keyword' => $keyword]));
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
    if (data.code === 0) { location.reload(); }
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
