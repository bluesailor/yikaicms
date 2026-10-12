<?php
/**
 * YikaiCMS - 客户评价管理（2.1.0）
 *
 * 按当前视图语言管理评价（姓名、职位与公司、评价内容、头像、评分、显示），拖动排序。
 * 前台评价轮播在「数据来源」里选「客户评价」时取这里当前语言的已显示评价。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/admin/includes/row_sort.php';

checkLogin();
requirePermission('edit_testimonial');

$_lang     = adminLangView();
$_viewLang = (string) $_lang['view'];
$tableReady = db()->tableExists('testimonials');

/** 本页可管理的行：当前视图语言的评价 */
$findRow = static function (int $id) use ($_viewLang): ?array {
    $row = testimonialModel()->find($id);
    return is_array($row) && (string) ($row['lang'] ?? '') === $_viewLang ? $row : null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$tableReady) error(__('testimonial_table_missing'));
    $action = post('action');

    if ($action === 'save') {
        $id = postInt('id');
        if ($id > 0 && $findRow($id) === null) error(__('testimonial_not_found'));
        $data = [
            'name'       => mb_substr(trim(strip_tags((string) post('name'))), 0, 100),
            'role'       => mb_substr(trim(strip_tags((string) post('role'))), 0, 200),
            'content'    => trim(strip_tags((string) post('content'))),
            'avatar'     => safeUrl(trim((string) post('avatar'))),
            'rating'     => max(1, min(5, postInt('rating', 5))),
            'status'     => postInt('status', 1) ? 1 : 0,
            'updated_at' => time(),
        ];
        if ($data['name'] === '' || $data['content'] === '') error(__('testimonial_required'));
        if ($id > 0) {
            testimonialModel()->updateById($id, $data);
            adminLog('testimonial', 'update', '更新客户评价 #' . $id);
        } else {
            $data['lang'] = $_viewLang;
            $data['sort_order'] = (int) db()->fetchColumn('SELECT COUNT(*) FROM ' . testimonialModel()->tableName() . ' WHERE lang = ?', [$_viewLang]);
            $data['created_at'] = time();
            $id = (int) testimonialModel()->create($data);
            testimonialModel()->updateById($id, ['translation_group_id' => $id]);
            adminLog('testimonial', 'create', '新建客户评价 #' . $id);
        }
        success(['id' => $id]);
    }

    if ($action === 'delete') {
        requirePermission('delete_testimonial');
        $id = postInt('id');
        if ($findRow($id) === null) error(__('testimonial_not_found'));
        testimonialModel()->deleteById($id);
        adminLog('testimonial', 'delete', '删除客户评价 #' . $id);
        success();
    }

    if ($action === 'toggle_status') {
        $id = postInt('id');
        if ($findRow($id) === null) error(__('testimonial_not_found'));
        success(['status' => testimonialModel()->toggle($id, 'status')]);
    }

    if ($action === 'sort') {
        adminRowSortApply(testimonialModel(), adminRowSortIds($findRow), 'testimonial');
    }
    exit;
}

$rows = $tableReady
    ? db()->fetchAll('SELECT * FROM ' . testimonialModel()->tableName() . ' WHERE lang = ? ORDER BY sort_order ASC, id ASC', [$_viewLang])
    : [];

$pageTitle = __('admin_testimonial');
$currentMenu = 'testimonial';
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<?php echo renderAdminLangSwitcher($_viewLang); ?>

<div class="bg-blue-50 border border-blue-200 text-blue-800 text-sm rounded-lg px-4 py-3 mb-4">
    <i class="ti ti-info-circle mr-1" aria-hidden="true"></i><?php echo e(__('testimonial_notice')); ?>
</div>

<?php if (!$tableReady): ?>
<div class="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-lg px-4 py-3 mb-4"><?php echo e(__('testimonial_table_missing')); ?></div>
<?php else: ?>

<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex justify-end">
        <button type="button" onclick="openTestimonial()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1" data-testid="testimonial-add">
            <i class="ti ti-plus text-base" aria-hidden="true"></i><?php echo e(__('testimonial_add')); ?>
        </button>
    </div>
