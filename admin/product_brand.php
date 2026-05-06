<?php
/**
 * 品牌管理
 */
declare(strict_types=1);
define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
checkLogin();
requirePermission('content');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'save') {
        $id = postInt('id');
        $data = [
            'name' => post('name'),
            'slug' => post('slug') ?: 'brand-' . time(),
            'logo' => post('logo'),
            'country' => post('country'),
            'url' => post('url'),
            'description' => $_POST['description'] ?? '',
            'sort_order' => postInt('sort_order'),
            'status' => postInt('status', 1),
        ];
        if (empty($data['name'])) error('请输入品牌名称');

        if ($id > 0) {
            db()->update('brands', $data, 'id = ?', [$id]);
        } else {
            db()->insert('brands', $data);
        }
        success();
    }

    if ($action === 'delete') {
        $id = postInt('id');
        db()->delete('brands', 'id = ?', [$id]);
        db()->execute("UPDATE " . DB_PREFIX . "products SET brand_id = 0 WHERE brand_id = ?", [$id]);
        success();
    }

    if ($action === 'batch_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            db()->execute("DELETE FROM " . DB_PREFIX . "brands WHERE id IN ({$placeholders})", $ids);
            db()->execute("UPDATE " . DB_PREFIX . "products SET brand_id = 0 WHERE brand_id IN ({$placeholders})", $ids);
        }
        success();
    }
}

$brands = db()->fetchAll("SELECT b.*, (SELECT COUNT(*) FROM " . DB_PREFIX . "products WHERE brand_id = b.id) as product_count FROM " . DB_PREFIX . "brands b ORDER BY b.sort_order ASC, b.id ASC");
$editBrand = null;
$editId = getInt('edit');
if ($editId > 0) {
    $editBrand = db()->fetchOne("SELECT * FROM " . DB_PREFIX . "brands WHERE id = ?", [$editId]);
}

$pageTitle = '品牌管理';
$currentMenu = 'product';
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/product.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent"><?php echo __('product_tab_list'); ?></a>
        <a href="/admin/product_category.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent"><?php echo __('product_tab_category'); ?></a>
        <a href="/admin/product_brand.php" class="px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary"><?php echo __('product_tab_brand'); ?></a>
        <a href="/admin/product_tag.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent"><?php echo __('product_tab_tag'); ?></a>
        <a href="/admin/product_setting.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent"><?php echo __('product_tab_setting'); ?></a>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- 品牌列表 -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b flex justify-between items-center">
                <h3 class="font-bold">品牌列表 <span class="text-gray-400 font-normal">(<?php echo count($brands); ?>)</span></h3>
            </div>
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left"><input type="checkbox" id="checkAll"></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500">品牌</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500">产地</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500"><?php echo __('admin_count'); ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500"><?php echo __('admin_sort_order'); ?></th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500"><?php echo __('admin_action'); ?></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($brands as $b): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3"><input type="checkbox" name="ids[]" value="<?php echo $b['id']; ?>"></td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <?php if ($b['logo']): ?>
                                <img src="<?php echo e($b['logo']); ?>" class="w-8 h-8 object-contain">
                                <?php endif; ?>
                                <span class="font-medium"><?php echo e($b['name']); ?></span>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-gray-500"><?php echo e($b['country'] ?: '-'); ?></td>
                        <td class="px-4 py-3 text-center"><span class="text-xs bg-blue-100 text-blue-600 px-2 py-0.5 rounded"><?php echo $b['product_count']; ?></span></td>
                        <td class="px-4 py-3 text-center text-sm text-gray-500"><?php echo $b['sort_order']; ?></td>
                        <td class="px-4 py-3 text-center">
                            <a href="?edit=<?php echo $b['id']; ?>" class="text-blue-500 hover:text-blue-700 text-sm mr-2"><svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg> <?php echo __('admin_edit'); ?></a>
                            <button onclick="deleteBrand(<?php echo $b['id']; ?>)" class="text-red-500 hover:text-red-700"><svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="px-4 py-3 border-t">
                <button type="button" onclick="batchDeleteBrands()" class="border px-3 py-1 rounded text-sm hover:bg-red-50 text-red-600 inline-flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                    <?php echo __('admin_batch_delete'); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- 添加/编辑 -->
    <div>
        <div class="bg-white rounded-lg shadow p-6">
            <h3 class="font-bold mb-4"><?php echo $editBrand ? '编辑品牌' : '添加品牌'; ?></h3>
            <form id="brandForm" class="space-y-4">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo $editBrand['id'] ?? 0; ?>">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">品牌名称 *</label>
                    <input type="text" name="name" value="<?php echo e($editBrand['name'] ?? ''); ?>" required class="w-full border rounded px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">国家/产地</label>
                    <input type="text" name="country" value="<?php echo e($editBrand['country'] ?? ''); ?>" placeholder="如：日本、德国" class="w-full border rounded px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Logo</label>
                    <div class="flex gap-2">
                        <input type="text" name="logo" id="brandLogo" value="<?php echo e($editBrand['logo'] ?? ''); ?>" class="flex-1 border rounded px-3 py-2 text-sm">
                        <label class="px-3 py-2 bg-gray-100 hover:bg-gray-200 rounded cursor-pointer text-sm whitespace-nowrap">
                            上传
                            <input type="file" class="hidden" accept="image/*" onchange="uploadLogo(this)">
                        </label>
                    </div>
                    <?php if (!empty($editBrand['logo'])): ?>
                    <img src="<?php echo e($editBrand['logo']); ?>" class="h-12 mt-2 rounded border">
                    <?php endif; ?>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">官网</label>
                    <input type="text" name="url" value="<?php echo e($editBrand['url'] ?? ''); ?>" class="w-full border rounded px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Slug</label>
                    <input type="text" name="slug" value="<?php echo e($editBrand['slug'] ?? ''); ?>" class="w-full border rounded px-3 py-2 text-sm" placeholder="留空自动生成">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo __('label_sort_order'); ?></label>
                        <input type="number" name="sort_order" value="<?php echo $editBrand['sort_order'] ?? 0; ?>" class="w-full border rounded px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo __('label_status'); ?></label>
                        <select name="status" class="w-full border rounded px-3 py-2 text-sm">
                            <option value="1" <?php echo ($editBrand['status'] ?? 1) == 1 ? 'selected' : ''; ?>><?php echo __('admin_enabled'); ?></option>
                            <option value="0" <?php echo ($editBrand['status'] ?? 1) == 0 ? 'selected' : ''; ?>><?php echo __('admin_disabled'); ?></option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">品牌介绍</label>
                    <textarea name="description" rows="3" class="w-full border rounded px-3 py-2 text-sm"><?php echo e($editBrand['description'] ?? ''); ?></textarea>
                </div>
                <button type="button" onclick="saveBrand()" class="w-full bg-primary hover:bg-secondary text-white py-2 rounded text-sm"><?php echo __('btn_save'); ?></button>
                <?php if ($editBrand): ?>
                <a href="/admin/product_brand.php" class="block text-center text-gray-500 text-sm hover:text-gray-700">取消编辑</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
