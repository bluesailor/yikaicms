<?php
/**
 * YikaiCMS - 文章分类管理
 *
 * 文章分类本质是栏目：默认是 news 栏目下的子栏目；插件接管（admin_article_categories 过滤器）时
 * 就是插件给出的那些栏目。本页按当前视图语言管理名称、上级、排序、显示与导航；
 * SEO、模板等其余设置仍在「栏目管理」里（每行的「更多设置」直达）。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('edit_article');

$_lang     = adminLangView();
$_viewLang = (string) $_lang['view'];

$tree       = adminArticleCategoryTree($_viewLang);
$categories = $tree['rows'];
$rootId     = (int) $tree['root'];
$byId       = [];
foreach ($categories as $row) $byId[(int) $row['id']] = $row;

/** 某分类及其在分类树里的全部下级（防止把分类挂到自己下面） */
$subtreeIds = static function (int $id) use ($categories): array {
    $ids = [$id => true];
    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($categories as $row) {
            $rid = (int) $row['id'];
            if (!isset($ids[$rid]) && isset($ids[(int) ($row['parent_id'] ?? 0)])) { $ids[$rid] = true; $changed = true; }
        }
    }
    return $ids;
};
$articleCount = static fn(int $channelId): int => (int) db()->fetchColumn(
    'SELECT COUNT(*) FROM ' . contentModel()->tableName() . ' WHERE channel_id = ? AND deleted_at IS NULL',
    [$channelId]
);
$childCount = static fn(int $channelId): int => channelModel()->count(['parent_id' => $channelId]);

// 处理 AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');
    if (!$tree['plugin'] && $rootId <= 0) error(__('acat_no_news'));

    if ($action === 'save') {
        $id = postInt('id');
        if ($id > 0 && !isset($byId[$id])) error(__('ccat_invalid'));

        $parentId = postInt('parent_id');
        if ($parentId !== $rootId && !isset($byId[$parentId])) $parentId = $rootId;
        if ($id > 0 && isset($subtreeIds($id)[$parentId])) $parentId = $rootId;

        $data = [
            'parent_id'   => $parentId,
            'name'        => trim((string) post('name')),
            'slug'        => post('slug'),
            'image'       => post('image'),
            'description' => post('description'),
            'sort_order'  => postInt('sort_order'),
            'status'      => postInt('status', 1) ? 1 : 0,
            'is_nav'      => !empty($_POST['is_nav']) ? 1 : 0,
            'updated_at'  => time(),
        ];
        if ($data['name'] === '') error(__('pcat_name_required'));
        $data['slug'] = resolveSlug((string) $data['slug'], $data['name'], 'channels', $id);

        if ($id > 0) {
            channelModel()->updateById($id, $data);
            adminLog('article_category', 'update', "更新文章分类ID: $id");
        } else {
            $data['type'] = 'list';
            $data['lang'] = $_viewLang;   // 显式写语言，避免依赖 DB 默认
            $data['created_at'] = time();
            $id = channelModel()->create($data);
            adminLog('article_category', 'create', "创建文章分类ID: $id");
        }
        success(['id' => $id]);
    }

    if ($action === 'delete') {
        $id = postInt('id');
        if (!isset($byId[$id])) error(__('ccat_invalid'));
        if ($childCount($id) > 0) error(__('pcat_has_children'));
        if ($articleCount($id) > 0) error(__('acat_has_articles'));
        channelModel()->deleteById($id);
        adminLog('article_category', 'delete', "删除文章分类ID: $id");
        success();
    }

    if ($action === 'toggle_status' || $action === 'toggle_nav') {
        $id = postInt('id');
        if (!isset($byId[$id])) error(__('ccat_invalid'));
        $field = $action === 'toggle_nav' ? 'is_nav' : 'status';
        $value = channelModel()->toggle($id, $field);
        adminLog('article_category', 'update', "切换文章分类{$field} ID: $id");
        success([$field => $value]);
    }

    if ($action === 'batch_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids) || !$ids) error(__('pcat_pick_delete'));
        $failed = [];
        $deleted = 0;
        foreach ($ids as $rid) {
            $rid = (int) $rid;
            if (!isset($byId[$rid])) continue;
            $name = (string) $byId[$rid]['name'];
            if ($childCount($rid) > 0) { $failed[] = $name . '（' . __('pcat_reason_children') . '）'; continue; }
            if ($articleCount($rid) > 0) { $failed[] = $name . '（' . __('acat_reason_articles') . '）'; continue; }
            channelModel()->deleteById($rid);
            $deleted++;
        }
        adminLog('article_category', 'batch_delete', "批量删除文章分类: {$deleted}条");
        success(['deleted' => $deleted, 'failed' => $failed]);
    }

    exit;
}