</div>

<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="w-full admin-workflow-table" data-testid="testimonial-table">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo e(__('testimonial_name')); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo e(__('testimonial_content')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('testimonial_rating')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('admin_sort_order')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('admin_status')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('admin_action')); ?></th>
                </tr>
            </thead>
            <tbody class="divide-y" id="testimonialRows">
                <?php foreach ($rows as $row):
                    $editData = array_intersect_key($row, array_flip(['id', 'name', 'role', 'content', 'avatar', 'rating', 'status']));
                ?>
                <tr class="hover:bg-gray-50"<?php echo adminRowSortAttrs((int) $row['id'], 0); ?>>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <?php echo adminRowSortHandle(); ?>
                            <?php if ((string) $row['avatar'] !== ''): ?>
                            <img src="<?php echo e(thumbnail((string) $row['avatar'], 'thumb')); ?>" alt="" class="w-9 h-9 rounded-full object-cover shrink-0">
                            <?php endif; ?>
                            <div class="min-w-0">
                                <div class="font-medium text-gray-900"><?php echo e((string) $row['name']); ?></div>
                                <div class="text-xs text-gray-500 truncate"><?php echo e((string) $row['role']); ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600 max-w-md"><span class="line-clamp-2"><?php echo e((string) $row['content']); ?></span></td>
                    <td class="px-4 py-3 text-center text-amber-500 text-sm whitespace-nowrap" aria-label="<?php echo (int) $row['rating']; ?>/5"><?php echo str_repeat('★', (int) $row['rating']); ?></td>
                    <td class="px-4 py-3 text-center text-gray-500" data-sort-cell><?php echo (int) $row['sort_order']; ?></td>
                    <td class="px-4 py-3 text-center">
                        <button type="button" onclick="toggleTestimonial(<?php echo (int) $row['id']; ?>, this)"
                                class="text-xs px-2 py-1 rounded cursor-pointer <?php echo !empty($row['status']) ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo e(!empty($row['status']) ? __('admin_show') : __('admin_hide')); ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center whitespace-nowrap">
                        <button type="button" onclick='openTestimonial(<?php echo json_encode($editData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                class="text-primary hover:underline text-sm mr-2 inline-flex items-center gap-1"><i class="ti ti-pencil text-sm" aria-hidden="true"></i><?php echo e(__('admin_edit')); ?></button>
                        <?php if (hasPermission('delete_testimonial')): ?>
                        <button type="button" onclick="deleteTestimonial(<?php echo (int) $row['id']; ?>)"
                                class="text-red-600 hover:underline text-sm inline-flex items-center gap-1"><i class="ti ti-trash text-sm" aria-hidden="true"></i><?php echo e(__('admin_delete')); ?></button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500"><?php echo e(__('admin_no_data')); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="testimonialModal" class="fixed inset-0 z-50 hidden" role="dialog" aria-modal="true" aria-labelledby="testimonialTitle">
    <div class="absolute inset-0 bg-black/50" onclick="closeTestimonial()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b flex justify-between items-center sticky top-0 bg-white">
            <h3 class="font-bold text-gray-800" id="testimonialTitle"></h3>
            <button type="button" onclick="closeTestimonial()" class="text-gray-400 hover:text-gray-600" aria-label="<?php echo e(__('admin_cancel')); ?>">&times;</button>
        </div>
        <form id="testimonialForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="tmId" value="0">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1" for="tmName"><?php echo e(__('testimonial_name')); ?> <span class="text-red-500">*</span></label>
                    <input type="text" name="name" id="tmName" required maxlength="100" class="w-full border rounded px-3 py-2">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1" for="tmRole"><?php echo e(__('testimonial_role')); ?></label>
                    <input type="text" name="role" id="tmRole" maxlength="200" class="w-full border rounded px-3 py-2" placeholder="<?php echo e(__('testimonial_role_ph')); ?>">
                </div>
            </div>
            <div>
                <label class="block text-gray-700 mb-1" for="tmContent"><?php echo e(__('testimonial_content')); ?> <span class="text-red-500">*</span></label>
                <textarea name="content" id="tmContent" rows="4" required class="w-full border rounded px-3 py-2"></textarea>
            </div>
            <div>
                <label class="block text-gray-700 mb-1" for="tmAvatar"><?php echo e(__('testimonial_avatar')); ?></label>
                <div class="flex gap-2">
                    <input type="text" name="avatar" id="tmAvatar" class="flex-1 border rounded px-3 py-2">
                    <button type="button" onclick="openMediaPicker(function (url) { document.getElementById('tmAvatar').value = url; })"
                            class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded"><?php echo e(__('admin_media_library')); ?></button>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1" for="tmRating"><?php echo e(__('testimonial_rating')); ?></label>
                    <select name="rating" id="tmRating" class="w-full border rounded px-3 py-2">
                        <?php for ($star = 5; $star >= 1; $star--): ?>
                        <option value="<?php echo $star; ?>"><?php echo str_repeat('★', $star); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-700 mb-1" for="tmStatus"><?php echo e(__('admin_status')); ?></label>
                    <select name="status" id="tmStatus" class="w-full border rounded px-3 py-2">
                        <option value="1"><?php echo e(__('admin_show')); ?></option>
                        <option value="0"><?php echo e(__('admin_hide')); ?></option>
                    </select>
                </div>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeTestimonial()" class="border px-4 py-2 rounded hover:bg-gray-100"><?php echo e(__('admin_cancel')); ?></button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded inline-flex items-center gap-1">
                    <i class="ti ti-check text-base" aria-hidden="true"></i><?php echo e(__('admin_save')); ?></button>
            </div>
        </form>
    </div>
