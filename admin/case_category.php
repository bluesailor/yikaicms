<?php
/**
 * YikaiCMS - 案例分类管理
 *
 * 案例分类本质是 type='case' 的栏目（每个分类是一个有独立页面/SEO 的栏目）。
 * 本页是「案例」下聚焦的分类管理器：只操作 type=case 的栏目，按站点默认语言。
 * 复杂的跨语言翻译仍在「栏目管理」里做。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('edit_case');

$srcLang = (string) config('site_lang', 'zh-CN');

// 处理 AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'save') {
        $id = postInt('id');
        $data = [
            'parent_id'   => postInt('parent_id'),
            'name'        => post('name'),
            'slug'        => post('slug'),
            'type'        => 'case',
            'image'       => post('image'),
            'description' => post('description'),
            'sort_order'  => postInt('sort_order'),
            'status'      => postInt('status', 1),
            'is_nav'      => !empty($_POST['is_nav']) ? 1 : 0,
            'updated_at'  => time(),
        ];

        if (empty($data['name'])) {
            error(__('pcat_name_required'));
        }

        // 上级只能是 case 栏目；非法则回退顶级
        if ($data['parent_id'] > 0) {
            $p = channelModel()->find($data['parent_id']);
            if (!$p || ($p['type'] ?? '') !== 'case') $data['parent_id'] = 0;
        }

        $data['slug'] = resolveSlug($data['slug'], $data['name'], 'channels', $id);

        if ($id > 0) {
            $existing = channelModel()->find($id);
            if (!$existing || ($existing['type'] ?? '') !== 'case') {
                error(__('ccat_invalid'));
            }
            channelModel()->updateById($id, $data);
            adminLog('case_category', 'update', "更新案例分类ID: $id");
        } else {
            $data['lang'] = $srcLang;   // 显式写语言，避免依赖 DB 默认（lang 陷阱）
            $data['created_at'] = time();
            $id = channelModel()->create($data);
            adminLog('case_category', 'create', "创建案例分类ID: $id");
        }

        success(['id' => $id]);
    }

    if ($action === 'delete') {
        $id = postInt('id');
        if (channelModel()->count(['parent_id' => $id, 'type' => 'case']) > 0) {
            error(__('pcat_has_children'));
        }
        $cnt = (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM " . contentModel()->tableName() . " WHERE channel_id = ? AND type = 'case' AND deleted_at IS NULL",
            [$id]
        );
        if ($cnt > 0) {
            error(__('ccat_has_cases'));
        }
        channelModel()->deleteById($id);
        adminLog('case_category', 'delete', "删除案例分类ID: $id");
        success();
    }

    if ($action === 'toggle_status') {
        $id = postInt('id');
        $newStatus = channelModel()->toggle($id, 'status');
        adminLog('case_category', 'update', "切换案例分类状态ID: $id");
        success(['status' => $newStatus]);
    }

    if ($action === 'toggle_nav') {
        $id = postInt('id');
        $newVal = channelModel()->toggle($id, 'is_nav');
        adminLog('case_category', 'update', "切换案例分类导航ID: $id is_nav=$newVal");
        success(['is_nav' => $newVal]);
    }

    if ($action === 'batch_delete') {
        $ids = $_POST['ids'] ?? [];
        if (empty($ids)) error(__('pcat_pick_delete'));
        $failed = []; $deleted = 0;
        foreach ($ids as $rid) {
            $rid = (int) $rid;
            if (channelModel()->count(['parent_id' => $rid, 'type' => 'case']) > 0) {
                $c = channelModel()->find($rid);
                $failed[] = ($c['name'] ?? "ID:$rid") . '（' . __('pcat_reason_children') . '）';
                continue;
            }
            $cnt = (int) db()->fetchColumn(
                "SELECT COUNT(*) FROM " . contentModel()->tableName() . " WHERE channel_id = ? AND type = 'case' AND deleted_at IS NULL",
                [$rid]
            );
            if ($cnt > 0) {
                $c = channelModel()->find($rid);
                $failed[] = ($c['name'] ?? "ID:$rid") . '（' . __('ccat_reason_cases') . '）';
                continue;
            }
            channelModel()->deleteById($rid);
            $deleted++;
        }
        adminLog('case_category', 'batch_delete', "批量删除案例分类: {$deleted}条");
        success(['deleted' => $deleted, 'failed' => $failed]);
    }

    exit;
}

// ---- 列出源语言的 case 栏目，构建带缩进前缀的扁平树 ----
$rows = channelModel()->where(['type' => 'case', 'lang' => $srcLang], 'sort_order ASC, id ASC');
$byParent = [];
foreach ($rows as $r) { $byParent[(int) $r['parent_id']][] = $r; }
$categories = [];
$seen = [];
$walk = function (int $parentId, int $depth) use (&$walk, &$categories, &$seen, $byParent) {
    foreach ($byParent[$parentId] ?? [] as $r) {
        if (isset($seen[(int) $r['id']])) continue;
        $seen[(int) $r['id']] = true;
        $r['_prefix'] = $depth > 0 ? str_repeat('　— ', $depth) : '';
        $categories[] = $r;
        $walk((int) $r['id'], $depth + 1);
    }
};
$walk(0, 0);
// 兜底：父级不在本语言集合里的孤立 case 栏目也列出来
foreach ($rows as $r) {
    if (!isset($seen[(int) $r['id']])) { $r['_prefix'] = ''; $categories[] = $r; }
}

// 每个分类的案例数
$caseCounts = [];
foreach (db()->fetchAll(
    "SELECT channel_id, COUNT(*) as cnt FROM " . contentModel()->tableName()
    . " WHERE type = 'case' AND deleted_at IS NULL GROUP BY channel_id"
) as $row) {
    $caseCounts[(int) $row['channel_id']] = (int) $row['cnt'];
}

$pageTitle = __('case_tab_category');
$currentMenu = 'case';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/case.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300"><?php echo __('case_tab_list'); ?></a>
        <a href="/admin/case_category.php" class="px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary"><?php echo __('case_tab_category'); ?></a>
    </div>
</div>

<div class="bg-blue-50 border border-blue-200 text-blue-800 text-sm rounded-lg px-4 py-3 mb-4">
    <i class="ti ti-info-circle mr-1"></i><?php echo e(__('ccat_notice')); ?>
</div>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex justify-between items-center">
        <div id="batchBar" class="hidden items-center gap-3">
            <span class="text-sm text-gray-500"><?php echo str_replace(':n', '<span id="selectedCount" class="font-medium text-gray-800">0</span>', e(__('admin_selected_n'))); ?><span class="hidden"> 项</span>
            <button onclick="batchDelete()" class="text-red-600 hover:text-red-800 text-sm inline-flex items-center gap-1">
                <i class="ti ti-trash text-sm"></i>批量<?php echo __('admin_delete'); ?></button>
        </div>
        <div id="batchPlaceholder"></div>
        <button onclick="openEditModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
            <i class="ti ti-plus text-base"></i><?php echo __('admin_category_add'); ?>
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
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_name'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_count'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_sort_order'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_status'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('pcat_col_nav')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_action'); ?></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($categories as $item):
                    $count = $caseCounts[(int) $item['id']] ?? 0;
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
                        <a href="/admin/case.php?channel_id=<?php echo $item['id']; ?>" class="text-primary hover:underline text-sm"><?php echo $count; ?></a>
                        <?php else: ?>
                        <span class="text-gray-400 text-sm">0</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-gray-500"><?php echo $item['sort_order']; ?></td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleStatus(<?php echo $item['id']; ?>, this)"
                                class="text-xs px-2 py-1 rounded cursor-pointer <?php echo $item['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo $item['status'] ? __('admin_enabled') : __('admin_disabled'); ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleNav(<?php echo $item['id']; ?>, this)"
                                class="text-xs px-2 py-1 rounded cursor-pointer <?php echo !empty($item['is_nav']) ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-400'; ?>"
                                title="<?php echo e(__('pcat_nav_toggle_tip')); ?>">
                            <?php echo !empty($item['is_nav']) ? e(__('admin_show')) : e(__('admin_hide')); ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick='openEditModal(<?php echo json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                class="text-primary hover:underline text-sm mr-2 inline-flex items-center gap-1">
                            <i class="ti ti-pencil text-sm"></i><?php echo __('admin_edit'); ?></button>
                        <button onclick="deleteCategory(<?php echo $item['id']; ?>)"
                                class="text-red-600 hover:underline text-sm inline-flex items-center gap-1">
                            <i class="ti ti-trash text-sm"></i><?php echo __('admin_delete'); ?></button>
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
<div id="editModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeModal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b flex justify-between items-center sticky top-0 bg-white">
            <h3 class="font-bold text-gray-800" id="modalTitle"><?php echo __('admin_category_add'); ?></h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form id="editForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="editId" value="0">

            <div>
                <label class="block text-gray-700 mb-1"><?php echo e(__('pcat_parent')); ?></label>
                <select name="parent_id" id="editParentId" class="w-full border rounded px-4 py-2">
                    <option value="0"><?php echo __('admin_none'); ?></option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>"><?php echo $cat['_prefix'] . e($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo __('admin_name'); ?> <span class="text-red-500">*</span></label>
                <input type="text" name="name" id="editName" required class="w-full border rounded px-4 py-2">
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo __('admin_slug'); ?> (Slug)</label>
                <input type="text" name="slug" id="editSlug" class="w-full border rounded px-4 py-2" placeholder="<?php echo e(__('ptag_slug_ph')); ?>">
            </div>

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_sort_order'); ?></label>
                    <input type="number" name="sort_order" id="editSortOrder" value="0" class="w-full border rounded px-4 py-2">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_status'); ?></label>
                    <select name="status" id="editStatus" class="w-full border rounded px-4 py-2">
                        <option value="1"><?php echo __('admin_enabled'); ?></option>
                        <option value="0"><?php echo __('admin_disabled'); ?></option>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo e(__('pcat_nav_show')); ?></label>
                    <select name="is_nav" id="editIsNav" class="w-full border rounded px-4 py-2">
                        <option value="1"><?php echo e(__('admin_show')); ?></option>
                        <option value="0"><?php echo e(__('admin_hide')); ?></option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo __('admin_image'); ?></label>
                <div class="flex gap-2">
                    <input type="text" name="image" id="editImage" class="flex-1 border rounded px-4 py-2">
                    <button type="button" onclick="pickImageFromMedia()" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded"><?php echo __('admin_media_library'); ?></button>
                </div>
                <div id="imagePreview" class="mt-2"></div>
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo __('admin_description'); ?></label>
                <textarea name="description" id="editDescription" rows="2" class="w-full border rounded px-4 py-2"></textarea>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <button type="button" onclick="closeModal()" class="border px-4 py-2 rounded hover:bg-gray-100"><?php echo __('admin_cancel'); ?></button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded inline-flex items-center gap-1">
                    <i class="ti ti-check text-base"></i><?php echo __('admin_save'); ?></button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(item = null) {
    document.getElementById('modalTitle').textContent = item ? '<?php echo __('admin_edit'); ?>' : '<?php echo __('admin_category_add'); ?>';
    document.getElementById('editId').value = item?.id || 0;
    document.getElementById('editParentId').value = item?.parent_id || 0;
    document.getElementById('editName').value = item?.name || '';
    document.getElementById('editSlug').value = item?.slug || '';
    document.getElementById('editSortOrder').value = item?.sort_order || 0;
    document.getElementById('editStatus').value = item?.status ?? 1;
    document.getElementById('editIsNav').value = (item && (item.is_nav === 0 || item.is_nav === '0')) ? 0 : 1;
    document.getElementById('editImage').value = item?.image || '';
    document.getElementById('editDescription').value = item?.description || '';
    document.getElementById('editModal').classList.remove('hidden');
}
function closeModal() { document.getElementById('editModal').classList.add('hidden'); }

document.getElementById('editForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const data = await safeJson(await fetch('', { method: 'POST', body: new FormData(this) }));
    if (data.code === 0) { showMessage('<?php echo __('admin_saved'); ?>'); setTimeout(() => location.reload(), 800); }
    else showMessage(data.msg, 'error');
});

async function deleteCategory(id) {
    if (!confirm('<?php echo __('admin_confirm_delete'); ?>')) return;
    const fd = new FormData(); fd.append('action', 'delete'); fd.append('id', id);
    const data = await safeJson(await fetch('', { method: 'POST', body: fd }));
    if (data.code === 0) { showMessage('<?php echo __('admin_deleted'); ?>'); setTimeout(() => location.reload(), 800); }
    else showMessage(data.msg, 'error');
}

function toggleAll(master) { document.querySelectorAll('.row-check').forEach(cb => cb.checked = master.checked); updateBatchBar(); }
function updateBatchBar() {
    const checked = document.querySelectorAll('.row-check:checked');
    const bar = document.getElementById('batchBar'), placeholder = document.getElementById('batchPlaceholder');
    document.getElementById('selectedCount').textContent = checked.length;
    if (checked.length > 0) { bar.classList.remove('hidden'); bar.classList.add('flex'); placeholder.classList.add('hidden'); }
    else { bar.classList.add('hidden'); bar.classList.remove('flex'); placeholder.classList.remove('hidden'); }
    const all = document.querySelectorAll('.row-check');
    document.getElementById('checkAll').checked = all.length > 0 && checked.length === all.length;
}
async function batchDelete() {
    const ids = [...document.querySelectorAll('.row-check:checked')].map(cb => cb.value);
    if (!ids.length) return;
    if (!confirm(<?php echo json_encode(__('pcat_batch_confirm'), JSON_UNESCAPED_UNICODE); ?>.replace(':n', ids.length))) return;
    const fd = new FormData(); fd.append('action', 'batch_delete'); ids.forEach(id => fd.append('ids[]', id));
    const data = await safeJson(await fetch('', { method: 'POST', body: fd }));
    if (data.code === 0) {
        let msg = <?php echo json_encode(__('pcat_batch_done'), JSON_UNESCAPED_UNICODE); ?>.replace(':n', data.data.deleted);
        if (data.data.failed && data.data.failed.length) msg += '\n' + <?php echo json_encode(__('pcat_batch_failed'), JSON_UNESCAPED_UNICODE); ?> + '\n' + data.data.failed.join('\n');
        showMessage(msg); setTimeout(() => location.reload(), 1000);
    } else showMessage(data.msg, 'error');
}
async function toggleStatus(id, btn) {
    const fd = new FormData(); fd.append('action', 'toggle_status'); fd.append('id', id);
    const data = await safeJson(await fetch('', { method: 'POST', body: fd }));
    if (data.code === 0) {
        if (data.data.status) { btn.className = 'text-xs px-2 py-1 rounded cursor-pointer bg-green-100 text-green-600'; btn.textContent = '<?php echo __('admin_enabled'); ?>'; }
        else { btn.className = 'text-xs px-2 py-1 rounded cursor-pointer bg-gray-100 text-gray-500'; btn.textContent = '<?php echo __('admin_disabled'); ?>'; }
    }
}
async function toggleNav(id, btn) {
    const fd = new FormData(); fd.append('action', 'toggle_nav'); fd.append('id', id);
    const data = await safeJson(await fetch('', { method: 'POST', body: fd }));
    if (data.code === 0) {
        if (data.data.is_nav) { btn.className = 'text-xs px-2 py-1 rounded cursor-pointer bg-blue-100 text-blue-600'; btn.textContent = <?php echo json_encode(__('admin_show'), JSON_UNESCAPED_UNICODE); ?>; }
        else { btn.className = 'text-xs px-2 py-1 rounded cursor-pointer bg-gray-100 text-gray-400'; btn.textContent = <?php echo json_encode(__('admin_hide'), JSON_UNESCAPED_UNICODE); ?>; }
    }
}
function pickImageFromMedia() {
    openMediaPicker(function (url) {
        document.getElementById('editImage').value = url;
        const preview = document.getElementById('imagePreview');
        if (preview) preview.innerHTML = '<img src="' + url + '" class="h-16 rounded">';
    });
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