// 每个分类的文章数（不含回收站）
$counts = [];
if ($categories) {
    $ids = array_map(static fn(array $r): int => (int) $r['id'], $categories);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    foreach (db()->fetchAll(
        'SELECT channel_id, COUNT(*) AS cnt FROM ' . contentModel()->tableName()
        . " WHERE deleted_at IS NULL AND channel_id IN ({$placeholders}) GROUP BY channel_id",
        $ids
    ) as $row) {
        $counts[(int) $row['channel_id']] = (int) $row['cnt'];
    }
}
$rootName = '';
if ($rootId > 0) {
    $rootRow = channelModel()->find($rootId);
    $rootName = (string) ($rootRow['name'] ?? '');
}

// 多语言：默认语言下每行显示各语言的翻译状态（点击到栏目管理里看译文或新建翻译）
require_once ROOT_PATH . '/admin/includes/trans_pills.php';
$isSourceLang = $_viewLang === (string) config('site_lang', 'zh-CN');
$transStatus = $isSourceLang ? loadTransStatus('channels') : [];

$pageTitle = __('article_tab_category');
$currentMenu = 'article';

require_once ROOT_PATH . '/admin/includes/header.php';
require ROOT_PATH . '/admin/includes/workflow_nav.php';
?>

<?php echo renderAdminLangSwitcher($_viewLang); ?>

<div class="bg-blue-50 border border-blue-200 text-blue-800 text-sm rounded-lg px-4 py-3 mb-4">
    <i class="ti ti-info-circle mr-1"></i><?php echo e(__($tree['plugin'] ? 'acat_notice_plugin' : 'acat_notice')); ?>
</div>

<?php if (!$tree['plugin'] && $rootId <= 0): ?>
<div class="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-lg px-4 py-3 mb-4" data-testid="article-category-no-news">
    <i class="ti ti-alert-triangle mr-1"></i><?php echo e(__('acat_no_news')); ?>
</div>
<?php else: ?>

<?php /* 工具栏 */ ?>
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex justify-between items-center">
        <div id="batchBar" class="hidden items-center gap-3">
            <span class="text-sm text-gray-500"><?php echo str_replace(':n', '<span id="selectedCount" class="font-medium text-gray-800">0</span>', e(__('admin_selected_n'))); ?></span>
            <button onclick="batchDelete()" class="text-red-600 hover:text-red-800 text-sm inline-flex items-center gap-1">
                <i class="ti ti-trash text-sm"></i><?php echo e(__('admin_batch_delete')); ?></button>
        </div>
        <div id="batchPlaceholder"></div>
        <button onclick="openEditModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1" data-testid="article-category-add">
            <i class="ti ti-plus text-base"></i><?php echo e(__('admin_category_add')); ?>
        </button>
    </div>
</div>

