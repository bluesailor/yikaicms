<?php
/**
 * YikaiCMS - 合作伙伴管理
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
            'url' => safeUrl((string) post('url')),   // 入库即过滤伪协议（输出侧亦有防护，双重保险）
            'logo' => post('logo'),
            'description' => post('description'),
            'sort_order' => postInt('sort_order', 0),
            'status' => postInt('status', 1),
        ];

        if (empty($data['name'])) {
            error(__('link_name_required'));
        }

        if (empty($data['url'])) {
            error(__('link_url_required'));
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

// 视图语言
$_lang        = adminLangView();
$_defaultLang = $_lang['default'];
$_viewLang    = $_lang['view'];
$_enabledList = $_lang['enabled'];
$_langLabels  = availableLanguages();

// 获取列表（按 view-lang 过滤）
$links = array_values(array_filter(
    linkModel()->all('sort_order ASC, id DESC'),
    fn($l) => ($l['lang'] ?? $_defaultLang) === $_viewLang
));

$pageTitle = __('admin_link');
$currentMenu = 'link';

require_once ROOT_PATH . '/admin/includes/trans_pills.php';
$transStatus = loadTransStatus('links');
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<?php echo renderAdminLangSwitcher($_viewLang, __('link_lang_hint')); ?>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex justify-end">
        <button onclick="openEditModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
            <i class="ti ti-plus text-base"></i>
            <?php echo __('admin_add'); ?>
        </button>
    </div>
</div>

<!-- 列表 -->
<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_sort_order'); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('link_logo'); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_name'); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo e(__('link_url')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_status'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_action'); ?></th>
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
                        <a href="<?php echo e(safeUrl((string) ($item['url'] ?? ''))); ?>" target="_blank" class="text-primary hover:underline">
                            <?php echo e(cutStr($item['url'], 40)); ?>
                        </a>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleStatus(<?php echo $item['id']; ?>, this)"
                                class="text-xs px-2 py-1 rounded <?php echo $item['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo $item['status'] ? __('admin_show') : __('admin_hide'); ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center whitespace-nowrap">
                        <button onclick='openEditModal(<?php echo json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                class="text-primary hover:underline text-sm mr-2 inline-flex items-center gap-1">
                            <i class="ti ti-pencil text-sm"></i>
                            <?php echo __('admin_edit'); ?></button>
                        <button onclick="deleteLink(<?php echo $item['id']; ?>)"
                                class="text-red-600 hover:underline text-sm inline-flex items-center gap-1">
                            <i class="ti ti-trash text-sm"></i>
                            <?php echo __('admin_delete'); ?></button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($links)): ?>
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-gray-500"><?php echo __('admin_no_data'); ?></td>
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
            <h3 class="font-bold text-gray-800" id="modalTitle"><?php echo __('link_add'); ?></h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 hover:bg-gray-100 text-xl leading-none w-8 h-8 rounded-full flex items-center justify-center transition-colors">&times;</button>
        </div>
        <form id="editForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="editId" value="0">

            <div>
                <label class="block text-gray-700 mb-1"><?php echo e(__('admin_name')); ?> <span class="text-red-500">*</span></label>
                <input type="text" name="name" id="editName" required class="w-full border rounded px-4 py-2">
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo e(__('link_url')); ?> <span class="text-red-500">*</span></label>
                <input type="text" name="url" id="editUrl" required class="w-full border rounded px-4 py-2" placeholder="https://example.com">
                <p class="text-xs text-gray-400 mt-1"><?php echo e(__('link_url_hint')); ?></p>
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo __('link_logo'); ?></label>
                <div class="flex gap-2">
                    <input type="text" name="logo" id="editLogo" class="flex-1 border rounded px-4 py-2">
                    <button type="button" onclick="uploadLogo()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded shrink-0 whitespace-nowrap"><?php echo __('admin_choose_file'); ?></button>
                    <button type="button" onclick="pickLogoFromMedia()" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded shrink-0 whitespace-nowrap"><?php echo __('admin_media_library'); ?></button>
                </div>
                <div id="logoPreview" class="mt-2"></div>
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo e(__('admin_description')); ?></label>
                <input type="text" name="description" id="editDescription" class="w-full border rounded px-4 py-2">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_sort_order'); ?></label>
                    <input type="number" name="sort_order" id="editSortOrder" value="0" class="w-full border rounded px-4 py-2">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_status'); ?></label>
                    <select name="status" id="editStatus" class="w-full border rounded px-4 py-2">
                        <option value="1"><?php echo __('admin_show'); ?></option>
                        <option value="0"><?php echo __('admin_hide'); ?></option>
                    </select>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <button type="button" onclick="closeModal()" class="border px-4 py-2 rounded hover:bg-gray-100"><?php echo __('admin_cancel'); ?></button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded inline-flex items-center gap-1">
                    <i class="ti ti-check text-base"></i>
                    <?php echo __('admin_save'); ?></button>
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
    document.getElementById('modalTitle').textContent = item ? '<?php echo __('admin_edit'); ?>' : '<?php echo __('admin_add'); ?>';
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
        showMessage('<?php echo __('admin_saved'); ?>');
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
            btn.textContent = <?php echo json_encode(__('admin_show'), JSON_UNESCAPED_UNICODE); ?>;
        } else {
            btn.className = 'text-xs px-2 py-1 rounded bg-gray-100 text-gray-500';
            btn.textContent = <?php echo json_encode(__('admin_hide'), JSON_UNESCAPED_UNICODE); ?>;
        }
    }
}

async function deleteLink(id) {
    if (!confirm('<?php echo __('admin_confirm_delete'); ?>')) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('<?php echo __('admin_deleted'); ?>');
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
            showMessage('<?php echo __('admin_success'); ?>');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('<?php echo __('admin_fail'); ?>', 'error');
    }

    this.value = '';
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
