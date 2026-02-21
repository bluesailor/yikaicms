<?php
/**
 * Yikai CMS - 合作伙伴管理
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('link');

// 处理 AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'save') {
        $id = postInt('id');
        $data = [
            'name' => post('name'),
            'url' => post('url'),
            'logo' => post('logo'),
            'description' => post('description'),
            'sort_order' => postInt('sort_order', 0),
            'status' => postInt('status', 1),
        ];

        if (empty($data['name'])) {
            error('请输入链接名称');
        }

        if (empty($data['url'])) {
            error('请输入链接地址');
        }

        // 自动添加协议
        if (!preg_match('#^https?://#i', $data['url'])) {
            $data['url'] = 'https://' . $data['url'];
        }

        if ($id > 0) {
            linkModel()->updateById($id, $data);
            adminLog('link', 'update', "更新友链ID: $id");
        } else {
            $data['created_at'] = time();
            $id = linkModel()->create($data);
            adminLog('link', 'create', "创建友链ID: $id");
        }

        success(['id' => $id]);
    }

    if ($action === 'delete') {
        $id = postInt('id');
        linkModel()->deleteById($id);
        adminLog('link', 'delete', "删除友链ID: $id");
        success();
    }

    if ($action === 'toggle_status') {
        $id = postInt('id');
        $newStatus = linkModel()->toggle($id, 'status');
        success(['status' => $newStatus]);
    }

    if ($action === 'sort') {
        $ids = $_POST['ids'] ?? [];
        linkModel()->updateSort($ids);
        success();
    }

    exit;
}

// 获取列表
$links = linkModel()->all('sort_order ASC, id DESC');

$pageTitle = __('admin_link');
$currentMenu = 'link';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex justify-end">
        <button onclick="openEditModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            添加链接
        </button>
    </div>
</div>

<!-- 列表 -->
<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">排序</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Logo</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">名称</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">链接</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">状态</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y" id="sortableList">
                <?php foreach ($links as $item): ?>
                <tr class="hover:bg-gray-50" data-id="<?php echo $item['id']; ?>">
                    <td class="px-4 py-3">
                        <span class="cursor-move text-gray-400">&#9776;</span>
                    </td>
                    <td class="px-4 py-3">
                        <?php if ($item['logo']): ?>
                        <img src="<?php echo e($item['logo']); ?>" class="h-8 w-auto">
                        <?php else: ?>
                        <span class="text-gray-400">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 font-medium"><?php echo e($item['name']); ?></td>
                    <td class="px-4 py-3">
                        <a href="<?php echo e($item['url']); ?>" target="_blank" class="text-primary hover:underline">
                            <?php echo e(cutStr($item['url'], 40)); ?>
                        </a>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleStatus(<?php echo $item['id']; ?>, this)"
                                class="text-xs px-2 py-1 rounded <?php echo $item['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo $item['status'] ? '显示' : '隐藏'; ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick='openEditModal(<?php echo json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                class="text-primary hover:underline text-sm mr-2 inline-flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                            编辑</button>
                        <button onclick="deleteLink(<?php echo $item['id']; ?>)"
                                class="text-red-600 hover:underline text-sm inline-flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            删除</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($links)): ?>
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-gray-500">暂无数据</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 编辑弹窗 -->
<div id="editModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeModal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h3 class="font-bold text-gray-800" id="modalTitle">添加链接</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form id="editForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="editId" value="0">

            <div>
                <label class="block text-gray-700 mb-1">名称 <span class="text-red-500">*</span></label>
                <input type="text" name="name" id="editName" required class="w-full border rounded px-4 py-2">
            </div>

            <div>
                <label class="block text-gray-700 mb-1">链接 <span class="text-red-500">*</span></label>
                <input type="text" name="url" id="editUrl" required class="w-full border rounded px-4 py-2" placeholder="https://example.com">
                <p class="text-xs text-gray-400 mt-1">请输入完整链接，包含 http:// 或 https://</p>
            </div>

            <div>
                <label class="block text-gray-700 mb-1">Logo</label>
                <div class="flex gap-2">
                    <input type="text" name="logo" id="editLogo" class="flex-1 border rounded px-4 py-2">
                    <button type="button" onclick="uploadLogo()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded"><?php echo __('admin_choose_file'); ?></button>
                    <button type="button" onclick="pickLogoFromMedia()" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded">媒体库</button>
                </div>
                <div id="logoPreview" class="mt-2"></div>
            </div>

            <div>
                <label class="block text-gray-700 mb-1">描述</label>
                <input type="text" name="description" id="editDescription" class="w-full border rounded px-4 py-2">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1">排序</label>
                    <input type="number" name="sort_order" id="editSortOrder" value="0" class="w-full border rounded px-4 py-2">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1">状态</label>
                    <select name="status" id="editStatus" class="w-full border rounded px-4 py-2">
                        <option value="1">显示</option>
                        <option value="0">隐藏</option>
                    </select>
                </div>
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

<input type="file" id="logoFileInput" class="hidden" accept="image/*">

<script src="/assets/sortable/Sortable.min.js"></script>
<script>
// 拖拽排序
new Sortable(document.getElementById('sortableList'), {
    animation: 150,
    handle: '.cursor-move',
    onEnd: async function() {
        const ids = [...document.querySelectorAll('#sortableList tr[data-id]')].map(el => el.dataset.id);
        const formData = new FormData();
        formData.append('action', 'sort');
        ids.forEach(id => formData.append('ids[]', id));
        await fetch('', { method: 'POST', body: formData });
    }
});

function openEditModal(item = null) {
    document.getElementById('modalTitle').textContent = item ? '编辑链接' : '添加链接';
    document.getElementById('editId').value = item?.id || 0;
    document.getElementById('editName').value = item?.name || '';
    document.getElementById('editUrl').value = item?.url || '';
    document.getElementById('editLogo').value = item?.logo || '';
    document.getElementById('editDescription').value = item?.description || '';
    document.getElementById('editSortOrder').value = item?.sort_order || 0;
    document.getElementById('editStatus').value = item?.status ?? 1;
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

async function toggleStatus(id, btn) {
    const formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        if (data.data.status) {
            btn.className = 'text-xs px-2 py-1 rounded bg-green-100 text-green-600';
            btn.textContent = '显示';
        } else {
            btn.className = 'text-xs px-2 py-1 rounded bg-gray-100 text-gray-500';
            btn.textContent = '隐藏';
        }
    }
}

async function deleteLink(id) {
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

function uploadLogo() {
    document.getElementById('logoFileInput').click();
}

function pickLogoFromMedia() {
    openMediaPicker(function(url) {
        document.getElementById('editLogo').value = url;
        var preview = document.getElementById('logoPreview');
        if (preview) {
            preview.innerHTML = '<img src="' + url + '" class="h-10 rounded">';
        }
    });
}

document.getElementById('logoFileInput').addEventListener('change', async function() {
    if (!this.files[0]) return;

    const formData = new FormData();
    formData.append('file', this.files[0]);
    formData.append('type', 'images');

    try {
        const response = await fetch('/admin/upload.php', { method: 'POST', body: formData });
        const data = await safeJson(response);

        if (data.code === 0) {
            document.getElementById('editLogo').value = data.data.url;
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