<?php /* 列表 */ ?>
<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="w-full admin-workflow-table" data-testid="article-category-table">
            <thead class="bg-gray-50">
                <tr>
                    <th class="w-10 px-4 py-3"><input type="checkbox" id="checkAll" class="rounded" onchange="toggleAll(this)" aria-label="<?php echo e(__('admin_select_all')); ?>"></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo e(__('admin_name')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('admin_count')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('admin_sort_order')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('admin_status')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('pcat_col_nav')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('admin_action')); ?></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($categories as $item):
                    $count = $counts[(int) $item['id']] ?? 0;
                    $editData = array_intersect_key($item, array_flip(['id', 'parent_id', 'name', 'slug', 'image', 'description', 'sort_order', 'status', 'is_nav']));
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3"><input type="checkbox" class="row-check rounded" value="<?php echo (int) $item['id']; ?>" onchange="updateBatchBar()" aria-label="<?php echo e((string) $item['name']); ?>"></td>
                    <td class="px-4 py-3">
                        <span class="text-gray-400"><?php echo $item['_prefix']; ?></span>
                        <span class="font-medium"><?php echo e((string) $item['name']); ?></span>
                        <?php if (!empty($item['slug'])): ?>
                        <code class="text-xs bg-gray-100 px-2 py-1 rounded ml-2"><?php echo e((string) $item['slug']); ?></code>
                        <?php endif; ?>
                        <?php if ($isSourceLang): ?>
                        <span class="ml-2 align-middle" data-testid="article-category-trans"><?php echo renderTransPills((int) $item['id'], $transStatus, '/admin/channel.php', 'edit'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($count > 0): ?>
                        <a href="/admin/article.php?lang=<?php echo rawurlencode($_viewLang); ?>&amp;channel_id=<?php echo (int) $item['id']; ?>" class="text-primary hover:underline text-sm"><?php echo $count; ?></a>
                        <?php else: ?>
                        <span class="text-gray-400 text-sm">0</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-gray-500"><?php echo (int) ($item['sort_order'] ?? 0); ?></td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleField(<?php echo (int) $item['id']; ?>, 'status', this)"
                                class="text-xs px-2 py-1 rounded cursor-pointer <?php echo !empty($item['status']) ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo e(!empty($item['status']) ? __('admin_enabled') : __('admin_disabled')); ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleField(<?php echo (int) $item['id']; ?>, 'is_nav', this)"
                                class="text-xs px-2 py-1 rounded cursor-pointer <?php echo !empty($item['is_nav']) ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-400'; ?>"
                                title="<?php echo e(__('pcat_nav_toggle_tip')); ?>">
                            <?php echo e(!empty($item['is_nav']) ? __('admin_show') : __('admin_hide')); ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center whitespace-nowrap">
                        <button onclick='openEditModal(<?php echo json_encode($editData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                class="text-primary hover:underline text-sm mr-2 inline-flex items-center gap-1">
                            <i class="ti ti-pencil text-sm"></i><?php echo e(__('admin_edit')); ?></button>
                        <a href="/admin/channel.php?edit=<?php echo (int) $item['id']; ?>" class="text-gray-600 hover:text-primary hover:underline text-sm mr-2 inline-flex items-center gap-1">
                            <i class="ti ti-settings text-sm"></i><?php echo e(__('acat_more')); ?></a>
                        <button onclick="deleteCategory(<?php echo (int) $item['id']; ?>)"
                                class="text-red-600 hover:underline text-sm inline-flex items-center gap-1">
                            <i class="ti ti-trash text-sm"></i><?php echo e(__('admin_delete')); ?></button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($categories)): ?>
                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500"><?php echo e(__('admin_no_data')); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php /* 编辑弹窗 */ ?>
