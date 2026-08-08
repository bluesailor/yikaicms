<?php
/**
 * YikaiCMS - 下载分类管理
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('edit_download');

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'save') {
        $id = postInt('id');
        $data = [
            'name' => post('name'),
            'description' => post('description'),
            'sort_order' => postInt('sort_order'),
            'status' => postInt('status', 1),
        ];

        if (empty($data['name'])) {
            error(__('pcat_name_required'));
        }

        // slug：留空按名称自动生成；用于伪静态 /download/{slug}.html
        // 容错：slug 列未建（未跑数据库升级）时跳过写入，避免报错（前端回退 ?cat=id）
        $hasSlugCol = true;
        try { db()->fetchOne("SELECT slug FROM " . downloadCategoryModel()->tableName() . " LIMIT 1"); }
        catch (\Throwable $e) { $hasSlugCol = false; }
        if ($hasSlugCol) {
            $data['slug'] = resolveSlug((string) post('slug'), $data['name'], 'download_categories', $id);
        }

        if ($id > 0) {
            downloadCategoryModel()->updateById($id, $data);
            adminLog('download_category', 'update', '更新分类：' . $data['name']);
        } else {
            $data['created_at'] = time();
            downloadCategoryModel()->create($data);
            adminLog('download_category', 'create', '新建分类：' . $data['name']);
        }
        success();
    }

    if ($action === 'delete') {
        $id = postInt('id');

        // 检查是否有下载文件使用此分类
        $count = downloadModel()->count(['category_id' => $id]);

        if ($count > 0) {
            error(str_replace(':n', (string) $count, __('dcat_has_files')));
        }

        downloadCategoryModel()->deleteById($id);
        adminLog('download_category', 'delete', '删除分类ID：' . $id);
        success();
    }

    if ($action === 'toggle') {
        $id = postInt('id');
        $value = postInt('value');
        downloadCategoryModel()->updateById($id, ['status' => $value]);
        success();
    }

    if ($action === 'get') {
        $id = postInt('id');
        $item = downloadCategoryModel()->find($id);
        if ($item) {
            success($item);
        } else {
            error(__('ccat_invalid'));
        }
    }

    exit;
}

// 获取所有分类
$categories = downloadCategoryModel()->getAllWithCount();

$pageTitle = __('admin_category');
$currentMenu = 'download';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex flex-wrap gap-4 items-center justify-between">
        <div class="text-gray-600">
            <?php echo str_replace(':n', (string) count($categories), e(__('dcat_total'))); ?>
        </div>
        <div class="flex gap-2">
            <a href="/admin/download.php" class="border border-gray-300 hover:bg-gray-100 px-4 py-2 rounded inline-flex items-center gap-1">
                <i class="ti ti-arrow-left text-base"></i>
                <?php echo __('admin_back'); ?>
            </a>
            <button onclick="openModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <i class="ti ti-plus text-base"></i>
                <?php echo e(__('pcat_add')); ?>
            </button>
        </div>
    </div>
</div>

<!-- 分类列表 -->
<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_name'); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo e(__('shome_f_desc')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('dcat_file_count')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_sort_order'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_status'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_action'); ?></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($categories as $item): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-sm text-gray-500"><?php echo $item['id']; ?></td>
                    <td class="px-4 py-3">
                        <span class="font-medium"><?php echo e($item['name']); ?></span>
                        <?php if (!empty($item['slug'])): ?>
                        <code class="text-xs bg-gray-100 px-2 py-1 rounded ml-2"><?php echo e($item['slug']); ?></code>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-500">
                        <?php echo e($item['description'] ?: '-'); ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($item['file_count'] > 0): ?>
                        <a href="/admin/download.php?category_id=<?php echo $item['id']; ?>" class="text-primary hover:underline">
                            <?php echo $item['file_count']; ?>
                        </a>
                        <?php else: ?>
                        <span class="text-gray-400">0</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-sm text-gray-500"><?php echo $item['sort_order']; ?></td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleStatus(<?php echo $item['id']; ?>, <?php echo $item['status'] ? 0 : 1; ?>, this)"
                                class="text-xs px-2 py-1 rounded <?php echo $item['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo $item['status'] ? __('admin_show') : __('admin_hide'); ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="editItem(<?php echo $item['id']; ?>)" class="text-primary hover:underline text-sm mr-2"><?php echo __('admin_edit'); ?></button>
                        <button onclick="deleteItem(<?php echo $item['id']; ?>, '<?php echo e($item['name']); ?>')" class="text-red-600 hover:underline text-sm"><?php echo __('admin_delete'); ?></button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($categories)): ?>
                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500"><?php echo __('admin_no_data'); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 编辑弹窗 -->
<div id="editModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h3 class="text-lg font-medium" id="modalTitle"><?php echo e(__('pcat_add')); ?></h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                <i class="ti ti-x text-lg"></i>
            </button>
        </div>
        <form id="editForm" onsubmit="saveItem(event)">
            <input type="hidden" name="id" id="formId" value="0">
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo e(__('pcat_name')); ?> <span class="text-red-500">*</span></label>
                    <input type="text" name="name" id="formName" required
                           class="w-full border rounded px-3 py-2 focus:ring-2 focus:ring-primary/20 focus:border-primary">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo e(__('admin_slug')); ?> (Slug)</label>
                    <input type="text" name="slug" id="formSlug"
                           class="w-full border rounded px-3 py-2 focus:ring-2 focus:ring-primary/20 focus:border-primary"
                           placeholder="<?php echo e(__('dcat_slug_ph')); ?>">
                    <p class="text-xs text-gray-400 mt-1"><?php echo e(__('dcat_url_tip')); ?>/download/<span class="font-mono">{slug}</span>.html</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo __('admin_description'); ?></label>
                    <input type="text" name="description" id="formDescription"
                           class="w-full border rounded px-3 py-2 focus:ring-2 focus:ring-primary/20 focus:border-primary">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo e(__('sort_order')); ?></label>
                        <input type="number" name="sort_order" id="formSortOrder" value="0"
                               class="w-full border rounded px-3 py-2 focus:ring-2 focus:ring-primary/20 focus:border-primary">
                        <p class="text-xs text-gray-400 mt-1"><?php echo e(__('dcat_sort_tip')); ?></p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo e(__('label_status')); ?></label>
                        <select name="status" id="formStatus"
                                class="w-full border rounded px-3 py-2 focus:ring-2 focus:ring-primary/20 focus:border-primary">
                            <option value="1"><?php echo __('admin_show'); ?></option>
                            <option value="0"><?php echo __('admin_hide'); ?></option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="px-6 py-4 border-t bg-gray-50 flex justify-end gap-2 rounded-b-lg">
                <button type="button" onclick="closeModal()" class="px-4 py-2 border rounded hover:bg-gray-100"><?php echo __('admin_cancel'); ?></button>
                <button type="submit" class="px-4 py-2 bg-primary text-white rounded hover:bg-secondary"><?php echo e(__('btn_save')); ?></button>
            </div>
        </form>
    </div>
</div>

<script>
const modal = document.getElementById('editModal');

function openModal() {
    document.getElementById('modalTitle').textContent = <?php echo json_encode(__('pcat_add'), JSON_UNESCAPED_UNICODE); ?>;
    document.getElementById('formId').value = 0;
    document.getElementById('formName').value = '';
    document.getElementById('formSlug').value = '';
    document.getElementById('formDescription').value = '';
    document.getElementById('formSortOrder').value = 0;
    document.getElementById('formStatus').value = 1;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeModal() {
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

async function editItem(id) {
    const formData = new FormData();
    formData.append('action', 'get');
    formData.append('id', id);

    const response = await fetch('', { method: 'POST', body: formData });
    const result = await safeJson(response);

    if (result.code === 0 && result.data) {
        const item = result.data;
        document.getElementById('modalTitle').textContent = <?php echo json_encode(__('dcat_edit'), JSON_UNESCAPED_UNICODE); ?>;
        document.getElementById('formId').value = item.id;
        document.getElementById('formName').value = item.name;
        document.getElementById('formSlug').value = item.slug || '';
        document.getElementById('formDescription').value = item.description || '';
        document.getElementById('formSortOrder').value = item.sort_order;
        document.getElementById('formStatus').value = item.status;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    } else {
        showMessage(result.msg || <?php echo json_encode(__('admin_load_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    }
}

async function saveItem(e) {
    e.preventDefault();

    const formData = new FormData(document.getElementById('editForm'));
    formData.append('action', 'save');

    const response = await fetch('', { method: 'POST', body: formData });
    const result = await safeJson(response);

    if (result.code === 0) {
        showMessage('<?php echo __('admin_saved'); ?>');
        setTimeout(() => location.reload(), 1000);
    } else {
        showMessage(result.msg || <?php echo json_encode(__('admin_save_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    }
}

async function toggleStatus(id, value, btn) {
    const formData = new FormData();
    formData.append('action', 'toggle');
    formData.append('id', id);
    formData.append('value', value);

    const response = await fetch('', { method: 'POST', body: formData });
    const result = await safeJson(response);

    if (result.code === 0) {
        location.reload();
    }
}

async function deleteItem(id, name) {
    if (!confirm(<?php echo json_encode(__('dcat_del_confirm'), JSON_UNESCAPED_UNICODE); ?>.replace(':name', name))) return;

    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);

    const response = await fetch('', { method: 'POST', body: formData });
    const result = await safeJson(response);

    if (result.code === 0) {
        showMessage('<?php echo __('admin_deleted'); ?>');
        setTimeout(() => location.reload(), 1000);
    } else {
        showMessage(result.msg || <?php echo json_encode(__('admin_delete_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    }
}

// 点击遮罩关闭
modal.addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

// ESC 关闭
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