</div>

<?php echo adminRowSortScript('testimonialRows'); ?>
<script>
const TM = <?php echo json_encode([
    'add' => __('testimonial_add'), 'edit' => __('admin_edit'), 'saved' => __('admin_saved'), 'deleted' => __('admin_deleted'),
    'confirm' => __('admin_confirm_delete'), 'show' => __('admin_show'), 'hide' => __('admin_hide'),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
function openTestimonial(item = null) {
    document.getElementById('testimonialTitle').textContent = item ? TM.edit : TM.add;
    document.getElementById('tmId').value = item?.id || 0;
    document.getElementById('tmName').value = item?.name || '';
    document.getElementById('tmRole').value = item?.role || '';
    document.getElementById('tmContent').value = item?.content || '';
    document.getElementById('tmAvatar').value = item?.avatar || '';
    document.getElementById('tmRating').value = item?.rating || 5;
    document.getElementById('tmStatus').value = item ? (Number(item.status) ? 1 : 0) : 1;
    document.getElementById('testimonialModal').classList.remove('hidden');
    document.getElementById('tmName').focus();
}
function closeTestimonial() { document.getElementById('testimonialModal').classList.add('hidden'); }
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeTestimonial(); });
document.getElementById('testimonialForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const data = await safeJson(await fetch('', { method: 'POST', body: new FormData(this) }));
    if (data.code === 0) { showMessage(TM.saved); setTimeout(() => location.reload(), 600); }
    else showMessage(data.msg, 'error');
});
async function deleteTestimonial(id) {
    if (!confirm(TM.confirm)) return;
    const fd = new FormData(); fd.append('action', 'delete'); fd.append('id', id);
    const data = await safeJson(await fetch('', { method: 'POST', body: fd }));
    if (data.code === 0) { showMessage(TM.deleted); setTimeout(() => location.reload(), 600); }
    else showMessage(data.msg, 'error');
}
async function toggleTestimonial(id, btn) {
    const fd = new FormData(); fd.append('action', 'toggle_status'); fd.append('id', id);
    const data = await safeJson(await fetch('', { method: 'POST', body: fd }));
    if (data.code !== 0) { showMessage(data.msg, 'error'); return; }
    const on = !!Number(data.data.status);
    btn.className = 'text-xs px-2 py-1 rounded cursor-pointer ' + (on ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500');
    btn.textContent = on ? TM.show : TM.hide;
}
</script>
<?php endif; ?>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