</div>

<script>
async function saveBrand() {
    const fd = new FormData(document.getElementById('brandForm'));
    const r = await fetch('', { method: 'POST', body: fd });
    const d = await safeJson(r);
    if (d.code === 0) { showMessage('<?php echo __('admin_saved'); ?>'); setTimeout(() => location.href = '/admin/product_brand.php', 1000); }
    else showMessage(d.msg, 'error');
}
document.getElementById('checkAll').addEventListener('change', function() {
    document.querySelectorAll('input[name="ids[]"]').forEach(el => el.checked = this.checked);
});
async function batchDeleteBrands() {
    const checked = document.querySelectorAll('input[name="ids[]"]:checked');
    if (checked.length === 0) { showMessage('请先选择品牌', 'error'); return; }
    if (!confirm('确定删除选中的 ' + checked.length + ' 个品牌？关联产品的品牌将被清除。')) return;
    const fd = new FormData();
    fd.append('action', 'batch_delete');
    checked.forEach(el => fd.append('ids[]', el.value));
    const r = await fetch('', { method: 'POST', body: fd });
    const d = await safeJson(r);
    if (d.code === 0) { showMessage('<?php echo __('admin_deleted'); ?>'); setTimeout(() => location.reload(), 1000); }
}
async function uploadLogo(input) {
    if (!input.files[0]) return;
    const fd = new FormData();
    fd.append('file', input.files[0]);
    fd.append('type', 'images');
    const r = await fetch('/admin/upload.php', { method: 'POST', body: fd });
    const d = await safeJson(r);
    if (d.code === 0) {
        document.getElementById('brandLogo').value = d.data.url;
        showMessage('<?php echo __('admin_success'); ?>');
    } else showMessage(d.msg, 'error');
    input.value = '';
}
async function deleteBrand(id) {
    if (!confirm('删除品牌将清除产品的品牌关联，确定？')) return;
    const fd = new FormData(); fd.append('action', 'delete'); fd.append('id', id);
    const r = await fetch('', { method: 'POST', body: fd });
    const d = await safeJson(r);
    if (d.code === 0) { showMessage('已删除'); setTimeout(() => location.reload(), 1000); }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