<div id="editModal" class="fixed inset-0 z-50 hidden" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="absolute inset-0 bg-black/50" onclick="closeModal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b flex justify-between items-center sticky top-0 bg-white">
            <h3 class="font-bold text-gray-800" id="modalTitle"><?php echo e(__('admin_category_add')); ?></h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600" aria-label="<?php echo e(__('admin_cancel')); ?>">&times;</button>
        </div>
        <form id="editForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="editId" value="0">

            <div>
                <label class="block text-gray-700 mb-1" for="editParentId"><?php echo e(__('pcat_parent')); ?></label>
                <select name="parent_id" id="editParentId" class="w-full border rounded px-4 py-2">
                    <option value="<?php echo $rootId; ?>"><?php echo e($rootName !== '' ? $rootName : __('admin_none')); ?></option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo (int) $cat['id']; ?>"><?php echo $cat['_prefix'] . e((string) $cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-gray-700 mb-1" for="editName"><?php echo e(__('admin_name')); ?> <span class="text-red-500">*</span></label>
                <input type="text" name="name" id="editName" required class="w-full border rounded px-4 py-2">
            </div>

            <div>
                <label class="block text-gray-700 mb-1" for="editSlug"><?php echo e(__('admin_slug')); ?> (Slug)</label>
                <input type="text" name="slug" id="editSlug" class="w-full border rounded px-4 py-2" placeholder="<?php echo e(__('ptag_slug_ph')); ?>">
            </div>

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1" for="editSortOrder"><?php echo e(__('label_sort_order')); ?></label>
                    <input type="number" name="sort_order" id="editSortOrder" value="0" class="w-full border rounded px-4 py-2">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1" for="editStatus"><?php echo e(__('label_status')); ?></label>
                    <select name="status" id="editStatus" class="w-full border rounded px-4 py-2">
                        <option value="1"><?php echo e(__('admin_enabled')); ?></option>
                        <option value="0"><?php echo e(__('admin_disabled')); ?></option>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-700 mb-1" for="editIsNav"><?php echo e(__('pcat_nav_show')); ?></label>
                    <select name="is_nav" id="editIsNav" class="w-full border rounded px-4 py-2">
                        <option value="1"><?php echo e(__('admin_show')); ?></option>
                        <option value="0"><?php echo e(__('admin_hide')); ?></option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-gray-700 mb-1" for="editImage"><?php echo e(__('admin_image')); ?></label>
                <div class="flex gap-2">
                    <input type="text" name="image" id="editImage" class="flex-1 border rounded px-4 py-2">
                    <button type="button" onclick="pickImageFromMedia()" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded"><?php echo e(__('admin_media_library')); ?></button>
                </div>
                <div id="imagePreview" class="mt-2"></div>
            </div>

            <div>
                <label class="block text-gray-700 mb-1" for="editDescription"><?php echo e(__('admin_description')); ?></label>
                <textarea name="description" id="editDescription" rows="2" class="w-full border rounded px-4 py-2"></textarea>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <button type="button" onclick="closeModal()" class="border px-4 py-2 rounded hover:bg-gray-100"><?php echo e(__('admin_cancel')); ?></button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded inline-flex items-center gap-1">
                    <i class="ti ti-check text-base"></i><?php echo e(__('admin_save')); ?></button>
            </div>
        </form>
    </div>
</div>

<script>
const ACAT = {
    root: <?php echo $rootId; ?>,
    t: <?php echo json_encode([
        'add' => __('admin_category_add'), 'edit' => __('admin_edit'), 'saved' => __('admin_saved'), 'deleted' => __('admin_deleted'),
        'confirm' => __('admin_confirm_delete'), 'batchConfirm' => __('pcat_batch_confirm'), 'batchDone' => __('pcat_batch_done'),
        'batchFailed' => __('pcat_batch_failed'), 'enabled' => __('admin_enabled'), 'disabled' => __('admin_disabled'),
        'show' => __('admin_show'), 'hide' => __('admin_hide'),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>,
};
function openEditModal(item = null) {
    document.getElementById('modalTitle').textContent = item ? ACAT.t.edit : ACAT.t.add;
    document.getElementById('editId').value = item?.id || 0;
    document.getElementById('editParentId').value = item ? (item.parent_id || ACAT.root) : ACAT.root;
    if (document.getElementById('editParentId').selectedIndex < 0) document.getElementById('editParentId').value = ACAT.root;
    document.getElementById('editName').value = item?.name || '';
    document.getElementById('editSlug').value = item?.slug || '';
    document.getElementById('editSortOrder').value = item?.sort_order || 0;
    document.getElementById('editStatus').value = item?.status ?? 1;
    document.getElementById('editIsNav').value = (item && (item.is_nav === 0 || item.is_nav === '0')) ? 0 : 1;
    document.getElementById('editImage').value = item?.image || '';
    document.getElementById('editDescription').value = item?.description || '';
    document.getElementById('imagePreview').innerHTML = '';
    document.getElementById('editModal').classList.remove('hidden');
    document.getElementById('editName').focus();
}
function closeModal() { document.getElementById('editModal').classList.add('hidden'); }
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });

