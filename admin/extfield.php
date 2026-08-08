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
requirePermission('*');

$ownerType = get('owner_type', 'content');
if (!in_array($ownerType, extFieldOwnerTypes(), true)) {
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

        if (!in_array($data['owner_type'], extFieldOwnerTypes(), true)) {
            error(__('ef_bad_owner'));
        }
        if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $data['field_key'])) {
            error(__('ef_key_format'));
        }
        if (empty($data['field_name'])) {
            error(__('ef_name_required'));
        }
        if (!array_key_exists($data['field_type'], ExtFieldModel::TYPES)) {
            error(__('ef_bad_type'));
        }
        if (!extFieldModel()->isFieldKeyUnique($data['owner_type'], $data['field_key'], $id)) {
            error(__('ef_key_exists'));
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
$pageTitle = __('ef_title');
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
            <i class="ti ti-plus text-base"></i>
            <?php echo e(__('ef_add')); ?>
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
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo e(__('bn_slug')); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo e(__('label_name')); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo e(__('scontact_col_type')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('ef_required')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('label_status')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('admin_actions')); ?></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($fields as $f): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500"><?php echo (int)$f['sort_order']; ?></td>
                    <td class="px-4 py-3 font-mono text-sm"><?php echo e($f['field_key']); ?></td>
                    <td class="px-4 py-3"><?php echo e($f['field_name']); ?></td>
                    <td class="px-4 py-3"><span class="text-xs px-2 py-0.5 rounded bg-gray-100 text-gray-600"><?php echo e(ExtFieldModel::TYPES[$f['field_type']] ?? $f['field_type']); ?></span></td>
                    <td class="px-4 py-3 text-center"><?php echo $f['is_required'] ? '<span class="text-red-500">' . e(__('setting_yes')) . '</span>' : '否'; ?></td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleStatus(<?php echo (int)$f['id']; ?>, this)"
                                class="text-xs px-2 py-1 rounded <?php echo $f['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo $f['status'] ? e(__('admin_enabled')) : e(__('ef_disabled')); ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick='openEditModal(<?php echo json_encode($f, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)' class="text-primary hover:underline text-sm mr-2"><?php echo e(__('edit')); ?></button>
                        <button onclick="deleteField(<?php echo (int)$f['id']; ?>)" class="text-red-600 hover:underline text-sm"><?php echo e(__('admin_delete')); ?></button>
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
            <h3 class="font-bold text-gray-800" id="modalTitle"><?php echo e(__('ef_add')); ?></h3>
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
                    <p class="text-xs text-gray-400 mt-1"><?php echo e(__('ef_key_tip')); ?></p>
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('extfield_name'); ?> <span class="text-red-500">*</span></label>
                    <input type="text" name="field_name" id="editName" required class="w-full border rounded px-4 py-2" placeholder="<?php echo e(__('ef_name_ph')); ?>">
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
                <label class="block text-gray-700 mb-1"><?php echo e(__('ef_options')); ?></label>
                <textarea name="options" id="editOptions" rows="3" class="w-full border rounded px-4 py-2 font-mono text-xs" placeholder='<?php echo e(__('ef_options_ph')); ?>' ></textarea>
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo __('extfield_placeholder'); ?></label>
                <input type="text" name="placeholder" id="editPlaceholder" class="w-full border rounded px-4 py-2">
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo e(__('ef_help_text')); ?></label>
                <input type="text" name="help_text" id="editHelp" class="w-full border rounded px-4 py-2">
            </div>

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_sort_order'); ?></label>
                    <input type="number" name="sort_order" id="editSort" value="0" class="w-full border rounded px-4 py-2">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo e(__('ef_required')); ?></label>
                    <select name="is_required" id="editRequired" class="w-full border rounded px-4 py-2">
                        <option value="0"><?php echo e(__('setting_no')); ?></option>
                        <option value="1"><?php echo e(__('setting_yes')); ?></option>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo e(__('label_status')); ?></label>
                    <select name="status" id="editStatus" class="w-full border rounded px-4 py-2">
                        <option value="1"><?php echo e(__('admin_enabled')); ?></option>
                        <option value="0"><?php echo e(__('ef_disabled')); ?></option>
                    </select>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-4 border-t">
                <button type="button" onclick="closeModal()" class="border px-4 py-2 rounded hover:bg-gray-100"><?php echo e(__('cancel')); ?></button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded"><?php echo e(__('btn_save')); ?></button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(item) {
    document.getElementById('modalTitle').textContent = item ? <?php echo json_encode(__('ef_edit'), JSON_UNESCAPED_UNICODE); ?> : <?php echo json_encode(__('ef_add'), JSON_UNESCAPED_UNICODE); ?>;
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
        showMessage(<?php echo json_encode(__('save_success'), JSON_UNESCAPED_UNICODE); ?>);
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
            btn.textContent = <?php echo json_encode(__('admin_enabled'), JSON_UNESCAPED_UNICODE); ?>;
        } else {
            btn.className = 'text-xs px-2 py-1 rounded bg-gray-100 text-gray-500';
            btn.textContent = <?php echo json_encode(__('ef_disabled'), JSON_UNESCAPED_UNICODE); ?>;
        }
    }
}

async function deleteField(id) {
    if (!confirm(<?php echo json_encode(__('ef_del_confirm'), JSON_UNESCAPED_UNICODE); ?>)) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage(<?php echo json_encode(__('admin_deleted'), JSON_UNESCAPED_UNICODE); ?>);
        setTimeout(() => location.reload(), 800);
    } else {
        showMessage(data.msg, 'error');
    }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
