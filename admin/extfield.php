<?php
/**
 * Yikai CMS - 扩展字段管理
 *
 * 按 owner_type (content/product) 维护扩展字段定义。
 * 字段值通过 MetaModel 存入 yikai_metas。
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

$ownerType = get('owner_type', 'content');
if (!in_array($ownerType, ['content', 'product'], true)) {
    $ownerType = 'content';
}

// 处理 AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'save') {
        $id = postInt('id');
        $data = [
            'owner_type'  => post('owner_type', 'content'),
            'field_key'   => post('field_key'),
            'field_name'  => post('field_name'),
            'field_type'  => post('field_type', 'text'),
            'options'     => post('options'),
            'placeholder' => post('placeholder'),
            'help_text'   => post('help_text'),
            'is_required' => postInt('is_required'),
            'sort_order'  => postInt('sort_order'),
            'status'      => postInt('status', 1),
        ];

        if (!in_array($data['owner_type'], ['content', 'product'], true)) {
            error('非法的 owner_type');
        }
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $data['field_key'])) {
            error('字段标识必须以小写字母开头，仅允许小写字母/数字/下划线，长度 2-64');
        }
        if (empty($data['field_name'])) {
            error('请输入字段名称');
        }
        if (!array_key_exists($data['field_type'], ExtFieldModel::TYPES)) {
            error('非法的字段类型');
        }
        if (!extFieldModel()->isFieldKeyUnique($data['owner_type'], $data['field_key'], $id)) {
            error('字段标识已存在');
        }

        if ($id > 0) {
            extFieldModel()->updateById($id, $data);
            adminLog('extfield', 'update', "更新扩展字段: {$data['field_key']}");
        } else {
            $data['created_at'] = time();
            $id = extFieldModel()->create($data);
            adminLog('extfield', 'create', "创建扩展字段: {$data['field_key']}");
        }
        success(['id' => $id]);
    }

    if ($action === 'delete') {
        $id = postInt('id');
        extFieldModel()->deleteById($id);
        adminLog('extfield', 'delete', "删除扩展字段ID: $id");
        success();
    }

    if ($action === 'toggle_status') {
        $id = postInt('id');
        $newStatus = extFieldModel()->toggle($id, 'status');
        success(['status' => $newStatus]);
    }

    exit;
}

$fields = extFieldModel()->getByOwner($ownerType, false);
$pageTitle = '扩展字段';
$currentMenu = 'extfield';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Owner 切换 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="?owner_type=content" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $ownerType === 'content' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>"><?php echo __('extfield_content'); ?></a>
        <a href="?owner_type=product" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $ownerType === 'product' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700'; ?>"><?php echo __('extfield_product'); ?></a>
    </div>
    <div class="p-4 flex justify-end">
        <button onclick="openEditModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            添加字段
        </button>
    </div>
</div>

<!-- 字段列表 -->
<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('label_sort_order'); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">标识</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">名称</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">类型</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">必填</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">状态</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($fields as $f): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500"><?php echo (int)$f['sort_order']; ?></td>
                    <td class="px-4 py-3 font-mono text-sm"><?php echo e($f['field_key']); ?></td>
                    <td class="px-4 py-3"><?php echo e($f['field_name']); ?></td>
                    <td class="px-4 py-3"><span class="text-xs px-2 py-0.5 rounded bg-gray-100 text-gray-600"><?php echo e(ExtFieldModel::TYPES[$f['field_type']] ?? $f['field_type']); ?></span></td>
                    <td class="px-4 py-3 text-center"><?php echo $f['is_required'] ? '<span class="text-red-500">是</span>' : '否'; ?></td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleStatus(<?php echo (int)$f['id']; ?>, this)"
                                class="text-xs px-2 py-1 rounded <?php echo $f['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo $f['status'] ? '启用' : '停用'; ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick='openEditModal(<?php echo json_encode($f, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' class="text-primary hover:underline text-sm mr-2">编辑</button>
                        <button onclick="deleteField(<?php echo (int)$f['id']; ?>)" class="text-red-600 hover:underline text-sm">删除</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($fields)): ?>
                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500"><?php echo __('extfield_empty'); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 编辑弹窗 -->
<div id="editModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeModal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h3 class="font-bold text-gray-800" id="modalTitle">添加扩展字段</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form id="editForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="editId" value="0">
            <input type="hidden" name="owner_type" value="<?php echo e($ownerType); ?>">

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('extfield_key'); ?> <span class="text-red-500">*</span></label>
                    <input type="text" name="field_key" id="editKey" required class="w-full border rounded px-4 py-2 font-mono" placeholder="e.g. material">
                    <p class="text-xs text-gray-400 mt-1">小写字母开头，字母/数字/下划线</p>
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('extfield_name'); ?> <span class="text-red-500">*</span></label>
                    <input type="text" name="field_name" id="editName" required class="w-full border rounded px-4 py-2" placeholder="e.g. 材质">
                </div>
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo __('extfield_type'); ?></label>
                <select name="field_type" id="editType" class="w-full border rounded px-4 py-2">
                    <?php foreach (ExtFieldModel::TYPES as $k => $v): ?>
                    <option value="<?php echo e($k); ?>"><?php echo e($v); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-gray-700 mb-1">选项 (select/multi_select 专用)</label>
                <textarea name="options" id="editOptions" rows="3" class="w-full border rounded px-4 py-2 font-mono text-xs" placeholder='JSON 格式: {"red":"红色","blue":"蓝色"} 或每行 key|label'></textarea>
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo __('extfield_placeholder'); ?></label>
                <input type="text" name="placeholder" id="editPlaceholder" class="w-full border rounded px-4 py-2">
            </div>

            <div>
                <label class="block text-gray-700 mb-1">说明文字</label>
                <input type="text" name="help_text" id="editHelp" class="w-full border rounded px-4 py-2">
            </div>

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_sort_order'); ?></label>
                    <input type="number" name="sort_order" id="editSort" value="0" class="w-full border rounded px-4 py-2">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1">必填</label>
                    <select name="is_required" id="editRequired" class="w-full border rounded px-4 py-2">
                        <option value="0">否</option>
                        <option value="1">是</option>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-700 mb-1">状态</label>
                    <select name="status" id="editStatus" class="w-full border rounded px-4 py-2">
                        <option value="1">启用</option>
                        <option value="0">停用</option>
                    </select>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-4 border-t">
                <button type="button" onclick="closeModal()" class="border px-4 py-2 rounded hover:bg-gray-100">取消</button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded">保存</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(item) {
    document.getElementById('modalTitle').textContent = item ? '编辑扩展字段' : '添加扩展字段';
    document.getElementById('editId').value = item?.id || 0;
    document.getElementById('editKey').value = item?.field_key || '';
    document.getElementById('editName').value = item?.field_name || '';
    document.getElementById('editType').value = item?.field_type || 'text';
    document.getElementById('editOptions').value = item?.options || '';
    document.getElementById('editPlaceholder').value = item?.placeholder || '';
    document.getElementById('editHelp').value = item?.help_text || '';
    document.getElementById('editSort').value = item?.sort_order || 0;
    document.getElementById('editRequired').value = item?.is_required || 0;
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
        setTimeout(() => location.reload(), 800);
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
            btn.textContent = '启用';
        } else {
            btn.className = 'text-xs px-2 py-1 rounded bg-gray-100 text-gray-500';
            btn.textContent = '停用';
        }
    }
}

async function deleteField(id) {
    if (!confirm('确定要删除该扩展字段吗？已保存的字段值不会被删除。')) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('删除成功');
        setTimeout(() => location.reload(), 800);
    } else {
        showMessage(data.msg, 'error');
    }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