document.getElementById('editForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const data = await safeJson(await fetch('', { method: 'POST', body: new FormData(this) }));
    if (data.code === 0) { showMessage(ACAT.t.saved); setTimeout(() => location.reload(), 800); }
    else showMessage(data.msg, 'error');
});

async function deleteCategory(id) {
    if (!confirm(ACAT.t.confirm)) return;
    const fd = new FormData(); fd.append('action', 'delete'); fd.append('id', id);
    const data = await safeJson(await fetch('', { method: 'POST', body: fd }));
    if (data.code === 0) { showMessage(ACAT.t.deleted); setTimeout(() => location.reload(), 800); }
    else showMessage(data.msg, 'error');
}

function toggleAll(master) { document.querySelectorAll('.row-check').forEach(cb => cb.checked = master.checked); updateBatchBar(); }
function updateBatchBar() {
    const checked = document.querySelectorAll('.row-check:checked');
    const bar = document.getElementById('batchBar'), placeholder = document.getElementById('batchPlaceholder');
    document.getElementById('selectedCount').textContent = checked.length;
    bar.classList.toggle('hidden', checked.length === 0); bar.classList.toggle('flex', checked.length > 0);
    placeholder.classList.toggle('hidden', checked.length > 0);
    const all = document.querySelectorAll('.row-check');
    document.getElementById('checkAll').checked = all.length > 0 && checked.length === all.length;
}
async function batchDelete() {
    const ids = [...document.querySelectorAll('.row-check:checked')].map(cb => cb.value);
    if (!ids.length || !confirm(ACAT.t.batchConfirm.replace(':n', ids.length))) return;
    const fd = new FormData(); fd.append('action', 'batch_delete'); ids.forEach(id => fd.append('ids[]', id));
    const data = await safeJson(await fetch('', { method: 'POST', body: fd }));
    if (data.code === 0) {
        let msg = ACAT.t.batchDone.replace(':n', data.data.deleted);
        if (data.data.failed && data.data.failed.length) msg += '\n' + ACAT.t.batchFailed + '\n' + data.data.failed.join('\n');
        showMessage(msg); setTimeout(() => location.reload(), 1000);
    } else showMessage(data.msg, 'error');
}
async function toggleField(id, field, btn) {
    const fd = new FormData(); fd.append('action', field === 'is_nav' ? 'toggle_nav' : 'toggle_status'); fd.append('id', id);
    const data = await safeJson(await fetch('', { method: 'POST', body: fd }));
    if (data.code !== 0) { showMessage(data.msg, 'error'); return; }
    const on = !!Number(data.data[field]);
    if (field === 'is_nav') {
        btn.className = 'text-xs px-2 py-1 rounded cursor-pointer ' + (on ? 'bg-blue-100 text-blue-600' : 'bg-gray-100 text-gray-400');
        btn.textContent = on ? ACAT.t.show : ACAT.t.hide;
    } else {
        btn.className = 'text-xs px-2 py-1 rounded cursor-pointer ' + (on ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500');
        btn.textContent = on ? ACAT.t.enabled : ACAT.t.disabled;
    }
}
function pickImageFromMedia() {
    openMediaPicker(function (url) {
        document.getElementById('editImage').value = url;
        const preview = document.getElementById('imagePreview');
        preview.innerHTML = '';
        const img = document.createElement('img'); img.src = url; img.className = 'h-16 rounded'; preview.appendChild(img);
    });
}
</script>
<?php endif; ?>

<?php adminModuleEnd(); ?>
<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
