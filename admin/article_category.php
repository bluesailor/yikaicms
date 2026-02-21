<?php
/**
 * Yikai CMS - 文章分类管理
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

        $data['slug'] = resolveSlug($data['slug'], $data['name'], 'article_categories', $id);

        if ($id > 0) {
            articleCategoryModel()->updateById($id, $data);
            adminLog('article_category', 'update', "更新文章分类ID: $id");
        } else {
            $data['created_at'] = time();
            $id = articleCategoryModel()->create($data);
            adminLog('article_category', 'create', "创建文章分类ID: $id");
        }

        success(['id' => $id]);
    }

    if ($action === 'delete') {
        $id = postInt('id');

        // 检查是否有子分类
        if (articleCategoryModel()->count(['parent_id' => $id]) > 0) {
            error('请先删除子分类');
        }

        // 检查是否有文章
        if (articleModel()->count(['category_id' => $id]) > 0) {
            error('该分类下有文章，无法删除');
        }

        articleCategoryModel()->deleteById($id);
        adminLog('article_category', 'delete', "删除文章分类ID: $id");
        success();
    }

    exit;
}

// 获取分类树
$categories = articleCategoryModel()->getFlatOptions();

$pageTitle = '文章分类';
$currentMenu = 'article';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/article.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300">文章列表</a>
        <a href="/admin/article_category.php" class="px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary">分类管理</a>
    </div>
</div>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex justify-end">
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
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">分类名称</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">别名 Slug</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">排序</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">状态</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($categories as $item): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500"><?php echo $item['id']; ?></td>
                    <td class="px-4 py-3">
                        <span class="text-gray-400"><?php echo $item['_prefix']; ?></span>
                        <span class="font-medium"><?php echo e($item['name']); ?></span>
                    </td>
                    <td class="px-4 py-3">
                        <?php if (!empty($item['slug'])): ?>
                        <code class="text-xs bg-gray-100 px-2 py-1 rounded"><?php echo e($item['slug']); ?></code>
                        <?php else: ?>
                        <span class="text-gray-300">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-gray-500"><?php echo $item['sort_order']; ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="text-xs px-2 py-1 rounded <?php echo $item['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo $item['status'] ? '启用' : '禁用'; ?>
                        </span>
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
                <label class="block text-gray-700 mb-1">URL 别名 (Slug)</label>
                <input type="text" name="slug" id="editSlug" class="w-full border rounded px-4 py-2" placeholder="如：company-news，留空自动生成">
                <p class="text-xs text-gray-400 mt-1">用于前台URL，仅支持字母、数字、横杠</p>
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
