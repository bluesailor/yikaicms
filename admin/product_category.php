<?php
/**
 * Yikai CMS - 产品分类管理
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

    if ($action === 'save') {
        $id = postInt('id');
        $data = [
            'parent_id' => postInt('parent_id'),
            'name' => post('name'),
            'slug' => post('slug'),
            'image' => post('image'),
            'description' => post('description'),
            'seo_title' => post('seo_title'),
            'seo_keywords' => post('seo_keywords'),
            'seo_description' => post('seo_description'),
            'sort_order' => postInt('sort_order'),
            'status' => postInt('status', 1),
        ];

        if (empty($data['name'])) {
            error('请输入分类名称');
        }

        $data['slug'] = resolveSlug($data['slug'], $data['name'], 'product_categories', $id);

        if ($id > 0) {
            productCategoryModel()->updateById($id, $data);
            adminLog('product_category', 'update', "更新产品分类ID: $id");
        } else {
            $data['created_at'] = time();
            $id = productCategoryModel()->create($data);
            adminLog('product_category', 'create', "创建产品分类ID: $id");
        }

        success(['id' => $id]);
    }

    if ($action === 'delete') {
        $id = postInt('id');

        // 检查是否有子分类
        if (productCategoryModel()->count(['parent_id' => $id]) > 0) {
            error('请先删除子分类');
        }

        // 检查是否有产品
        if (productModel()->count(['category_id' => $id]) > 0) {
            error('该分类下有产品，无法删除');
        }

        productCategoryModel()->deleteById($id);
        adminLog('product_category', 'delete', "删除产品分类ID: $id");
        success();
    }

    if ($action === 'toggle_status') {
        $id = postInt('id');
        $newStatus = productCategoryModel()->toggle($id, 'status');
        adminLog('product_category', 'update', "切换产品分类状态ID: $id");
        success(['status' => $newStatus]);
    }

    if ($action === 'batch_delete') {
        $ids = $_POST['ids'] ?? [];
        if (empty($ids)) error('请选择要删除的分类');

        $failed = [];
        $deleted = 0;
        foreach ($ids as $id) {
            $id = (int)$id;
            if (productCategoryModel()->count(['parent_id' => $id]) > 0) {
                $cat = productCategoryModel()->find($id);
                $failed[] = ($cat['name'] ?? "ID:$id") . '（有子分类）';
                continue;
            }
            if (productModel()->count(['category_id' => $id]) > 0) {
                $cat = productCategoryModel()->find($id);
                $failed[] = ($cat['name'] ?? "ID:$id") . '（有产品）';
                continue;
            }
            productCategoryModel()->deleteById($id);
            $deleted++;
        }

        adminLog('product_category', 'batch_delete', "批量删除产品分类: {$deleted}条");

        if (!empty($failed)) {
            success(['deleted' => $deleted, 'failed' => $failed]);
        }
        success(['deleted' => $deleted]);
    }

    exit;
}

// 获取分类树
$categories = productCategoryModel()->getFlatOptions();

// 获取每个分类的产品数量
$productCounts = [];
$countRows = db()->fetchAll(
    "SELECT category_id, COUNT(*) as cnt FROM " . DB_PREFIX . "products GROUP BY category_id"
);
foreach ($countRows as $row) {
    $productCounts[(int)$row['category_id']] = (int)$row['cnt'];
}

$pageTitle = '产品分类';
$currentMenu = 'product_category';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/product.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300">产品列表</a>
        <a href="/admin/product_category.php" class="px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary">分类管理</a>
        <a href="/admin/product_setting.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300">产品设置</a>
    </div>
</div>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex justify-between items-center">
        <div id="batchBar" class="hidden items-center gap-3">
            <span class="text-sm text-gray-500">已选 <span id="selectedCount" class="font-medium text-gray-800">0</span> 项</span>
            <button onclick="batchDelete()" class="text-red-600 hover:text-red-800 text-sm inline-flex items-center gap-1">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                批量删除</button>
        </div>
        <div id="batchPlaceholder"></div>
        <button onclick="openEditModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            添加分类
        </button>
    </div>
</div>

<!-- 列表 -->
<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="w-10 px-4 py-3"><input type="checkbox" id="checkAll" class="rounded" onchange="toggleAll(this)"></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">分类名称</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">产品数</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">排序</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">状态</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($categories as $item):
                    $count = $productCounts[(int)$item['id']] ?? 0;
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3"><input type="checkbox" class="row-check rounded" value="<?php echo $item['id']; ?>" onchange="updateBatchBar()"></td>
                    <td class="px-4 py-3">
                        <span class="text-gray-400"><?php echo $item['_prefix']; ?></span>
                        <span class="font-medium"><?php echo e($item['name']); ?></span>
                        <?php if (!empty($item['slug'])): ?>
                        <code class="text-xs bg-gray-100 px-2 py-1 rounded ml-2"><?php echo e($item['slug']); ?></code>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($count > 0): ?>
                        <a href="/admin/product.php?category_id=<?php echo $item['id']; ?>" class="text-primary hover:underline text-sm"><?php echo $count; ?></a>
                        <?php else: ?>
                        <span class="text-gray-400 text-sm">0</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-gray-500"><?php echo $item['sort_order']; ?></td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleStatus(<?php echo $item['id']; ?>, this)"
                                class="text-xs px-2 py-1 rounded cursor-pointer <?php echo $item['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo $item['status'] ? '启用' : '禁用'; ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick='openEditModal(<?php echo json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                class="text-primary hover:underline text-sm mr-2 inline-flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                            编辑</button>
                        <button onclick="deleteCategory(<?php echo $item['id']; ?>)"
                                class="text-red-600 hover:underline text-sm inline-flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            删除</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($categories)): ?>
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-gray-500">暂无分类</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 编辑弹窗 -->
<div id="editModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeModal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b flex justify-between items-center sticky top-0 bg-white">
            <h3 class="font-bold text-gray-800" id="modalTitle">添加分类</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form id="editForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="editId" value="0">

            <div>
                <label class="block text-gray-700 mb-1">上级分类</label>
                <select name="parent_id" id="editParentId" class="w-full border rounded px-4 py-2">
                    <option value="0">顶级分类</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>"><?php echo $cat['_prefix'] . e($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-gray-700 mb-1">分类名称 <span class="text-red-500">*</span></label>
                <input type="text" name="name" id="editName" required class="w-full border rounded px-4 py-2">
            </div>

            <div>
                <label class="block text-gray-700 mb-1">URL别名 (Slug)</label>
                <input type="text" name="slug" id="editSlug" class="w-full border rounded px-4 py-2" placeholder="如：smart-device，留空自动生成">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1">排序</label>
                    <input type="number" name="sort_order" id="editSortOrder" value="0" class="w-full border rounded px-4 py-2">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1">状态</label>
                    <select name="status" id="editStatus" class="w-full border rounded px-4 py-2">
                        <option value="1">启用</option>
                        <option value="0">禁用</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-gray-700 mb-1">分类图片</label>
                <div class="flex gap-2">
                    <input type="text" name="image" id="editImage" class="flex-1 border rounded px-4 py-2">
                    <button type="button" onclick="uploadImage()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded"><?php echo __('admin_choose_file'); ?></button>
                    <button type="button" onclick="pickImageFromMedia()" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded">媒体库</button>
                </div>
                <div id="imagePreview" class="mt-2"></div>
            </div>

            <div>
                <label class="block text-gray-700 mb-1">分类描述</label>
                <textarea name="description" id="editDescription" rows="2" class="w-full border rounded px-4 py-2"></textarea>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <button type="button" onclick="closeModal()" class="border px-4 py-2 rounded hover:bg-gray-100">取消</button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded inline-flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    保存</button>
            </div>
        </form>
    </div>
</div>

<input type="file" id="imageFileInput" class="hidden" accept="image/*">

<script>
function openEditModal(item = null) {
    document.getElementById('modalTitle').textContent = item ? '编辑分类' : '添加分类';
    document.getElementById('editId').value = item?.id || 0;
    document.getElementById('editParentId').value = item?.parent_id || 0;
    document.getElementById('editName').value = item?.name || '';
    document.getElementById('editSlug').value = item?.slug || '';
    document.getElementById('editSortOrder').value = item?.sort_order || 0;
    document.getElementById('editStatus').value = item?.status ?? 1;
    document.getElementById('editImage').value = item?.image || '';
    document.getElementById('editDescription').value = item?.description || '';
    document.getElementById('editModal').classList.remove('hidden');
}

function closeModal() {
    document.getElementById('editModal').classList.add('hidden');
}

document.getElementById('editForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('保存成功');
        setTimeout(() => location.reload(), 1000);
    } else {
        showMessage(data.msg, 'error');
    }
});

async function deleteCategory(id) {
    if (!confirm('确定要删除该分类吗？')) return;
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

// 全选 / 批量操作
function toggleAll(master) {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = master.checked);
    updateBatchBar();
}

function updateBatchBar() {
    const checked = document.querySelectorAll('.row-check:checked');
    const bar = document.getElementById('batchBar');
    const placeholder = document.getElementById('batchPlaceholder');
    document.getElementById('selectedCount').textContent = checked.length;
    if (checked.length > 0) {
        bar.classList.remove('hidden');
        bar.classList.add('flex');
        placeholder.classList.add('hidden');
    } else {
        bar.classList.add('hidden');
        bar.classList.remove('flex');
        placeholder.classList.remove('hidden');
    }
    // 同步全选框状态
    const all = document.querySelectorAll('.row-check');
    document.getElementById('checkAll').checked = all.length > 0 && checked.length === all.length;
}

async function batchDelete() {
    const ids = [...document.querySelectorAll('.row-check:checked')].map(cb => cb.value);
    if (!ids.length) return;
    if (!confirm(`确定要删除选中的 ${ids.length} 个分类吗？`)) return;

    const formData = new FormData();
    formData.append('action', 'batch_delete');
    ids.forEach(id => formData.append('ids[]', id));
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        let msg = `成功删除 ${data.data.deleted} 个分类`;
        if (data.data.failed && data.data.failed.length) {
            msg += `\n以下分类无法删除：\n${data.data.failed.join('\n')}`;
        }
        showMessage(msg);
        setTimeout(() => location.reload(), 1200);
    } else {
        showMessage(data.msg, 'error');
    }
}

async function toggleStatus(id, btn) {
    const formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        if (data.data.status) {
            btn.className = 'text-xs px-2 py-1 rounded cursor-pointer bg-green-100 text-green-600';
            btn.textContent = '启用';
        } else {
            btn.className = 'text-xs px-2 py-1 rounded cursor-pointer bg-gray-100 text-gray-500';
            btn.textContent = '禁用';
        }
    }
}

function uploadImage() {
    document.getElementById('imageFileInput').click();
}

function pickImageFromMedia() {
    openMediaPicker(function(url) {
        document.getElementById('editImage').value = url;
        var preview = document.getElementById('imagePreview');
        if (preview) {
            preview.innerHTML = '<img src="' + url + '" class="h-16 rounded">';
        }
    });
}

document.getElementById('imageFileInput').addEventListener('change', async function() {
    if (!this.files[0]) return;
    const formData = new FormData();
    formData.append('file', this.files[0]);
    formData.append('type', 'images');
    try {
        const response = await fetch('/admin/upload.php', { method: 'POST', body: formData });
        const data = await safeJson(response);
        if (data.code === 0) {
            document.getElementById('editImage').value = data.data.url;
            showMessage('上传成功');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('上传失败', 'error');
    }
    this.value = '';
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
