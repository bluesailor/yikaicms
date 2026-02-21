<?php
/**
 * Yikai CMS - 产品管理
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

// 处理 AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'delete') {
        $id = postInt('id');
        productModel()->deleteById($id);
        adminLog('product', 'delete', "删除产品ID: $id");
        success();
    }

    if ($action === 'batch_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!empty($ids)) {
            productModel()->deleteByIds($ids);
            adminLog('product', 'batch_delete', '批量删除：' . implode(',', $ids));
        }
        success();
    }

    if ($action === 'toggle_status') {
        $id = postInt('id');
        $newStatus = productModel()->toggle($id, 'status');
        success(['status' => $newStatus]);
    }

    if ($action === 'toggle_top') {
        $id = postInt('id');
        $newValue = productModel()->toggle($id, 'is_top');
        success(['is_top' => $newValue]);
    }

    if ($action === 'toggle_recommend') {
        $id = postInt('id');
        $newValue = productModel()->toggle($id, 'is_recommend');
        success(['is_recommend' => $newValue]);
    }

    exit;
}

// 获取分类
$categories = productCategoryModel()->where(['status' => 1]);

// 查询参数
$categoryId = getInt('category_id');
$status = get('status', '');
$keyword = get('keyword');
$page = max(1, getInt('page', 1));
$perPage = 20;

$offset = ($page - 1) * $perPage;
$filters = array_filter([
    'category_id' => $categoryId,
    'status' => $status,
    'keyword' => $keyword,
], fn($v) => $v !== '' && $v !== 0);
$result = productModel()->getAdminList($filters, $perPage, $offset);
$total = $result['total'];
$products = $result['items'];

$pageTitle = '产品管理';
$currentMenu = 'product';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/product.php" class="px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary">产品列表</a>
        <a href="/admin/product_category.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300">分类管理</a>
        <a href="/admin/product_setting.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300">产品设置</a>
    </div>
</div>

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
                <option value="1" <?php echo $status === '1' ? 'selected' : ''; ?>>已上架</option>
                <option value="0" <?php echo $status === '0' ? 'selected' : ''; ?>>已下架</option>
            </select>

            <input type="text" name="keyword" value="<?php echo e($keyword); ?>"
                   class="border rounded px-3 py-2" placeholder="搜索名称/型号...">

            <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                筛选
            </button>
        </form>

        <a href="/admin/product_edit.php" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            添加产品
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
                        <th class="px-4 py-3 text-left">
                            <input type="checkbox" id="checkAll">
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">产品</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">分类</th>
                        <?php if (config('show_price', '0') === '1'): ?>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">价格</th>
                        <?php endif; ?>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">置顶</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">推荐</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">浏览</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">状态</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($products as $item): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <input type="checkbox" name="ids[]" value="<?php echo $item['id']; ?>">
                        </td>
                        <td class="px-4 py-3 text-gray-500"><?php echo $item['id']; ?></td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <?php if ($item['cover']): ?>
                                <img src="<?php echo e($item['cover']); ?>" class="w-12 h-12 object-cover rounded">
                                <?php endif; ?>
                                <div>
                                    <div class="font-medium"><?php echo e(cutStr($item['title'], 30)); ?></div>
                                    <div class="text-xs text-gray-400">
                                        <?php echo e($item['model'] ?: '-'); ?>
                                        <?php if (!empty($item['slug'])): ?>
                                        <code class="bg-gray-100 px-1.5 py-0.5 rounded ml-1"><?php echo e($item['slug']); ?></code>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="text-xs bg-blue-100 text-blue-600 px-2 py-1 rounded">
                                <?php echo e($item['category_name'] ?: '未分类'); ?>
                            </span>
                        </td>
                        <?php if (config('show_price', '0') === '1'): ?>
                        <td class="px-4 py-3 text-center">
                            <?php if ($item['price'] > 0): ?>
                            <span class="text-red-600 font-medium">&yen;<?php echo number_format((float)$item['price'], 2); ?></span>
                            <?php else: ?>
                            <span class="text-gray-400">面议</span>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td class="px-4 py-3 text-center">
                            <button onclick="toggleTop(<?php echo $item['id']; ?>, this)"
                                    class="text-xs px-2 py-1 rounded <?php echo $item['is_top'] ? 'bg-orange-100 text-orange-600' : 'bg-gray-100 text-gray-400'; ?>">
                                <?php echo $item['is_top'] ? '置顶' : '普通'; ?>
                            </button>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <button onclick="toggleRecommend(<?php echo $item['id']; ?>, this)"
                                    class="text-xs px-2 py-1 rounded <?php echo $item['is_recommend'] ? 'bg-purple-100 text-purple-600' : 'bg-gray-100 text-gray-400'; ?>">
                                <?php echo $item['is_recommend'] ? '推荐' : '普通'; ?>
                            </button>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-gray-500">
                            <?php echo number_format((int)$item['views']); ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <button onclick="toggleStatus(<?php echo $item['id']; ?>, this)"
                                    class="text-xs px-2 py-1 rounded <?php echo $item['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                                <?php echo $item['status'] ? '上架' : '下架'; ?>
                            </button>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <a href="/admin/product_edit.php?id=<?php echo $item['id']; ?>"
                               class="text-primary hover:underline text-sm mr-2 inline-flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                编辑</a>
                            <button onclick="deleteProduct(<?php echo $item['id']; ?>)"
                                    class="text-red-600 hover:underline text-sm inline-flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                删除</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($products)): ?>
                    <tr>
                        <td colspan="<?php echo config('show_price', '0') === '1' ? 10 : 9; ?>" class="px-4 py-8 text-center text-gray-500">暂无数据</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

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
                $totalPages = ceil($total / $perPage);
                $queryString = http_build_query(array_filter(['category_id' => $categoryId, 'status' => $status, 'keyword' => $keyword]));
                $baseUrl = '?' . ($queryString ? $queryString . '&' : '');
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
            btn.textContent = '上架';
        } else {
            btn.className = 'text-xs px-2 py-1 rounded bg-gray-100 text-gray-500';
            btn.textContent = '下架';
        }
    }
}

async function toggleTop(id, btn) {
    const formData = new FormData();
    formData.append('action', 'toggle_top');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        if (data.data.is_top) {
            btn.className = 'text-xs px-2 py-1 rounded bg-orange-100 text-orange-600';
            btn.textContent = '置顶';
        } else {
            btn.className = 'text-xs px-2 py-1 rounded bg-gray-100 text-gray-400';
            btn.textContent = '普通';
        }
    }
}

async function toggleRecommend(id, btn) {
    const formData = new FormData();
    formData.append('action', 'toggle_recommend');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        if (data.data.is_recommend) {
            btn.className = 'text-xs px-2 py-1 rounded bg-purple-100 text-purple-600';
            btn.textContent = '推荐';
        } else {
            btn.className = 'text-xs px-2 py-1 rounded bg-gray-100 text-gray-400';
            btn.textContent = '普通';
        }
    }
}

async function deleteProduct(id) {
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
